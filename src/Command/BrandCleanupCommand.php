<?php declare(strict_types=1);

namespace Ott\BrandCleanup\Command;

use Ott\BrandCleanup\Service\BrandCleanupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class BrandCleanupCommand extends Command
{
    protected static $defaultName = 'ott:brands:cleanup';

    private BrandCleanupService $service;

    public function __construct(BrandCleanupService $service)
    {
        parent::__construct();
        $this->service = $service;
    }

    protected function configure(): void
    {
        $this->setDescription('Marken aufräumen: Duplikate mergen, saubere /marken/<name>/ URLs freimachen, SEO-URLs + Zähler neu bauen')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Änderungen wirklich ausführen (ohne diese Option nur Dry-Run)')
            ->addOption('manufacturer', 'm', InputOption::VALUE_REQUIRED, 'Nur diese Marke bearbeiten, z.B. "Rolf Benz"')
            ->addOption('category-suffix', null, InputOption::VALUE_REQUIRED, 'Suffix für blockierende Kategorie-URLs', '-serien')
            ->addOption('detach-foreign', null, InputOption::VALUE_NONE, 'Fremd-Produkte (andere Marke) von inaktiven Kategorien lösen, die sie unter /marken/<marke>/ einsortieren')
            ->addOption('report', 'r', InputOption::VALUE_NONE, 'Nur kurzer Status in der Konsole: was müsste gefixt werden (ändert nichts)')
            ->addOption('only-empty', null, InputOption::VALUE_NONE, 'Sicherer Modus: nur Duplikate OHNE Produkte löschen, nichts umhängen, keine Fremdprodukt-Bereinigung')
            ->addOption('skip-cbax', null, InputOption::VALUE_NONE, 'CBAX SEO-URL-Erzeugung und Produktzähler nicht ausführen');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $apply = (bool) $input->getOption('apply');
        $detach = (bool) $input->getOption('detach-foreign');

        $plan = $this->service->plan($input->getOption('manufacturer'), (string) $input->getOption('category-suffix'));
        if ($input->getOption('report')) {
            return $this->report($io, $plan);
        }

        if ($input->getOption('only-empty')) {
            $plan = $this->service->restrictToEmptyDuplicates($plan);
            $detach = false;
        }
        $io->title(($apply ? 'APPLY' : 'DRY-RUN') . ' – Marken aufräumen (' . $plan['brands'] . ' Marken geprüft)');

        $io->section('1. Doppelte Hersteller (gleicher URL-Slug)');
        if ($plan['groups'] === []) {
            $io->writeln('keine');
        }
        $rows = [];
        foreach ($plan['groups'] as $slug => $g) {
            $rows[] = [$slug, 'BEHALTEN', $g['master']['name'], $g['master']['id'], $g['master']['total'] . ' / ' . $g['master']['active'], $g['master']['hasUrl'] ? 'ja' : 'nein'];
            foreach ($g['duplicates'] as $d) {
                $rows[] = ['', 'mergen+löschen', $d['name'], $d['id'], $d['total'] . ' / ' . $d['active'], $d['hasUrl'] ? 'ja' : 'nein'];
            }
        }
        $io->table(['Slug', 'Aktion', 'Name', 'ID', 'Produkte ges./aktiv', 'CBAX-URL'], $rows);

        if (!empty($plan['manual'])) {
            $io->warning('Von Hand klären (Duplikat hat Produkte, bleibt unangetastet):');
            $rows = [];
            foreach ($plan['manual'] as $slug => $g) {
                foreach ($g['duplicates'] as $d) {
                    $rows[] = [$slug, $g['master']['name'] . ' (' . $g['master']['total'] . ')', $d['name'] . ' ' . $d['id'] . ' (' . $d['total'] . ')'];
                }
            }
            $io->table(['Slug', 'Master (Produkte)', 'Duplikat mit Produkten'], $rows);
        }

        $io->section('2. Blockierte Marken-URLs');
        if ($plan['blockers'] === []) {
            $io->writeln('keine');
        }
        $io->table(
            ['Pfad', 'blockiert durch', 'Aktion', 'neuer Pfad'],
            array_map(static fn ($b) => [$b['path'], $b['route'] . ' ' . $b['label'], $b['action'], $b['action'] === 'rename' ? $b['newPath'] : '-'], $plan['blockers'])
        );
        if (array_filter($plan['blockers'], static fn ($b) => $b['action'] !== 'rename')) {
            $io->warning('Einträge mit Aktion "manual"/"conflict" werden NICHT angefasst und müssen von Hand gelöst werden.');
        }

        $io->section('3. Fremd-Produkte unter /marken/<marke>/');
        if ($plan['foreign'] === []) {
            $io->writeln('keine');
        }
        $io->table(
            ['Marke (Slug)', 'Produkte mit falscher URL', 'Kategorie-Zuordnungen lösbar (inaktive Kat.)'],
            array_map(static fn ($slug, $f) => [$slug, \count($f['productIds']), \count($f['detach'])], array_keys($plan['foreign']), $plan['foreign'])
        );
        if ($plan['foreign'] !== [] && !$detach) {
            $io->note('Ohne --detach-foreign werden diese Produkte nur neu indiziert; die URL bleibt, solange die inaktive Kategorie zugeordnet ist.');
        }

        $io->section('4. Link-Override auf eigene Marken-URL (Custom Field cbaxManufacturerLink)');
        $io->table(['Marke', 'Link (wird entfernt)'], array_map(static fn ($l) => [$l['name'], $l['link']], $plan['ownLinks']));

        if (!$apply) {
            $io->success('Dry-Run beendet, nichts geändert. Mit --apply ausführen.');

            return Command::SUCCESS;
        }

        $io->section('Ausführen');
        $result = $this->service->apply($plan, $detach, (bool) $input->getOption('skip-cbax'), static fn (string $m) => $io->writeln($m));

        if (\is_array($result['cbax'])) {
            $io->writeln('CBAX: ' . ($result['cbax']['message'] ?? ''));
            if (!empty($result['cbax']['errors'])) {
                $io->warning(\count($result['cbax']['errors']) . ' CBAX-Fehler:');
                $io->table(['Pfad', 'Fehler'], array_map(static fn ($e) => [$e['seoPathInfo'] ?? '', $e['error'] ?? ''], $result['cbax']['errors']));
            }
        }
        $io->success(sprintf('%d Duplikate gemergt, %d Produkte umgehängt. Backup/Undo-Info: %s', $result['merged'], $result['moved'], $result['backup']));

        return Command::SUCCESS;
    }

    private function report(SymfonyStyle $io, array $plan): int
    {
        $safe = $this->service->restrictToEmptyDuplicates($plan);
        $emptyDups = array_sum(array_map(static fn ($g) => \count($g['duplicates']), $safe['groups']));
        $manual = $safe['manual'];
        $renames = array_filter($plan['blockers'], static fn ($b) => $b['action'] === 'rename');
        $foreignProducts = array_sum(array_map(static fn ($f) => \count($f['productIds']), $plan['foreign']));

        $io->title('Marken-Status (nur Analyse, nichts geändert)');
        $io->definitionList(
            ['Marken geprüft' => $plan['brands']],
            ['Gruppen mit Duplikaten' => \count($plan['groups'])],
            ['  davon leere Duplikate (löschbar mit --only-empty)' => $emptyDups],
            ['  davon Duplikate MIT Produkten (von Hand/Vollmodus)' => \count($manual)],
            ['Marken ohne saubere URL' => \count($plan['noUrl'])],
            ['URLs blockiert durch Kategorie' => \count($renames)],
            ['Fremdprodukte unter /marken/<andere Marke>/' => $foreignProducts],
            ['Link-Overrides auf eigene Marken-URL' => \count($plan['ownLinks'])],
        );

        if ($plan['noUrl']) {
            $io->section('Ohne saubere URL');
            $io->listing(array_map(static fn ($n) => sprintf('%s (%d aktive Produkte) – %s', $n['name'], $n['active'], $n['urlOnDuplicate'] ? 'URL hängt am Duplikat' : 'keine URL'), $plan['noUrl']));
        }
        if ($manual) {
            $io->section('Duplikat mit Produkten');
            $io->listing(array_map(static fn ($slug, $g) => sprintf('%s: %s', $g['master']['name'], implode(', ', array_map(static fn ($d) => $d['total'] . ' Produkte in ' . $d['id'], $g['duplicates']))), array_keys($manual), $manual));
        }
        foreach ($renames as $b) {
            $io->writeln(sprintf('Kategorie %s blockiert %s -> %s', $b['label'], $b['path'], $b['newPath']));
        }
        $io->writeln('');
        $io->writeln('Ausführen: --only-empty --apply (sicher) oder --apply [--detach-foreign] (voll).');

        return Command::SUCCESS;
    }
}
