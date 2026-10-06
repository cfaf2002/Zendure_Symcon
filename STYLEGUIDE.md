# Hausstil für die IP-Symcon-Module von Armin Frohwerk

Gilt für alle Module unter [github.com/cfaf2002](https://github.com/cfaf2002). Vorlage ist die Markisensteuerung bzw. der Pegelstand. Diese Datei liegt in jedem Repository und ist überall gleich; geändert wird sie nur zusammen in allen Repositorys.

## 1. Repository

| Datei / Ordner | Pflicht | Inhalt |
| :-- | :-: | :-- |
| `README.md` | ja | Aufbau siehe Abschnitt 2 |
| `LICENSE` | ja | MIT, „Copyright (c) 2026 Armin Frohwerk“ |
| `library.json` | ja | `author` „Armin Frohwerk“, `url` auf das Repository, `version` + `build` + `date` bei jeder Änderung hochzählen |
| `STYLEGUIDE.md` | ja | diese Datei |
| `.github/workflows/tests.yml` | ja | in allen Repositorys gleich: PHP 8.3 und 8.5, Syntax, JSON, Strukturprüfung, eigene Tests |
| `tests/structure.php` | ja | Strukturprüfung (Abschnitt 6), in allen Repositorys gleich |
| `tests/stubs.php` | ja | Ladetest mit den offiziellen Symcon-Stubs; ohne eigenen Ladetest die gemeinsame Fassung |
| `tests/run.php`, `phpunit.xml` | empfohlen | eigene Funktionstests; der Workflow führt sie aus, wenn vorhanden |
| `libs/` | bei Bedarf | gemeinsamer Code mehrerer Module (Traits, Clients) |
| `<Modul>/tile.html` | bei Kachel | Kachel des Moduls, Dateiname immer `tile.html` |
| `<Modul>/locale.json` | empfohlen | Übersetzungen; fehlt sie, ist das Modul nur deutsch |

## 2. README

Titel immer `# <Modulname> für IP-Symcon`. Direkt darunter der Badge-Block in **dieser Reihenfolge und diesen Farben** (Unterstriche statt Leerzeichen, `.svg`):

| # | Badge | Farbe |
| -: | :-- | :-- |
| 1 | `IP--Symcon-ab_<Mindestversion>` | `0b6fb3` |
| 2 | `optimiert_für-Symcon_9.0` | `0b6fb3` |
| 3 | `Modul--Version-<Version>_(Build_<Build>)` | `informational` |
| 4 | Tests (GitHub-Actions-Badge) | – |
| 5 | `PHP-8.3_\|_8.5` mit PHP-Logo | `777bb4` |
| 6 | `SDK-IPSModuleStrict` | `success` |
| 7 | `Variablen-Darstellungen` | `success` |
| 8 | `Kachel--Visualisierung-HTML--SDK` (nur mit Kachel) | `orange` |
| 9 | `Farbschema-Symcon--Design_\|_Dunkel_\|_Hell` (nur mit Kachel) | `blueviolet` |
| 10 | `Sprache-Deutsch` bzw. `Sprachen-Deutsch_\|_Englisch` | `blueviolet` |
| 11 | `Lizenz-MIT` | `green` |
| 12+ | modulspezifisch (Datenquelle, Cloud, Funktionen …) | frei, Datenquellen `lightgrey` |

Danach: ein bis zwei Sätze, was das Modul tut, und – bei inoffiziellen Schnittstellen – der Hinweis „kein offizielles Produkt …“ als Zitatblock. Am Ende stehen **Changelog** (als Tabelle *Version · Build · Datum · Beschreibung*, neueste oben, oder als Verweis auf `CHANGELOG.md`) und **Lizenz**.

Empfohlene Gliederung für neue Module: Inhalt · Funktionsumfang · Voraussetzungen und Technik · Installation · Einrichtung · Kachel · Variablen und Darstellungen · PHP-Befehle · Sicherheit und Geschwindigkeit · Entwicklung und Tests · Changelog · Lizenz.

## 3. PHP

- Basisklasse `IPSModuleStrict` mit vollständigen Typangaben.
- Variablen mit **Darstellungen** (`PRESENTATION`), keine eigenen Profile. Alte Profile des Moduls beim Aktualisieren aufräumen, sobald keine Variable sie mehr nutzt.
- Präfix in Großbuchstaben, eines pro Modul oder pro Bibliothek (`EASEE`, `MARKISE`, `EINK` …).
- Dateikopf: `declare(strict_types=1);` und `SPDX-License-Identifier: MIT`.
- Zugangsdaten nie ins Debug oder Meldungsfenster; HTTPS mit Zeitlimit; Werte für die Kachel als JSON mit `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`.
- Variablen nur schreiben, wenn sich der Wert ändert.

## 4. Kachel

### 4.1 Grundlage

Jede `tile.html` beginnt mit dem Kopfkommentar (Modul, Copyright, SPDX, kurz Aufbau/Sicherheit) und enthält am Anfang des `<style>` die **Kachel-Grundlage** unverändert. Modulspezifisch ist dort nur `--brand`.

| Token | Bedeutung |
| :-- | :-- |
| `--font` | Systemschrift (die Schrift der Visualisierung kommt im Kachel-Rahmen nicht an) |
| `--fg` | Schriftfarbe; Symcon-Design: `--content-color` |
| `--accent` / `--on-accent` | Akzent und Schrift darauf; Symcon-Design: `--accent-color`, sonst `--brand` |
| `--muted` / `--faint` | Nebentext (65 %) / sehr leise (40 %) |
| `--line` | Linien und Rahmen (15 %) |
| `--surface` / `--surface-hover` | Flächen auf der Kachel (7 % / 12 %) |
| `--popup` | Hintergrund von Menüs und Dialogen |
| `--ok` `--warn` `--bad` `--info` `--off` | Zustände: grün, gelb, rot, blau, grau |
| `--radius-l` `--radius` `--radius-s` `--pill` | 14 / 12 / 8 px / Kapsel |
| `--gap` | Grundabstand 8 px |

Eigene Variablen eines Moduls (z. B. für ein Diagramm) sind erlaubt, werden aber aus diesen Tokens abgeleitet statt mit festen Farben.

### 4.2 Farbschema

- Eigenschaft `TileTheme` (Integer), Auswahl „Farbschema der Kachel“: **0 = Symcon-Design (Farben der Visualisierung)**, 1 = Dunkel, 2 = Hell. Modulspezifische Schemas (z. B. „Natur“) folgen danach.
- Der Wert geht als `theme` in die Kacheldaten; die Kachel ruft `applyTheme(d.theme)` auf. Das setzt `theme-dark` bzw. `theme-light` am `html`-Element.
- Im Symcon-Design hat die Kachel keinen eigenen Hintergrund – außer der Nutzer wählt ein Bild oder eine Szene (z. B. Aurora bei Zendure, Natur beim Pegelstand). Auf Bildern und Szenen ist die Schrift immer hell.
- Ältere Module mit anderer Zählung (Pegelstand: 3 = Symcon-Design) behalten ihre gespeicherten Werte und rechnen sie für `applyTheme` um; in der Auswahl steht trotzdem Symcon-Design, Dunkel, Hell vorn.

### 4.3 Verhalten

- Texte nur per `textContent`, nie Daten als HTML.
- Keine externen Dateien und Schriften (erlaubt sind die Symbole der Visualisierung); Bilder als Data-URI oder Medienobjekt.
- Animationen respektieren `prefers-reduced-motion` und ruhen, wenn die Kachel nicht sichtbar ist.
- Bedienelemente mindestens 36 px hoch, sichtbarer Fokusrahmen.

## 5. Formular

- Gruppen als `ExpansionPanel`, die wichtigste Gruppe offen.
- „Farbschema der Kachel“ steht bei den Kachel-Einstellungen, direkt nach dem Schalter für die eigene Kachel (falls es einen gibt).
- Formulare sind deutsch oder englisch mit `locale.json`; neue Texte bekommen in beiden Fällen ihre Übersetzung.

## 6. Tests

`tests/structure.php` prüft: JSON-Dateien gültig, GUIDs eindeutig und im richtigen Format, Präfix vorhanden, jede Eigenschaft im Formular ist im Modul registriert, die Kachel-Datei existiert und enthält die Kachel-Grundlage. `tests/stubs.php` legt jede Instanz mit den Symcon-Stubs an, öffnet das Formular, erzeugt die Kachel und schaltet das Farbschema durch. Weitergehende Tests (Fixtures, nachgebaute Cloud) wie in Markisensteuerung, Pegelstand, Easee und Hoymiles sind willkommen.
