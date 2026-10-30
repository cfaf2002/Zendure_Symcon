<?php

declare(strict_types=1);

/**
 * Funktionstests für die Zendure Speicherstrategie mit den offiziellen Symcon-Stubs.
 *
 * Die PV-Prognose wird als Fixture (relativ zur aktuellen Uhrzeit) in den Speicher der Instanz gelegt –
 * es wird kein Netzwerk benötigt. Die Hub-Variablen werden wie vom Gerät gemeldet angelegt.
 *
 * Aufruf: php tests/run.php [Pfad zu SymconStubs]
 *
 * SPDX-License-Identifier: MIT
 */

$stubs = $argv[1] ?? __DIR__ . '/../../SymconStubs';
if (!is_file($stubs . '/autoload.php')) {
    // im Workflow werden die Stubs erst später geholt
    $clone = sys_get_temp_dir() . '/zendure-symconstubs';
    if (!is_file($clone . '/autoload.php')) {
        exec('git clone --depth 1 https://github.com/symcon/SymconStubs.git ' . escapeshellarg($clone) . ' 2>&1', $out, $rc);
    }
    $stubs = $clone;
}
if (!is_file($stubs . '/autoload.php')) {
    fwrite(STDERR, 'SymconStubs nicht gefunden: ' . $stubs . PHP_EOL);
    exit(2);
}

set_error_handler(static function (int $no, string $str): bool {
    return $no === E_DEPRECATED || $no === E_USER_DEPRECATED || str_contains($str, 'could not be found');
});

// Testuhr der Stubs durch die normale Uhrzeit ersetzen (wie in tests/stubs.php)
$copy = sys_get_temp_dir() . '/zendure-run-' . getmypid();
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

function call(object $obj, string $method, array $args = []): mixed
{
    $m = new ReflectionMethod($obj, $method);
    return $m->invokeArgs($obj, $args);
}

/** Hub-Variable anlegen, als hätte der Hub sie gemeldet. */
function hubVar(int $hub, string $ident, int $type, mixed $value): int
{
    $id = @IPS_GetObjectIDByIdent($ident, $hub);
    if ($id === false || $id === 0) {
        $id = IPS_CreateVariable($type);
        IPS_SetParent($id, $hub);
        IPS_SetIdent($id, $ident);
    }
    \IPS\VariableManager::writeVariable($id, $value);   // wie eine Meldung des Hubs (Variablen der Instanz sind schreibgeschützt)
    return $id;
}

/**
 * Prognose-Fixture: Sonnenaufgang 8 Uhr, -untergang 17 Uhr; Mittagsspitze $peakKw je Tag.
 * $now liegt am Tag 0 um $hour Uhr.
 */
function forecast(int $now, int $hour, array $peaks): array
{
    $midnight = $now - $hour * 3600;
    $rise = $set = $hours = [];
    foreach ($peaks as $d => $peak) {
        $day = $midnight + $d * 86400;
        $rise[] = $day + 8 * 3600;
        $set[] = $day + 17 * 3600;
        for ($h = 1; $h <= 24; $h++) {
            $x = ($h - 12.5) / 4.5;  // Glocke um 12:30
            $hours[$day + $h * 3600] = round(max(0.0, $peak * (1 - $x * $x)), 3);
        }
    }
    return ['time' => $now, 'sunrise' => $rise, 'sunset' => $set, 'hours' => $hours];
}

$moduleId = '{0E7766B4-A4B8-43EA-B79B-6DCB9B5BE75F}';
$hubModule = '{EEA9BD1E-4878-4A2D-9165-F23C8A4BA200}';

echo 'Zendure Speicherstrategie' . PHP_EOL;

$hub = IPS_CreateInstance($hubModule);
$id = IPS_CreateInstance($moduleId);
$s = \IPS\InstanceManager::getInstanceInterface($id);
ok(IPS_GetInstance($id)['InstanceStatus'] === 104, 'ohne Hub: Status 104');

