<?php

declare(strict_types=1);

/**
 * Ladetest mit den offiziellen Symcon-Stubs (https://github.com/symcon/SymconStubs) – Hausstil, in allen
 * Repositorys ohne eigenen Ladetest gleich.
 *
 * Lädt die Bibliothek wie Symcon, legt jede Instanz an, öffnet das Formular, erzeugt die Kachel und
 * schaltet das Farbschema der Kachel um. Netzwerk wird nicht benötigt: Abrufe schlagen hier fehl,
 * das Modul muss damit sauber umgehen.
 *
 * Aufruf: php tests/stubs.php <Pfad zu SymconStubs>
 *
 * SPDX-License-Identifier: MIT
 */

$stubs = $argv[1] ?? __DIR__ . '/../../SymconStubs';
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, 'SymconStubs nicht gefunden: ' . $stubs . PHP_EOL);
    exit(2);
}

set_error_handler(static function (int $no, string $str): bool {
    return $no === E_DEPRECATED || $no === E_USER_DEPRECATED || str_contains($str, 'could not be found');
});

// Die Stubs verlangen für Timer eine Testuhr (getTime). In eine Kopie die normale Uhrzeit eintragen.
$copy = sys_get_temp_dir() . '/symcon-stubs-' . getmypid();
@mkdir($copy);
foreach (glob($stubs . '/*.php') as $file) {
    $code = (string) file_get_contents($file);
    if (basename($file) === 'ModuleStrictStubs.php') {
        $code = str_replace(
            "throw new Exception('getTime needs to be implemented by module under test');\n    }\n}",
            "return time();\n    }\n}",
            $code
        );
    }
    file_put_contents($copy . '/' . basename($file), $code);
}
register_shutdown_function(static function () use ($copy): void {
    array_map('unlink', glob($copy . '/*.php'));
    @rmdir($copy);
});

require $copy . '/autoload.php';

\IPS\Kernel::reset();
foreach (['~UnixTimestamp' => 1, '~HTMLBox' => 3, '~Alert.Reversed' => 0, '~Switch' => 0] as $profile => $type) {
    if (!IPS_VariableProfileExists($profile)) {
        IPS_CreateVariableProfile($profile, $type);
    }
}
\IPS\ModuleLoader::loadLibrary(__DIR__ . '/../library.json');

$failed = 0;
function ok(bool $condition, string $message): void
{
    global $failed;
    echo ($condition ? '  ✓ ' : '  ✗ ') . $message . PHP_EOL;
    if (!$condition) {
        $failed++;
    }
}

foreach (glob(__DIR__ . '/../*/module.json') as $moduleJson) {
    $module = json_decode((string) file_get_contents($moduleJson), true);
    echo $module['name'] . PHP_EOL;
    try {
        $id = IPS_CreateInstance($module['id']);
        ok($id > 0, 'Instanz angelegt');
        $form = json_decode(IPS_GetConfigurationForm($id), true);
        if ($form === []) {
            // Ohne eigenes GetConfigurationForm liefern die Stubs „{}“ – dann gilt die form.json
            $form = json_decode((string) @file_get_contents(dirname($moduleJson) . '/form.json'), true);
        }
        ok(is_array($form) && isset($form['elements']), 'Formular ist gültiges JSON');

        $instance = \IPS\InstanceManager::getInstanceInterface($id);
        if (method_exists($instance, 'GetVisualizationTile')) {
            $tile = $instance->GetVisualizationTile();
            ok(str_contains($tile, 'Kachel-Grundlage') && str_contains($tile, 'handleMessage'), 'Kachel mit Grundlage und Startdaten');
            ok(!preg_match('#</script>\s*<(img|svg)#i', $tile), 'Startdaten beenden das Skript nicht');
            foreach ([1, 2, 0] as $theme) {
                IPS_SetProperty($id, 'TileTheme', $theme);
                IPS_ApplyChanges($id);
            }
            ok(IPS_GetProperty($id, 'TileTheme') === 0, 'Farbschema umschaltbar (Symcon-Design, Dunkel, Hell)');
        }
    } catch (Throwable $e) {
        ok(false, get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
    }
}

echo PHP_EOL . ($failed === 0 ? 'Ladetest bestanden.' : "Ladetest: $failed Fehler.") . PHP_EOL;
exit($failed === 0 ? 0 : 1);
