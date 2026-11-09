<?php declare(strict_types=1);

namespace Ott\BrandCleanup\Service;

use Cbax\ModulManufacturers\Components\ManufacturerSeo;
use Cbax\ModulManufacturers\Components\ManufacturersHelper;
use Cocur\Slugify\Slugify;
use Doctrine\DBAL\Connection;
use Shopware\Core\Content\Seo\SeoUrlUpdater;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Adapter\Cache\CacheClearer;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Plans (always) and applies (only with $apply) a brand cleanup:
 *  1. merge manufacturers with the same CBAX slug (products -> master, duplicates deleted)
 *  2. free the brand URL path from categories (category SEO URL gets a suffix, is_modified=1)
 *  3. optional: detach foreign products from inactive categories that put them under /marken/<other-brand>/
 *  4. rebuild product SEO URLs, CBAX manufacturer SEO URLs and product counts, clear cache
 *
 * The slug logic mirrors Cbax ManufacturerSeo::createSeoUrls() + sCleanupPath() on purpose:
 * two manufacturers collide for CBAX exactly when strtolower(slugify(name)) is equal.
 */
class BrandCleanupService
{
    private const DETAIL_ROUTE = 'frontend.cbax.manufacturer.detail';
    private const INDEX_ROUTE = 'frontend.cbax.manufacturer.index';
    private const CATEGORY_ROUTE = 'frontend.navigation.page';
    private const PRODUCT_ROUTE = 'frontend.detail.page';
    private const CBAX_DOMAIN = 'CbaxModulManufacturers.config.';

    private Connection $connection;
    private EntityRepositoryInterface $productRepository;
    private EntityRepositoryInterface $manufacturerRepository;
    private EntityRepositoryInterface $seoUrlRepository;
    private SeoUrlUpdater $seoUrlUpdater;
    private SystemConfigService $config;
    private Slugify $slugify;
    private ?ManufacturerSeo $cbaxSeo;
    private ?ManufacturersHelper $cbaxHelper;
    private CacheClearer $cacheClearer;
    private string $projectDir;

    public function __construct(
        Connection $connection,
        EntityRepositoryInterface $productRepository,
        EntityRepositoryInterface $manufacturerRepository,
        EntityRepositoryInterface $seoUrlRepository,
        SeoUrlUpdater $seoUrlUpdater,
        SystemConfigService $config,
        Slugify $slugify,
        ?ManufacturerSeo $cbaxSeo,
        ?ManufacturersHelper $cbaxHelper,
        CacheClearer $cacheClearer,
        string $projectDir
    ) {
        $this->connection = $connection;
        $this->productRepository = $productRepository;
        $this->manufacturerRepository = $manufacturerRepository;
        $this->seoUrlRepository = $seoUrlRepository;
        $this->seoUrlUpdater = $seoUrlUpdater;
        $this->config = $config;
        $this->slugify = $slugify;
        $this->cbaxSeo = $cbaxSeo;
        $this->cbaxHelper = $cbaxHelper;
        $this->cacheClearer = $cacheClearer;
        $this->projectDir = $projectDir;
    }

    public function slug(string $name): string
    {
        $parts = array_map(fn (string $p) => $this->slugify->slugify($p), explode('/', $name));

        return strtolower(strtr(implode('/', $parts), ['-.' => '.']));
    }

    /**
     * @return array{groups: array, blockers: array, foreign: array, ownLinks: array, brands: int}
     */
    public function plan(?string $only, string $categorySuffix): array
    {
        $onlySlug = $only !== null && $only !== '' ? $this->slug($only) : null;

        $bySlug = [];
        foreach ($this->loadManufacturers() as $m) {
            if ($m['name'] === null || trim($m['name']) === '') {
                continue;
            }
            $slug = $this->slug($m['name']);
            if ($slug === '' || ($onlySlug !== null && $slug !== $onlySlug)) {
                continue;
            }
            $bySlug[$slug][] = $m;
        }

        $groups = [];
        foreach ($bySlug as $slug => $list) {
            // master: most products, then has a canonical CBAX url, then oldest
            usort($list, static fn ($a, $b) => [$b['total'], $b['hasUrl'], $a['createdAt']] <=> [$a['total'], $a['hasUrl'], $b['createdAt']]);
            $groups[$slug] = ['master' => array_shift($list), 'duplicates' => $list];
        }

        return [
            'brands' => \count($groups),
            'groups' => array_filter($groups, static fn ($g) => $g['duplicates'] !== []),
            'blockers' => $this->findBlockers($groups, $categorySuffix),
            'foreign' => $this->findForeignProducts($groups),
            'ownLinks' => $this->findOwnBrandLinks($groups),
            'noUrl' => $this->findBrandsWithoutUrl($groups),
        ];
    }

