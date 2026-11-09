# OttBrandCleanup – Marken aufräumen (Shopware 6.4)

Console-Command `ott:brands:cleanup` für Shops mit dem CBAX-Plugin *Hersteller-Seiten* (`CbaxModulManufacturers`).

## Problem

CBAX erzeugt Marken-URLs (`/marken/<marke>/`) aus dem Herstellernamen. Gibt es zwei Hersteller mit gleichem URL-Slug
(z. B. „Team 7“ doppelt, „Molteni & C“ / „Molteni&C“), bekommt keiner oder der falsche (leere) Eintrag die saubere URL –
die Marke mit den Produkten ist dann nur über `/cbax/manufacturer/detail/<id>` erreichbar. Zusätzlich können Kategorien
den Pfad `/marken/<marke>/` blockieren und Produkte fremder Marken über inaktive Kategorien unter `/marken/<marke>/` landen.

## Was der Command macht

1. **Duplikate zusammenführen:** Hersteller mit gleichem CBAX-Slug werden gruppiert. Behalten wird der Eintrag mit den
   meisten Produkten (bei Gleichstand der mit vorhandener CBAX-URL, dann der älteste). Produkte der Duplikate werden
   umgehängt, die Duplikate gelöscht.
2. **Blockierte Marken-URLs freimachen:** Kategorien, die `/marken/<marke>/` belegen, bekommen ein Suffix
   (Standard `-serien`, z. B. `marken/rolf-benz-serien/`).
3. **Fremdprodukte (optional, `--detach-foreign`):** Produkte anderer Marken werden von *inaktiven* Kategorien gelöst,
   die sie unter `/marken/<marke>/` einsortieren.
4. **Link-Overrides entfernen:** Custom Field `cbaxManufacturerLink`, das auf die eigene Marken-URL zeigt.
5. **Neu aufbauen:** Produkt-SEO-URLs, CBAX-Marken-URLs und Produktzähler, danach Cache leeren.

Jeder Lauf mit `--apply` schreibt eine Undo-Info nach `var/log/brand-cleanup-<datum>.json`.

## Installation

Ordner nach `custom/plugins/OttBrandCleanup` kopieren (oder als ZIP im Admin hochladen), dann:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate OttBrandCleanup
```

## Sicher ausführen

```bash
# 1. Datenbank-Backup (z. B. mysqldump)

# 2. Analyse, ändert nichts
bin/console ott:brands:cleanup --report

# 3. Probelauf: zeigt jede geplante Aktion, ändert nichts
bin/console ott:brands:cleanup --detach-foreign

# 4a. Sicherer Modus: nur leere Duplikate löschen, nichts umhängen
bin/console ott:brands:cleanup --only-empty --apply

# 4b. Vollmodus: Produkte umhängen, Duplikate löschen, Fremdprodukte lösen
php -d memory_limit=2G bin/console ott:brands:cleanup --apply --detach-foreign

# 5. Gegenprüfung: sollte überall 0 zeigen
bin/console ott:brands:cleanup --report
```

## Optionen

| Option | Bedeutung |
|---|---|
| `--apply` | Änderungen wirklich ausführen (ohne: Dry-Run) |
| `--report`, `-r` | Nur Kurzstatus, ändert nichts |
| `--manufacturer`, `-m` | Nur diese Marke bearbeiten, z. B. `-m "Rolf Benz"` |
| `--only-empty` | Nur Duplikate ohne Produkte löschen, keine Fremdprodukt-Bereinigung |
| `--detach-foreign` | Fremdprodukte von inaktiven Kategorien unter `/marken/<marke>/` lösen |
| `--category-suffix` | Suffix für blockierende Kategorie-URLs (Standard `-serien`) |
| `--skip-cbax` | CBAX-SEO-URL-Erzeugung und Produktzähler überspringen |

## Hinweise

- Produkte an *aktiven* Kategorien unter einer fremden Marken-URL werden bewusst nicht angefasst (im Report als
  „Fremdprodukte“ gezählt) und müssen von Hand gelöst werden.
- SEO-URLs werden nur für Verkaufskanäle mit Domain erzeugt; alte Einträge in Headless-Kanälen bleiben unverändert.
- Importe, die Hersteller per exaktem Namen zuordnen, finden gelöschte Schreibweisen (z. B. „Molteni&C“) danach nicht mehr.

## Voraussetzungen

Shopware 6.4, optional `CbaxModulManufacturers` (ohne CBAX laufen nur Schritte 1–4).