IPS_SetProperty($id, 'HubInstance', $hub);
IPS_ApplyChanges($id);
ok(IPS_GetInstance($id)['InstanceStatus'] === 201, 'ohne Modulleistung: Status 201');

IPS_SetProperty($id, 'Peak1', 1600);
IPS_SetProperty($id, 'BaseLoad', 150);
IPS_SetProperty($id, 'UseSymconLocation', false);   // kein Abruf im Test
IPS_ApplyChanges($id);
ok(IPS_GetInstance($id)['InstanceStatus'] === 102, 'mit Hub und Modulen: aktiv');
ok(GetValue(IPS_GetObjectIDByIdent('Active', $id)) === true, 'Automatik bei neuer Instanz eingeschaltet');

hubVar($hub, 'packNum', 1, 2);
hubVar($hub, 'BatteryTemperature', 2, 12.0);
$capacity = call($s, 'Capacity');
ok(abs($capacity - 3.84) < 0.001, 'Kapazität 2 × 1,92 kWh = 3,84 kWh');

$now = time();

// Sonniger Tag morgen, Abend 20 Uhr
$fc = forecast($now, 20, [1.0, 1.2, 1.0]);
$plan = call($s, 'Plan', [$fc, $now, 80.0, 12.0, 3.84]);
ok($plan['mode'] === 1 && $plan['minSoc'] === 10, 'viel Sonne morgen → Sonnig, Entladegrenze 10 %');
ok($plan['output'] === null, 'Ausgangsleistung unangetastet, solange nicht eingeschaltet');

// Trüber Tag morgen
$fc = forecast($now, 20, [0.3, 0.2, 0.3]);
$plan = call($s, 'Plan', [$fc, $now, 80.0, 12.0, 3.84]);
ok($plan['mode'] === 3 && $plan['minSoc'] === 30, 'wenig Sonne morgen → Sparbetrieb, Entladegrenze 30 %');

// Dazwischen
$fc = forecast($now, 20, [0.6, 0.62, 0.6]);
$plan = call($s, 'Plan', [$fc, $now, 80.0, 12.0, 3.84]);
ok($plan['mode'] === 2 && $plan['minSoc'] > 10 && $plan['minSoc'] < 30 && $plan['minSoc'] % 5 === 0,
    'mittlere Prognose → Ausgeglichen, Grenze dazwischen in 5-%-Schritten (' . $plan['minSoc'] . ' %)');

// Vor Sonnenaufgang zählt der heutige Tag
$fc = forecast($now, 5, [1.2, 0.1, 0.1]);
$plan = call($s, 'Plan', [$fc, $now, 50.0, 12.0, 3.84]);
ok($plan['minSoc'] === 10 && str_starts_with($plan['reason'], 'Heute'), 'vor Sonnenaufgang gilt der heutige Tag');

// Frostreserve
$fc = forecast($now, 20, [1.0, 1.2, 1.0]);
$plan = call($s, 'Plan', [$fc, $now, 80.0, -2.0, 3.84]);
ok($plan['mode'] === 4 && $plan['minSoc'] === 40, 'kalter Akku → Frostreserve 40 %');

// Nachtverteilung der Ausgangsleistung
IPS_SetProperty($id, 'ControlOutput', true);
IPS_ApplyChanges($id);
$fc = forecast($now, 20, [0.3, 0.2, 0.3]);
$plan = call($s, 'Plan', [$fc, $now, 60.0, 12.0, 3.84]);
// nutzbar (60 − 30) % von 3,84 = 1,152 kWh über 13 h (bis 9 Uhr) ≈ 89 W → Mindestwert 100 W
ok($plan['output'] === 100, 'nachts knapp → Mindest-Ausgangsleistung 100 W (' . $plan['output'] . ' W)');
$plan = call($s, 'Plan', [$fc, $now, 100.0, 12.0, 3.84]);
ok($plan['output'] === 150, 'nachts reichlich → höchstens Grundlast 150 W (' . $plan['output'] . ' W)');
$fc = forecast($now, 12, [0.3, 0.2, 0.3]);
$plan = call($s, 'Plan', [$fc, $now, 60.0, 12.0, 3.84]);
ok($plan['output'] === 150, 'tagsüber → Grundlast');

