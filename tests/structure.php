<?php

declare(strict_types=1);

/*
 * Strukturprüfung nach Hausstil (STYLEGUIDE.md, Abschnitt 6) – in allen Repositorys gleich.
 * Aufruf: php tests/structure.php
 * SPDX-License-Identifier: MIT
 */

$root = dirname(__DIR__);
$errors = [];
$checks = 0;

$fail = static function (string $msg) use (&$errors): void {
    $errors[] = $msg;
};
$json = static function (string $file) use ($fail, &$checks): ?array {
    $checks++;
    try {
        $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        $fail(basename(dirname($file)) . '/' . basename($file) . ': ungültiges JSON (' . $e->getMessage() . ')');
        return null;
    }
    return is_array($data) ? $data : null;
};
$guid = '/^\{[0-9A-F]{8}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{4}-[0-9A-F]{12}\}$/';

// Repository
foreach (['README.md', 'LICENSE', 'library.json', 'STYLEGUIDE.md'] as $file) {
    $checks++;
    if (!is_file("$root/$file")) {
        $fail("$file fehlt");
    }
}
$checks++;
if (is_file("$root/LICENSE") && stripos((string) file_get_contents("$root/LICENSE"), 'MIT License') === false) {
    $fail('LICENSE ist keine MIT-Lizenz');
}
$library = $json("$root/library.json") ?? [];
$checks++;
if (($library['author'] ?? '') !== 'Armin Frohwerk') {
    $fail('library.json: author fehlt oder falsch');
}
$checks++;
if (!preg_match($guid, (string) ($library['id'] ?? ''))) {
    $fail('library.json: id ist keine GUID');
}
$readme = is_file("$root/README.md") ? (string) file_get_contents("$root/README.md") : '';
$checks++;
if (!preg_match('/^# .+ für IP-Symcon\s*$/m', $readme)) {
    $fail('README: Titel nicht im Format „# <Name> für IP-Symcon“');
}
$checks++;
if (!str_contains($readme, 'badge/Lizenz-MIT')) {
    $fail('README: Lizenz-Badge fehlt');
}