    /**
     * Safe mode: only duplicates WITHOUT products are deleted. Groups with a duplicate that still has products are
     * left untouched and returned under 'manual'. Foreign-product handling is switched off.
     */
    public function restrictToEmptyDuplicates(array $plan): array
    {
        $plan['manual'] = [];
        foreach ($plan['groups'] as $slug => $g) {
            $empty = array_values(array_filter($g['duplicates'], static fn ($d) => $d['total'] === 0));
            $withProducts = array_values(array_filter($g['duplicates'], static fn ($d) => $d['total'] > 0));
            if ($withProducts !== []) {
                $plan['manual'][$slug] = ['master' => $g['master'], 'duplicates' => $withProducts];
            }
            if ($empty === []) {
                unset($plan['groups'][$slug]);
            } else {
                $plan['groups'][$slug]['duplicates'] = $empty;
            }
        }
        $plan['foreign'] = [];

        return $plan;
    }

    /**
     * @param array{groups: array, blockers: array, foreign: array} $plan
     *
     * @return array<string, mixed> result summary
     */
    public function apply(array $plan, bool $detachForeign, bool $skipCbax, ?callable $log = null): array
    {
        $log ??= static function (string $m): void {};
        $context = Context::createDefaultContext();
        $backup = ['createdAt' => date('c'), 'moved' => [], 'deleted' => [], 'renamedSeo' => [], 'detached' => [], 'removedLinks' => []];
        $productIdsForSeo = [];

        // 1. merge duplicates
        foreach ($plan['groups'] as $slug => $g) {
            $masterId = $g['master']['id'];
            foreach ($g['duplicates'] as $dup) {
                $ids = $this->connection->fetchFirstColumn(
                    'SELECT LOWER(HEX(id)) FROM product WHERE product_manufacturer_id = :m AND version_id = :v',
                    ['m' => Uuid::fromHexToBytes($dup['id']), 'v' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
                );
                foreach (array_chunk($ids, 100) as $chunk) {
                    $this->productRepository->update(
                        array_map(static fn ($id) => ['id' => $id, 'manufacturerId' => $masterId], $chunk),
                        $context
                    );
                }
                foreach ($ids as $id) {
                    $backup['moved'][] = ['product' => $id, 'from' => $dup['id'], 'to' => $masterId];
                }
                $productIdsForSeo = array_merge($productIdsForSeo, $ids);

                // CBAX would see the stale canonical url of the duplicate as "blocking" -> remove first
                $this->connection->executeStatement(
                    'DELETE FROM seo_url WHERE route_name = :r AND foreign_key = :fk',
                    ['r' => self::DETAIL_ROUTE, 'fk' => Uuid::fromHexToBytes($dup['id'])]
                );
                $this->manufacturerRepository->delete([['id' => $dup['id']]], $context);
                $backup['deleted'][] = ['id' => $dup['id'], 'name' => $dup['name'], 'master' => $masterId];
                $log(sprintf('  merged "%s" (%s, %d Produkte) -> %s', $dup['name'], $dup['id'], \count($ids), $masterId));
            }
        }

        // 2. free brand paths from categories
        foreach ($plan['blockers'] as $b) {
            if ($b['action'] !== 'rename') {
                continue;
            }
            $this->seoUrlRepository->update([[
                'id' => $b['seoUrlId'],
                'seoPathInfo' => $b['newPath'],
                'isModified' => true,
            ]], $context);
            $backup['renamedSeo'][] = ['seoUrlId' => $b['seoUrlId'], 'old' => $b['path'], 'new' => $b['newPath']];
            $log(sprintf('  category "%s": %s -> %s', $b['label'], $b['path'], $b['newPath']));
        }

        // 3. foreign products under brand paths
        $foreignIds = [];
        foreach ($plan['foreign'] as $f) {
            $foreignIds = array_merge($foreignIds, $f['productIds']);
            if ($detachForeign) {
                foreach ($f['detach'] as [$pid, $cid]) {
                    $this->connection->executeStatement(
                        'DELETE FROM product_category WHERE product_id = :p AND category_id = :c AND product_version_id = :v',
                        ['p' => Uuid::fromHexToBytes($pid), 'c' => Uuid::fromHexToBytes($cid), 'v' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
                    );
                    $this->connection->executeStatement(
                        'DELETE FROM product_category_tree WHERE product_id = :p AND category_id = :c AND product_version_id = :v',
                        ['p' => Uuid::fromHexToBytes($pid), 'c' => Uuid::fromHexToBytes($cid), 'v' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
                    );
                    $backup['detached'][] = ['product' => $pid, 'category' => $cid];
                }
            }
        }
        $productIdsForSeo = array_values(array_unique(array_merge($productIdsForSeo, $detachForeign ? $foreignIds : [])));

        // 3b. manual links "<domain>/marken/<same brand>" in the CBAX custom field would bypass the clean SEO URL
        foreach ($plan['ownLinks'] as $l) {
            $this->manufacturerRepository->update([['id' => $l['id'], 'customFields' => ['cbaxManufacturerLink' => null]]], $context);
            $backup['removedLinks'][] = $l;
            $log(sprintf('  Link-Override entfernt: %s (%s)', $l['name'], $l['link']));
        }

        // 4. rebuild
        foreach (array_chunk($productIdsForSeo, 200) as $chunk) {
            $this->seoUrlUpdater->update(self::PRODUCT_ROUTE, $chunk);
        }
        $log(sprintf('  %d Produkt-SEO-URLs neu erzeugt', \count($productIdsForSeo)));

        $cbax = null;
        if (!$skipCbax && $this->cbaxSeo !== null) {
            $cbax = $this->cbaxSeo->generateSeoUrls($context, 'de-DE');
            if ($this->cbaxHelper !== null) {
                $this->cbaxHelper->runProductCount($context);
            }
        }
        $this->cacheClearer->clear();

        $file = sprintf('%s/var/log/brand-cleanup-%s.json', $this->projectDir, date('Ymd-His'));
        file_put_contents($file, json_encode($backup, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE));

        return ['backup' => $file, 'cbax' => $cbax, 'merged' => \count($backup['deleted']), 'moved' => \count($backup['moved'])];
    }

    /** @return list<array<string, mixed>> */
    private function loadManufacturers(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(m.id)) AS id, m.created_at AS createdAt,
                    COALESCE(t.name, (SELECT t2.name FROM product_manufacturer_translation t2
                        WHERE t2.product_manufacturer_id = m.id AND t2.product_manufacturer_version_id = m.version_id AND t2.name IS NOT NULL LIMIT 1)) AS name
             FROM product_manufacturer m
             LEFT JOIN product_manufacturer_translation t ON t.product_manufacturer_id = m.id
                  AND t.product_manufacturer_version_id = m.version_id AND t.language_id = :lang
             WHERE m.version_id = :v',
            ['lang' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM), 'v' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        );

        $counts = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(product_manufacturer_id)) AS id, COUNT(*) AS total, SUM(active = 1) AS active
             FROM product WHERE version_id = :v AND product_manufacturer_id IS NOT NULL GROUP BY product_manufacturer_id',
            ['v' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
        ) as $c) {
            $counts[$c['id']] = $c;
        }