// Ende-zu-Ende: Sollwert landet am Hub
hubVar($hub, 'electricLevel', 1, 70);
foreach (['minSoc' => 10, 'outputLimit' => 800] as $ident => $value) {
    \IPS\VariableManager::setVariableAction(hubVar($hub, $ident, 1, $value), $hub);   // Aktion des Hubs (wie EnableAction)
}
$fc = forecast($now, 20, [0.3, 0.2, 0.3]);
call($s, 'SetBuffer', ['Forecast', (string) json_encode($fc)]);
$result = $s->Evaluate();
ok($result === true, 'Auswertung läuft durch');
ok(GetValue(IPS_GetObjectIDByIdent('TargetMinSoc', $id)) === 30, 'Ziel-Entladegrenze 30 % gesetzt');
ok(GetValue(IPS_GetObjectIDByIdent('Mode', $id)) === 3, 'Betriebsart Sparbetrieb');
ok(str_contains((string) GetValue(IPS_GetObjectIDByIdent('Reason', $id)), "Entladegrenze 30\u{00A0}%"), 'Begründung nennt die Grenze');
$sent = json_decode((string) call($s, 'GetBuffer', ['Sent']), true);
ok(($sent['minSoc'][0] ?? null) === 30, 'Entladegrenze 30 % an den Hub gesendet');
ok(isset($sent['outputLimit']), 'Ausgangsleistung an den Hub gesendet');

// Automatik aus → nichts senden
$s->RequestAction('Active', false);
ok(GetValue(IPS_GetObjectIDByIdent('Mode', $id)) === 0, 'Automatik aus → Betriebsart Aus');
$s->RequestAction('Active', true);

// Freigabe-Variable (läuft bei An)
$release = IPS_CreateVariable(0);
SetValue($release, false);
IPS_SetProperty($id, 'ReleaseVariable', $release);
IPS_ApplyChanges($id);
call($s, 'SetBuffer', ['Forecast', (string) json_encode($fc)]);
$s->Evaluate();
ok(GetValue(IPS_GetObjectIDByIdent('Mode', $id)) === 5, 'Freigabe aus → pausiert');
SetValue($release, true);
$s->Evaluate();
ok(GetValue(IPS_GetObjectIDByIdent('Mode', $id)) === 3, 'Freigabe an → läuft wieder');

// Veraltete Prognose
$old = forecast($now - 8 * 3600, 12, [1, 1, 1]);
call($s, 'SetBuffer', ['Forecast', (string) json_encode($old)]);
$s->Evaluate();
ok(GetValue(IPS_GetObjectIDByIdent('Mode', $id)) === 6, 'Prognose älter als 6 h → nichts ändern');

// Kachel
$tile = $s->GetVisualizationTile();
ok(str_contains($tile, 'Kachel-Grundlage') && !str_contains($tile, '__INITIAL_STATE__'), 'Kachel mit Startdaten');

// Öffentliche Funktionen typisiert
$untyped = [];
foreach ((new ReflectionClass($s))->getMethods(ReflectionMethod::IS_PUBLIC) as $m) {
    if ($m->getDeclaringClass()->getName() !== get_class($s)) {
        continue;
    }
    if (!$m->hasReturnType()) {
        $untyped[] = $m->getName();
    }
    foreach ($m->getParameters() as $p) {
        if (!$p->hasType()) {
            $untyped[] = $m->getName() . '($' . $p->getName() . ')';
        }
    }
}
ok($untyped === [], 'alle öffentlichen Funktionen typisiert' . ($untyped ? ' – fehlt: ' . implode(', ', $untyped) : ''));

echo PHP_EOL . ($failed === 0 ? 'Funktionstests bestanden.' : "Funktionstests: $failed Fehler.") . PHP_EOL;
exit($failed === 0 ? 0 : 1);