// Module
$ids = [(string) ($library['id'] ?? '') => 'library.json'];
$modules = glob("$root/*/module.json") ?: [];
$checks++;
if ($modules === []) {
    $fail('kein Modul gefunden');
}
foreach ($modules as $moduleJson) {
    $dir = dirname($moduleJson);
    $name = basename($dir);
    $module = $json($moduleJson);
    if ($module === null) {
        continue;
    }
    foreach (['id', 'parentRequirements', 'childRequirements', 'implemented'] as $key) {
        foreach ((array) ($module[$key] ?? []) as $value) {
            $checks++;
            if (!preg_match($guid, (string) $value)) {
                $fail("$name/module.json: $key enthält keine gültige GUID");
            }
        }
    }
    $id = (string) ($module['id'] ?? '');
    $checks++;
    if (isset($ids[$id])) {
        $fail("$name/module.json: GUID doppelt (auch in {$ids[$id]})");
    }
    $ids[$id] = $name;
    $checks++;
    if (!preg_match('/^[A-Z][A-Z0-9]*$/', (string) ($module['prefix'] ?? ''))) {
        $fail("$name/module.json: Präfix fehlt oder nicht in Großbuchstaben");
    }

    $php = is_file("$dir/module.php") ? (string) file_get_contents("$dir/module.php") : '';
    // Eigenschaften aus den genutzten Traits/Klassen in libs/ mit einbeziehen
    $own = $php;
    foreach (glob("$root/libs/*.php") ?: [] as $lib) {
        if (preg_match('/\\b' . preg_quote(basename($lib, '.php'), '/') . '\\b/', $own)) {
            $php .= (string) file_get_contents($lib);
        }
    }
    foreach (glob("$dir/*.php") ?: [] as $extra) {
        if (basename($extra) !== 'module.php') {
            $php .= (string) file_get_contents($extra);
        }
    }
    $checks++;
    if (!preg_match('/extends\s+IPSModuleStrict\b/', $php)) {
        $fail("$name: Basisklasse ist nicht IPSModuleStrict");
    }
    preg_match_all('/RegisterProperty\w+\(\s*[\'"]([^\'"]+)[\'"]/', $php, $m);
    $properties = array_flip($m[1]);
    // Dynamisch registrierte Eigenschaften (z. B. 'Weekday' . $d oder Namen aus einer Tabelle)
    $dynamic = (bool) preg_match('/RegisterProperty\w+\(\s*(?![\'"][^\'"]+[\'"]\s*,)/', $php);
    $known = static function (string $field) use ($properties, $dynamic, $php): bool {
        if (isset($properties[$field])) {
            return true;
        }
        if (!$dynamic) {
            return false;
        }
        if (preg_match('/[\'"]' . preg_quote($field, '/') . '[\'"]/', $php)) {
            return true;
        }
        $base = rtrim($field, '0123456789');
        return $base !== $field && (bool) preg_match('/RegisterProperty\w+\(\s*[\'"]' . preg_quote($base, '/') . '[\'"]\s*\./', $php);
    };

    if (is_file("$dir/form.json")) {
        $form = $json("$dir/form.json") ?? [];
        $walk = static function (array $elements) use (&$walk, $known, $fail, $name, &$checks): void {
            foreach ($elements as $el) {
                if (!is_array($el)) {
                    continue;
                }
                $type = (string) ($el['type'] ?? '');
                $noProperty = ['Button', 'Label', 'Image', 'TestCenter', 'ExpansionPanel', 'RowLayout', 'ColumnLayout',
                    'PopupButton', 'PopupAlert', 'ProgressBar', 'OpenObjectButton', 'Configurator', 'Tree', 'Chart'];
                if (isset($el['name']) && !in_array($type, $noProperty, true) && ($el['save'] ?? true) !== false) {
                    $checks++;
                    if (!$known((string) $el['name'])) {
                        $fail("$name/form.json: Feld „{$el['name']}“ ist im Modul nicht als Eigenschaft registriert");
                    }
                }
                foreach (['items', 'popup'] as $sub) {
                    if (isset($el[$sub]) && is_array($el[$sub])) {
                        $walk(isset($el[$sub]['items']) ? $el[$sub]['items'] : $el[$sub]);
                    }
                }
            }
        };
        $walk($form['elements'] ?? []);
    }

    // Datenfluss zu Symcon-I/O-Instanzen (Socket, Serial Port, UDP, MQTT): bei IPSModuleStrict HEX-kodiert
    $ioTx = ['{79827379-F36E-4ADA-8A95-5F8D1DC92FA9}', '{C8792760-65CF-4C53-B5C7-A30FCC84FEFE}',
        '{8E4D9B23-E0F2-1E05-41D8-C21EA53B8706}', '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}'];
    $ioRx = ['{018EF6B5-AB94-40C6-AA53-46943E824ACF}', '{7A1272A4-CBDB-46EF-BFC6-DCF4A53D2FC7}',
        '{9082C662-7864-D5CE-863F-53999200D897}', '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}'];
    $checks++;
    if (preg_match('/\butf8_(en|de)code\s*\(/', $php)) {
        $fail("$name: utf8_encode/utf8_decode verwendet – Datenfluss bei IPSModuleStrict ist HEX (bin2hex/hex2bin)");
    }
    // Nur Module, die Buffer/Payload selbst lesen oder schreiben (reine Durchreicher und Konfiguratoren nicht)
    $ownsData = (bool) preg_match('/[\'"](Buffer|BufferHex|Payload)[\'"]/', $php);
    if ($ownsData && array_intersect(array_map('strtoupper', (array) ($module['parentRequirements'] ?? [])), $ioTx) !== []) {
        $checks++;
        if (!str_contains($php, 'bin2hex(')) {
            $fail("$name: Daten an die I/O-Instanz werden nicht HEX-kodiert gesendet (bin2hex fehlt)");
        }
    }
    if ($ownsData && array_intersect(array_map('strtoupper', (array) ($module['implemented'] ?? [])), $ioRx) !== []) {
        $checks++;
        if (!str_contains($php, 'hex2bin(')) {
            $fail("$name: Daten der I/O-Instanz werden nicht HEX-dekodiert gelesen (hex2bin fehlt)");
        }
    }

    $hasTile = str_contains($php, 'GetVisualizationTile');
    if ($hasTile) {
        $checks++;
        if (!is_file("$dir/tile.html")) {
            $fail("$name: Kachel ohne tile.html");
            continue;
        }
        $tile = (string) file_get_contents("$dir/tile.html");
        $checks++;
        if (!str_contains($tile, 'Hausstil cfaf2002 · Kachel-Grundlage')) {
            $fail("$name/tile.html: Kachel-Grundlage fehlt");
        }
        $checks++;
        if (!str_contains($tile, 'function applyTheme')) {
            $fail("$name/tile.html: applyTheme fehlt");
        }
        $checks++;
        if (!str_contains($tile, 'SPDX-License-Identifier: MIT')) {
            $fail("$name/tile.html: Kopfkommentar mit SPDX fehlt");
        }
        $checks++;
        if (!array_key_exists('TileTheme', $properties)) {
            $fail("$name: Eigenschaft TileTheme fehlt");
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Strukturprüfung: " . count($errors) . " Fehler\n  - " . implode("\n  - ", $errors) . "\n");
    exit(1);
}
echo "Strukturprüfung: $checks Prüfungen bestanden\n";