        $withUrl = array_flip($this->connection->fetchFirstColumn(
            'SELECT DISTINCT LOWER(HEX(foreign_key)) FROM seo_url WHERE route_name = :r AND is_canonical = 1 AND is_deleted = 0',
            ['r' => self::DETAIL_ROUTE]
        ));

        foreach ($rows as &$r) {
            $r['total'] = (int) ($counts[$r['id']]['total'] ?? 0);
            $r['active'] = (int) ($counts[$r['id']]['active'] ?? 0);
            $r['hasUrl'] = isset($withUrl[$r['id']]) ? 1 : 0;
        }

        return $rows;
    }

    /**
     * Storefront sales channel + language pairs that have the CBAX index page, with the path scheme CBAX uses.
     *
     * @return list<array{sc: string, lang: string, prefix: string, trailing: string}>
     */
    private function targets(): array
    {
        $targets = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT LOWER(HEX(sales_channel_id)) AS sc, LOWER(HEX(language_id)) AS lang, seo_path_info AS path
             FROM seo_url WHERE route_name = :r AND is_canonical = 1 AND is_deleted = 0',
            ['r' => self::INDEX_ROUTE]
        ) as $row) {
            $useBase = !empty($this->config->get(self::CBAX_DOMAIN . 'basisPathForDetail', $row['sc']));
            $targets[] = [
                'sc' => $row['sc'],
                'lang' => $row['lang'],
                'prefix' => $useBase ? trim($row['path'], '/') . '/' : '',
                'trailing' => !empty($this->config->get(self::CBAX_DOMAIN . 'trailingSlash', $row['sc'])) ? '/' : '',
            ];
        }

        return $targets;
    }

    private function findBlockers(array $groups, string $suffix): array
    {
        $blockers = [];
        foreach ($this->targets() as $t) {
            foreach ($groups as $slug => $g) {
                $path = $t['prefix'] . $slug . $t['trailing'];
                $rows = $this->connection->fetchAllAssociative(
                    'SELECT LOWER(HEX(id)) AS id, route_name AS route, LOWER(HEX(foreign_key)) AS fk
                     FROM seo_url WHERE seo_path_info = :p AND sales_channel_id = :sc AND language_id = :l
                       AND is_deleted = 0 AND is_canonical = 1 AND route_name <> :d',
                    ['p' => $path, 'sc' => Uuid::fromHexToBytes($t['sc']), 'l' => Uuid::fromHexToBytes($t['lang']), 'd' => self::DETAIL_ROUTE]
                );
                foreach ($rows as $r) {
                    $isCategory = $r['route'] === self::CATEGORY_ROUTE;
                    $newPath = rtrim($path, '/') . $suffix . $t['trailing'];
                    $taken = $isCategory && (bool) $this->connection->fetchOne(
                        'SELECT 1 FROM seo_url WHERE seo_path_info = :p AND sales_channel_id = :sc AND language_id = :l LIMIT 1',
                        ['p' => $newPath, 'sc' => Uuid::fromHexToBytes($t['sc']), 'l' => Uuid::fromHexToBytes($t['lang'])]
                    );
                    $blockers[] = [
                        'slug' => $slug,
                        'path' => $path,
                        'newPath' => $newPath,
                        'seoUrlId' => $r['id'],
                        'route' => $r['route'],
                        'label' => $isCategory ? $this->categoryName($r['fk']) : $r['fk'],
                        'action' => $isCategory ? ($taken ? 'conflict' : 'rename') : 'manual',
                    ];
                }
            }
        }

        return $blockers;
    }

    /**
     * Custom field cbaxManufacturerLink pointing to the brand's own /<prefix><slug>/ path (any host) = old workaround.
     */
    private function findOwnBrandLinks(array $groups): array
    {
        $paths = [];
        foreach ($this->targets() as $t) {
            foreach ($groups as $slug => $_) {
                $paths[trim($t['prefix'] . $slug, '/')] = $slug;
            }
        }

        $result = [];
        foreach ($groups as $slug => $g) {
            $ids = [$g['master']['id']];
            foreach ($this->connection->fetchAllAssociative(
                "SELECT JSON_UNQUOTE(JSON_EXTRACT(custom_fields, '$.cbaxManufacturerLink')) AS link
                 FROM product_manufacturer_translation WHERE product_manufacturer_id = :id AND product_manufacturer_version_id = :v
                   AND JSON_EXTRACT(custom_fields, '$.cbaxManufacturerLink') IS NOT NULL",
                ['id' => Uuid::fromHexToBytes($ids[0]), 'v' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)]
            ) as $row) {
                $link = (string) $row['link'];
                $path = trim((string) parse_url($link, \PHP_URL_PATH), '/');
                if ($link !== '' && isset($paths[$path]) && $paths[$path] === $slug) {
                    $result[$ids[0]] = ['id' => $ids[0], 'name' => $g['master']['name'], 'link' => $link];
                }
            }
        }

        return array_values($result);
    }

    /**
     * Brands with active products whose manufacturer has no canonical CBAX url (=> only the cryptic /cbax/... link works).
     */
    private function findBrandsWithoutUrl(array $groups): array
    {
        $result = [];
        foreach ($groups as $slug => $g) {
            $m = $g['master'];
            if ($m['hasUrl'] || $m['active'] === 0) {
                continue;
            }
            $result[$slug] = [
                'name' => $m['name'],
                'id' => $m['id'],
                'active' => $m['active'],
                'urlOnDuplicate' => (bool) array_filter($g['duplicates'], static fn ($d) => $d['hasUrl']),
            ];
        }

        return $result;
    }

    private function categoryName(string $id): string
    {
        $name = $this->connection->fetchOne(
            'SELECT name FROM category_translation WHERE category_id = :c AND name IS NOT NULL LIMIT 1',
            ['c' => Uuid::fromHexToBytes($id)]
        );

        return ($name ?: '?') . ' (' . $id . ')';
    }

    /**
     * Products whose canonical URL is below /<prefix><brand-slug>/ although they belong to another manufacturer.
     */
    private function findForeignProducts(array $groups): array
    {
        $manufacturerIdsBySlug = [];
        foreach ($groups as $slug => $g) {
            $manufacturerIdsBySlug[$slug] = array_merge([$g['master']['id']], array_column($g['duplicates'], 'id'));
        }

        $result = [];
        foreach ($this->targets() as $t) {
            foreach ($groups as $slug => $_) {
                $rows = $this->connection->fetchAllAssociative(
                    'SELECT LOWER(HEX(s.foreign_key)) AS pid,
                            COALESCE(LOWER(HEX(p.product_manufacturer_id)), LOWER(HEX(pp.product_manufacturer_id))) AS mid
                     FROM seo_url s
                     JOIN product p ON p.id = s.foreign_key AND p.version_id = :v
                     LEFT JOIN product pp ON pp.id = p.parent_id AND pp.version_id = :v
                     WHERE s.route_name = :r AND s.is_canonical = 1 AND s.is_deleted = 0
                       AND s.sales_channel_id = :sc AND s.language_id = :l AND s.seo_path_info LIKE :like',
                    [
                        'v' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION),
                        'r' => self::PRODUCT_ROUTE,
                        'sc' => Uuid::fromHexToBytes($t['sc']),
                        'l' => Uuid::fromHexToBytes($t['lang']),
                        'like' => addcslashes($t['prefix'] . $slug, '%_') . '/%',
                    ]
                );
                foreach ($rows as $r) {
                    if (\in_array($r['mid'], $manufacturerIdsBySlug[$slug], true)) {
                        continue;
                    }
                    $result[$slug]['productIds'][$r['pid']] = $r['pid'];
                }
            }
        }

        foreach ($result as $slug => &$entry) {
            $entry['productIds'] = array_values($entry['productIds']);
            $entry['detach'] = [];
            // only INACTIVE categories are detached: they are the ones that leak a wrong breadcrumb into the URL
            foreach (array_chunk($entry['productIds'], 500) as $chunk) {
                $rows = $this->connection->fetchAllAssociative(
                    'SELECT LOWER(HEX(pc.product_id)) AS pid, LOWER(HEX(pc.category_id)) AS cid
                     FROM product_category pc JOIN category c ON c.id = pc.category_id AND c.version_id = pc.category_version_id
                     WHERE pc.product_id IN (:ids) AND pc.product_version_id = :v AND c.active = 0',
                    ['ids' => array_map([Uuid::class, 'fromHexToBytes'], $chunk), 'v' => Uuid::fromHexToBytes(Defaults::LIVE_VERSION)],
                    ['ids' => Connection::PARAM_STR_ARRAY]
                );
                foreach ($rows as $row) {
                    $entry['detach'][] = [$row['pid'], $row['cid']];
                }
            }
        }

        return $result;
    }
}
