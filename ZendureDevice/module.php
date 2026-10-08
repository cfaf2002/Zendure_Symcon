<?php

declare(strict_types=1);

/**
 * Zendure SolarFlow Hub (Hub 1200 / Hub 2000)
 *
 * Liest die Werte des Hubs per MQTT (Zendure-Cloud oder lokaler Broker) und steuert ihn.
 *
 * Autor: Armin Frohwerk
 */
class ZendureSolarFlowHub extends IPSModuleStrict
{
    private const MQTT_TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';
    private const MAX_PACKS = 6;        // Variablen Pack1_ … Pack6_
    private const PACK_STALE_DAYS = 2;  // so lange nicht mehr gemeldet = ausgebaut, Platz wird wiederverwendet

    private const PRODUCT_KEYS = [
        'Hub 1200' => '73bkTV',
        'Hub 2000' => 'A8yh63',
    ];

    /**
     * Bekannte Eigenschaften des Hubs.
     * ident => [Name, Typ, Darstellung, Position, Umrechnung, schreibbar]
     * Typ: 0 Boolean, 1 Integer, 2 Float. Umrechnung: Divisor oder Spezialfall.
     */
    private const PROPERTIES = [
        'electricLevel'   => ['Ladezustand', 1, 'soc', 10, 1, false],
        'solarInputPower' => ['PV-Leistung', 1, 'solar', 20, 1, false],
        'solarPower1'     => ['PV-Leistung Eingang 1', 1, 'solar', 21, 1, false],
        'solarPower2'     => ['PV-Leistung Eingang 2', 1, 'solar', 22, 1, false],
        'outputHomePower' => ['Ausgang zum Haus', 1, 'home', 30, 1, false],
        'outputPackPower' => ['Ladeleistung Akku', 1, 'charge', 31, 1, false],
        'packInputPower'  => ['Entladeleistung Akku', 1, 'discharge', 32, 1, false],
        'packState'       => ['Akkustatus', 1, 'packState', 40, 1, false],
        'remainOutTime'   => ['Restlaufzeit Entladen', 1, 'minutes', 41, 1, false],
        'remainInputTime' => ['Restzeit Laden', 1, 'minutes', 42, 1, false],
        'packNum'         => ['Anzahl Akkus', 1, 'count', 43, 1, false],
        'outputLimit'     => ['Ausgangsleistung (Limit)', 1, 'limit', 50, 1, true],
        'inputLimit'      => ['Eingangsleistung (Limit)', 1, 'limit', 51, 1, true],
        'socSet'          => ['Ladegrenze', 1, 'socMax', 52, 10, true],
        'minSoc'          => ['Entladegrenze', 1, 'socMin', 53, 10, true],
        'passMode'        => ['Bypass-Modus', 1, 'passMode', 54, 1, true],
        'autoRecover'     => ['Bypass automatisch zurücksetzen', 0, 'switch', 55, 1, true],
        'buzzerSwitch'    => ['Signalton', 0, 'switch', 56, 1, true],
        'pass'            => ['Bypass aktiv', 0, 'bypass', 60, 1, false],
        'masterSwitch'    => ['Hauptschalter', 0, 'power', 61, 1, false],
        'hubState'        => ['Verhalten bei leerem Akku', 1, 'hubState', 62, 1, false],
        'inverseMaxPower' => ['Max. Wechselrichterleistung', 1, 'watt', 63, 1, false],
        'heatState'       => ['Akkuheizung aktiv', 0, 'heat', 64, 1, false],
        'wifiState'       => ['WLAN verbunden', 0, 'wifiOn', 65, 1, false],
        'rssi'            => ['WLAN-Signal', 1, 'dbm', 66, 1, false],
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('Model', 'Hub 2000');
        $this->RegisterPropertyString('ProductKey', '');
        $this->RegisterPropertyString('DeviceKey', '');
        $this->RegisterPropertyString('SerialNumber', '');
        $this->RegisterPropertyInteger('UpdateInterval', 60);
        $this->RegisterPropertyInteger('MaxOutputPower', 1200);
        $this->RegisterPropertyBoolean('ShowPacks', true);
        $this->RegisterPropertyInteger('InverterPowerVariable', 0);
        $this->RegisterPropertyInteger('DirectPVVariable', 0);
        $this->RegisterPropertyInteger('DirectPVVariable2', 0);
        $this->RegisterPropertyInteger('InverterEnergyTodayVariable', 0);
        // Kachel-Hintergrund
        $this->RegisterPropertyInteger('TileTheme', 0);            // 0 = Symcon-Design, 1 = Dunkel, 2 = Hell
        $this->RegisterPropertyString('TileBackground', 'aurora'); // aurora | scene | image | none
        $this->RegisterPropertyInteger('TileImage', 0);            // Medienobjekt (Bild)
        $this->RegisterPropertyInteger('TileImageOpacity', 30);    // %

        $this->RegisterAttributeString('Packs', '[]');
        $this->RegisterAttributeString('PacksSeen', '{}');   // Seriennummer => Datum der letzten Meldung
        $this->RegisterAttributeString('WatchedVariables', '[]');
        $this->RegisterAttributeString('EnergyDay', '');

        // Eigene Kachel (HTML-SDK)
        $this->SetVisualizationType(1);

        $this->RegisterTimer('Poll', 0, 'IPS_RequestAction($_IPS[\'TARGET\'], \'Poll\', 0);');
        // Gateway: "Zendure Cloud" (Cloud-Betrieb) oder direkt ein MQTT Server/Client (lokaler Betrieb)
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        // Wechselrichter-Variablen (z. B. Hoymiles) für die Kachel beobachten
        foreach (json_decode($this->ReadAttributeString('WatchedVariables'), true) ?: [] as $vid) {
            $this->UnregisterMessage((int) $vid, VM_UPDATE);
        }
        $watched = [];
        foreach (['InverterPowerVariable', 'DirectPVVariable', 'DirectPVVariable2', 'InverterEnergyTodayVariable'] as $prop) {
            $vid = $this->ReadPropertyInteger($prop);
            if ($vid > 0 && IPS_VariableExists($vid)) {
                $this->RegisterMessage($vid, VM_UPDATE);
                $watched[] = $vid;
            }
        }
        $this->WriteAttributeString('WatchedVariables', json_encode($watched));

        // Darstellungen (ab Symcon 8) – auch bei bestehenden Variablen aktualisieren
        foreach (self::PROPERTIES as $ident => [$name, $type, $pres, $position, $divisor, $writable]) {
            if ($this->VariableExists($ident)) {
                $this->MaintainVariable($ident, $name, $type, $this->Presentation($pres), $position, true);
            }
        }
        foreach (['Pack1_', 'Pack2_', 'Pack3_', 'Pack4_', 'Pack5_', 'Pack6_'] as $n => $prefix) {
            $label = 'Akku ' . ($n + 1) . ' ';
            $pos = 100 + ($n + 1) * 10;
            foreach ([['SoC', 'Ladezustand', 1, 'soc', 0], ['Temp', 'Temperatur', 2, 'temp', 1], ['Volt', 'Spannung', 2, 'volt', 2],
                      ['Current', 'Strom', 2, 'ampere', 3], ['Power', 'Leistung', 1, 'battery', 4]] as [$suffix, $name, $type, $pres, $off]) {
                if ($this->VariableExists($prefix . $suffix)) {
                    $this->MaintainVariable($prefix . $suffix, $label . $name, $type, $this->Presentation($pres), $pos + $off, true);
                }
            }
        }
        $this->RemoveOldProfiles();

        $image = $this->ReadPropertyInteger('TileImage');
        if ($image > 0 && @IPS_MediaExists($image)) {
            $this->RegisterReference($image);
        }

        $this->RegisterVariableBoolean('Online', 'Online', $this->Presentation('online'), 0);
        $this->RegisterVariableInteger('LastUpdate', 'Letzte Meldung', $this->Presentation('datetime'), 1);
        $this->RegisterVariableInteger('BatteryPower', 'Akkuleistung (+ Laden / − Entladen)', $this->Presentation('battery'), 33);
        $this->RegisterVariableFloat('SolarEnergyToday', 'Solarertrag Hub heute', $this->Presentation('kwh'), 23);
        $this->RegisterVariableFloat('BatteryTemperature', 'Akkutemperatur', $this->Presentation('temp'), 44);
        $this->RegisterVariableInteger('DischargePower', 'Entladeleistung vorgeben', $this->Presentation('limit'), 49);
        $this->EnableAction('DischargePower');

        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->UpdateVisualizationValue((string) json_encode(['background' => $this->TileBackground(), 'theme' => $this->ReadPropertyInteger('TileTheme')]));
        }

        $deviceKey = trim($this->ReadPropertyString('DeviceKey'));
        if ($deviceKey === '' || $this->GetProductKey() === '') {
            $this->SetReceiveDataFilter('ZENDURE_NO_DEVICE');
            $this->SetTimerInterval('Poll', 0);
            $this->SetStatus(104);
            return;
        }

        $this->SetReceiveDataFilter('.*' . preg_quote($deviceKey, '/') . '.*');
        $this->SetTimerInterval('Poll', max(10, $this->ReadPropertyInteger('UpdateInterval')) * 1000);
        $this->SetSummary($this->ReadPropertyString('Model') . ' ' . $this->ReadPropertyString('SerialNumber'));
        $this->SetStatus(102);

        if (IPS_GetKernelRunlevel() === KR_READY && $this->HasActiveParent()) {
            $this->RequestUpdate();
        }
    }

    // ---------------------------------------------------------------------
    // Öffentliche Funktionen
    // ---------------------------------------------------------------------

    /** Fordert alle Werte beim Hub an. */
    public function RequestUpdate(): void
    {
        $this->CheckOnline();
        $this->AccumulateEnergy();
        $this->Publish('properties/read', ['properties' => ['getAll']]);
    }

    /** Ausgangsleistung zum Wechselrichter begrenzen (Watt). */
    public function SetOutputLimit(int $Watt): bool
    {
        $Watt = max(0, min($this->ReadPropertyInteger('MaxOutputPower'), $Watt));
        return $this->WriteProperties(['outputLimit' => $Watt]);
    }

    /** Eingangsleistung begrenzen (Watt), nur falls vom Gerät unterstützt. */
    public function SetInputLimit(int $Watt): bool
    {
        return $this->WriteProperties(['inputLimit' => max(0, $Watt)]);
    }

    /** Ladegrenze in Prozent (70–100). */
    public function SetMaxSoc(int $Percent): bool
    {
        $Percent = max(70, min(100, $Percent));
        return $this->WriteProperties(['socSet' => $Percent * 10]);
    }

    /** Entladegrenze in Prozent (0–50). */
    public function SetMinSoc(int $Percent): bool
    {
        $Percent = max(0, min(50, $Percent));
        return $this->WriteProperties(['minSoc' => $Percent * 10]);
    }

    /** Bypass-Modus: 0 = automatisch, 1 = immer aus, 2 = immer an. */
    public function SetPassMode(int $Mode): bool
    {
        if (!in_array($Mode, [0, 1, 2], true)) {
            return false;
        }
        return $this->WriteProperties(['passMode' => $Mode]);
    }

    public function SetAutoRecover(bool $Value): bool
    {
        return $this->WriteProperties(['autoRecover' => $Value ? 1 : 0]);
    }

    public function SetBuzzer(bool $Value): bool
    {
        return $this->WriteProperties(['buzzerSwitch' => $Value ? 1 : 0]);
    }

    /**
     * Entladeleistung über die Geräteautomatik vorgeben (wie die offizielle Zendure-Integration).
     * 0 = Automatik aus / Ausgabe stoppen.
     */
    public function SetDischargePower(int $Watt): bool
    {
        $Watt = max(0, min($this->ReadPropertyInteger('MaxOutputPower'), $Watt));
        if ($Watt === 0) {
            $args = ['autoModelProgram' => 0, 'autoModelValue' => 0, 'msgType' => 1, 'autoModel' => 0];
        } else {
            $args = ['autoModelProgram' => 2, 'autoModelValue' => $Watt, 'msgType' => 1, 'autoModel' => 8];
        }
        $ok = $this->Publish('function/invoke', [
            'arguments' => [$args],
            'function'  => 'deviceAutomation',
            'deviceKey' => $this->ReadPropertyString('DeviceKey'),
        ]);
        if ($ok) {
            $this->SetValue('DischargePower', $Watt);
        }
        return $ok;
    }

    /** Beliebige Eigenschaft schreiben (Ganzzahl). */
    public function WriteProperty(string $Name, int $Value): bool
    {
        return $this->WriteProperties([$Name => $Value]);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Poll':
                $this->PollIfSilent();
                return;
            case 'outputLimit':
                $this->SetOutputLimit((int) $Value);
                break;
            case 'inputLimit':
                $this->SetInputLimit((int) $Value);
                break;
            case 'socSet':
                $this->SetMaxSoc((int) $Value);
                break;
            case 'minSoc':
                $this->SetMinSoc((int) $Value);
                break;
            case 'passMode':
                $this->SetPassMode((int) $Value);
                break;
            case 'autoRecover':
                $this->SetAutoRecover((bool) $Value);
                break;
            case 'buzzerSwitch':
                $this->SetBuzzer((bool) $Value);
                break;
            case 'DischargePower':
                $this->SetDischargePower((int) $Value);
                break;
            default:
                throw new Exception('Unbekannte Aktion: ' . $Ident);
        }
    }

    // ---------------------------------------------------------------------
    // Datenfluss
    // ---------------------------------------------------------------------

    public function ReceiveData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        $topic = (string) ($data['Topic'] ?? '');
        $base = $this->GetProductKey() . '/' . $this->ReadPropertyString('DeviceKey') . '/';

        $pos = strpos($topic, $base);
        if ($pos === false) {
            return '';
        }
        // Befehle (iot/...) stammen von uns selbst bzw. der App
        if (strpos($topic, 'iot/') === 0) {
            return '';
        }
        $suffix = substr($topic, $pos + strlen($base));
        $payload = $this->DecodePayload((string) ($data['Payload'] ?? ''));
        $this->SendDebug('RX ' . $suffix, $payload, 0);

        $json = json_decode($payload, true);
        if (!is_array($json)) {
            return '';
        }

        switch ($suffix) {
            case 'properties/report':
                $this->HandleReport($json);
                break;
            default:
                // time-sync, function/invoke/reply, event/... werden nur protokolliert
                break;
        }
        return '';
    }

    // ---------------------------------------------------------------------
    // Verarbeitung
    // ---------------------------------------------------------------------

    private function HandleReport(array $json): void
    {
        $now = time();
        $this->SetBuffer('LastReport', (string) $now);
        if ($now - (int) $this->GetValue('LastUpdate') >= 60) {
            $this->SetValue('LastUpdate', $now); // Variable höchstens einmal pro Minute schreiben
        }
        if (!$this->GetValue('Online')) {
            $this->SetValue('Online', true);
        }

        $props = $json['properties'] ?? [];
        // Ertrag mit der bisherigen Leistung bis jetzt aufsummieren, bevor neue Werte gesetzt werden
        $this->AccumulateEnergy();
        if (is_array($props)) {
            foreach ($props as $key => $value) {
                if (!isset(self::PROPERTIES[$key]) || !is_numeric($value)) {
                    continue;
                }
                [$name, $type, $pres, $position, $divisor, $writable] = self::PROPERTIES[$key];
                $this->EnsureVariable($key, $name, $type, $pres, $position, $writable);

                switch ($type) {
                    case 0:
                        $this->SetIfChanged($key, (int) $value !== 0);
                        break;
                    case 1:
                        $this->SetIfChanged($key, (int) round($value / $divisor));
                        break;
                    default:
                        $this->SetIfChanged($key, (float) $value / $divisor);
                }
            }

            if (array_key_exists('outputPackPower', $props) || array_key_exists('packInputPower', $props)) {
                $charge = $this->ValueOrZero('outputPackPower');
                $discharge = $this->ValueOrZero('packInputPower');
                $this->SetIfChanged('BatteryPower', $charge - $discharge);
            }
        }

        if (!empty($json['packData']) && is_array($json['packData'])) {
            $temps = [];
            foreach ($json['packData'] as $pack) {
                if (isset($pack['maxTemp']) && is_numeric($pack['maxTemp'])) {
                    $temps[] = round(((float) $pack['maxTemp'] - 2731) / 10, 1);
                }
            }
            if (count($temps) > 0) {
                $this->SetIfChanged('BatteryTemperature', (float) max($temps));
            }
            if ($this->ReadPropertyBoolean('ShowPacks')) {
                $this->HandlePacks($json['packData']);
            }
        }

        $this->UpdateTile();
    }

    private function HandlePacks(array $packs): void
    {
        $known = json_decode($this->ReadAttributeString('Packs'), true) ?: [];
        $seen = json_decode($this->ReadAttributeString('PacksSeen'), true) ?: [];
        $today = date('Y-m-d');
        $seenChanged = false;

        foreach ($packs as $pack) {
            $sn = (string) ($pack['sn'] ?? '');
            if ($sn === '') {
                continue;
            }
            $index = array_search($sn, $known, true);
            if ($index === false) {
                $index = $this->PackSlot($known, $seen);
                if (isset($known[$index])) {
                    $this->SendDebug('Packs', 'Akku ' . ($index + 1) . ': ' . $known[$index] . ' ersetzt durch ' . $sn, 0);
                    unset($seen[$known[$index]]);
                }
                $known[$index] = $sn;
                $this->WriteAttributeString('Packs', json_encode(array_values($known)));
            }
            if (($seen[$sn] ?? '') !== $today) {
                $seen[$sn] = $today;
                $seenChanged = true;
            }
            $n = $index + 1;
            $prefix = 'Pack' . $n . '_';
            $label = 'Akku ' . $n . ' ';
            $pos = 100 + $n * 10;

            if (isset($pack['socLevel'])) {
                $this->EnsureVariable($prefix . 'SoC', $label . 'Ladezustand', 1, 'soc', $pos, false);
                $this->SetIfChanged($prefix . 'SoC', (int) $pack['socLevel']);
            }
            if (isset($pack['maxTemp'])) {
                $this->EnsureVariable($prefix . 'Temp', $label . 'Temperatur', 2, 'temp', $pos + 1, false);
                $this->SetIfChanged($prefix . 'Temp', round(((float) $pack['maxTemp'] - 2731) / 10, 1));
            }
            if (isset($pack['totalVol'])) {
                $this->EnsureVariable($prefix . 'Volt', $label . 'Spannung', 2, 'volt', $pos + 2, false);
                $this->SetIfChanged($prefix . 'Volt', round((float) $pack['totalVol'] / 100, 2));
            }
            if (isset($pack['batcur'])) {
                $cur = (int) $pack['batcur'];
                if ($cur >= 32768) {
                    $cur -= 65536;
                }
                $this->EnsureVariable($prefix . 'Current', $label . 'Strom', 2, 'ampere', $pos + 3, false);
                $this->SetIfChanged($prefix . 'Current', round($cur / 10, 1));
            }
            if (isset($pack['power'])) {
                $this->EnsureVariable($prefix . 'Power', $label . 'Leistung', 1, 'battery', $pos + 4, false);
                $this->SetIfChanged($prefix . 'Power', (int) $pack['power']);
            }
        }
        if ($seenChanged) {
            $this->WriteAttributeString('PacksSeen', json_encode($seen));
        }
    }

    /**
     * Platz (0-basiert) für einen neuen Akku: freier Platz, sonst der Platz eines Akkus, der seit
     * PACK_STALE_DAYS Tagen nicht mehr gemeldet wurde (getauscht), sonst der am längsten nicht gesehene.
     * So bleiben die Nummern nach einem Tausch bei Akku 1 … 6.
     */
    private function PackSlot(array $known, array $seen): int
    {
        if (count($known) < self::MAX_PACKS) {
            return count($known);
        }
        $oldest = 0;
        $oldestDate = null;
        foreach (array_slice(array_values($known), 0, self::MAX_PACKS) as $i => $sn) {
            $date = (string) ($seen[$sn] ?? '');  // ohne Datum: aus einer älteren Version, gilt als lange nicht gesehen
            if ($oldestDate === null || strcmp($date, $oldestDate) < 0) {
                $oldest = $i;
                $oldestDate = $date;
            }
        }
        $stale = $oldestDate === '' || strtotime($oldestDate) <= strtotime('-' . self::PACK_STALE_DAYS . ' days', strtotime('today'));
        if (!$stale) {
            $this->SendDebug('Packs', 'Mehr als ' . self::MAX_PACKS . ' Akkus gemeldet – ältester Platz wird überschrieben', 0);
        }
        return $oldest;
    }

    /** Summiert die Solarleistung des Hubs zum Tagesertrag (kWh), Rücksetzung um Mitternacht. */
    private function AccumulateEnergy(): void
    {
        $now = time();
        $today = date('Y-m-d', $now);
        if ($this->ReadAttributeString('EnergyDay') !== $today) {
            $this->WriteAttributeString('EnergyDay', $today); // einmal am Tag
            $this->SetBuffer('EnergyAcc', '0');
            $this->SetIfChanged('SolarEnergyToday', 0.0);
            $this->SetBuffer('EnergyLastTs', (string) $now);
            return;
        }
        $last = (int) $this->GetBuffer('EnergyLastTs');
        $this->SetBuffer('EnergyLastTs', (string) $now);
        if ($last <= 0 || $now <= $last || !$this->GetValue('Online')) {
            return;
        }
        $dt = min($now - $last, 600); // Lücken (z. B. offline) nicht hochrechnen
        $power = $this->ValueOrZero('solarInputPower');
        if ($power <= 0) {
            return;
        }
        // Genaue Summe im Speicher, Variable nur in 1-Wh-Schritten schreiben
        $acc = $this->GetBuffer('EnergyAcc');
        $kwh = ($acc !== '' ? (float) $acc : (float) $this->GetValue('SolarEnergyToday')) + $power * $dt / 3600000;
        $this->SetBuffer('EnergyAcc', (string) $kwh);
        $this->SetIfChanged('SolarEnergyToday', round($kwh, 3));
    }

    /** Schreibt eine Variable nur, wenn sich der Wert geändert hat (weniger Last und Archivdaten). */
    private function SetIfChanged(string $ident, mixed $value): void
    {
        if ($this->GetValue($ident) !== $value) {
            $this->SetValue($ident, $value);
        }
    }

    /**
     * Timer: Der Hub meldet Änderungen selbst. Nur wenn er seit einem Intervall still war,
     * werden alle Werte angefordert – das spart Nachrichten an Hub und Zendure-Cloud.
     */
    private function PollIfSilent(): void
    {
        $this->CheckOnline();
        $this->AccumulateEnergy();
        $interval = max(10, $this->ReadPropertyInteger('UpdateInterval'));
        if (time() - $this->LastReport() >= $interval) {
            $this->Publish('properties/read', ['properties' => ['getAll']]);
        } else {
            $this->SendDebug('Poll', 'Hub hat sich selbst gemeldet – keine Abfrage nötig', 0);
        }
    }

    /** Zeitpunkt der letzten Meldung (im Speicher, nach Neustart aus der Variable). */
    private function LastReport(): int
    {
        $buf = $this->GetBuffer('LastReport');
        return $buf !== '' ? (int) $buf : (int) $this->GetValue('LastUpdate');
    }

    private function CheckOnline(): void
    {
        $last = $this->LastReport();
        $timeout = max(10, $this->ReadPropertyInteger('UpdateInterval')) * 3 + 30;
        $online = $last > 0 && (time() - $last) <= $timeout;
        if ($this->GetValue('Online') !== $online) {
            $this->SetValue('Online', $online);
            $this->UpdateTile();
        }
    }

    // ---------------------------------------------------------------------
    // Kachel
    // ---------------------------------------------------------------------

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === VM_UPDATE) {
            $this->UpdateTile();
        }
    }

    public function GetVisualizationTile(): string
    {
        $state = $this->BuildTileState();
        $state['background'] = $this->TileBackground();
        $html = file_get_contents(__DIR__ . '/tile.html');
        return str_replace('__INITIAL_STATE__', (string) json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), (string) $html);
    }

    /** Hintergrund der Kachel: eingebaute Szene, eigenes Bild (Medienobjekt) oder keiner. */
    private function TileBackground(): array
    {
        $mode = $this->ReadPropertyString('TileBackground');
        $opacity = max(5, min(100, $this->ReadPropertyInteger('TileImageOpacity'))) / 100;
        if ($mode === 'image') {
            $media = $this->ReadPropertyInteger('TileImage');
            if ($media > 0 && @IPS_MediaExists($media)) {
                $uri = $this->TileImageData($media, 1600);
                if ($uri !== '') {
                    return ['mode' => 'image', 'image' => $uri, 'opacity' => $opacity];
                }
            }
            $mode = 'aurora';
        }
        if (!in_array($mode, ['aurora', 'scene', 'none'], true)) {
            $mode = 'aurora';
        }
        return ['mode' => $mode, 'image' => null, 'opacity' => $opacity];
    }

    /**
     * Bild als data-URI für die Kachel. Große Bilder werden verkleinert (längste Seite $maxSize px),
     * damit die Kachel die Grenze der Visualisierung von 1 MB nicht überschreitet. Ergebnis wird zwischengespeichert.
     */
    private function TileImageData(int $media, int $maxSize): string
    {
        $content = (string) IPS_GetMediaContent($media); // Base64
        if ($content === '') {
            return '';
        }
        $key = md5($media . '|' . $maxSize . '|' . strlen($content) . '|' . substr($content, 0, 64) . '|' . substr($content, -64));
        $cache = json_decode($this->GetBuffer('TileImage'), true) ?: [];
        if (($cache['key'] ?? '') === $key) {
            return (string) $cache['uri'];
        }

        $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif', 'svg' => 'image/svg+xml'];
        $ext = strtolower(pathinfo((string) IPS_GetMedia($media)['MediaFile'], PATHINFO_EXTENSION));
        $mime = $types[$ext] ?? 'image/jpeg';

        $uri = '';
        $problem = '';
        $scaled = self::scaleImage($content, $mime, $maxSize);
        if (isset($scaled['uri'])) {
            $uri = $scaled['uri'];
        } elseif (isset($scaled['error'])) {
            $problem = sprintf('Kachelbild ist zu groß zum Verkleinern (%d × %d Pixel) – bitte ein kleineres Bild verwenden (z. B. max. 2000 Pixel breit).', $scaled['from'][0], $scaled['from'][1]);
        } else {
            $uri = "data:$mime;base64,$content";
        }
        unset($content);
        if ($problem === '' && strlen($uri) > 700 * 1024) {
            $problem = 'Kachelbild ist zu groß – bitte ein kleineres Bild verwenden (JPEG, max. ca. 500 kB).';
            $uri = '';
        }
        if ($problem !== '') {
            $this->SendDebug('Tile', $problem, 0);
            $this->LogMessage($problem, KL_WARNING);
        }
        $this->SetBuffer('TileImage', json_encode(['key' => $key, 'uri' => $uri]));
        return $uri;
    }

    private static function scaleImage(string $base64, string $mime, int $maxSize): ?array
    {
        $raw = base64_decode($base64, true);
        if ($mime === 'image/svg+xml' || $raw === false || !function_exists('imagecreatefromstring')) {
            return null;
        }
        $info = @getimagesizefromstring($raw);
        if ($info === false || $info[0] <= 0 || $info[1] <= 0) {
            return null;
        }
        [$w, $h] = $info;
        if (max($w, $h) <= $maxSize && strlen($raw) <= 400 * 1024) {
            return null; // schon klein genug
        }
        // Entpackt braucht das Bild ca. 5 Byte je Pixel. Symcon erlaubt Skripten meist nur 32 MB –
        // vorher prüfen (und wenn möglich kurz anheben), statt mit einem Speicherfehler abzubrechen.
        $scale = min(1.0, $maxSize / max($w, $h));
        $need = (int) ($w * $h * 5.5 + ($w * $scale) * ($h * $scale) * 5 + strlen($raw) * 2 + 4 * 1024 * 1024);
        $oldLimit = ini_get('memory_limit');
        if (!self::ensureMemory($need)) {
            return ['error' => 'memory', 'from' => [$w, $h]];
        }
        try {
            $result = self::scaleDecoded($raw, $mime, $w, $h, $scale);
        } finally {
            if ($oldLimit !== false && function_exists('ini_set') && ini_get('memory_limit') !== $oldLimit) {
                @ini_set('memory_limit', $oldLimit);
            }
        }
        return $result;
    }

    private static function scaleDecoded(string $raw, string $mime, int $w, int $h, float $scale): ?array
    {
        $img = @imagecreatefromstring($raw);
        if ($img === false) {
            return null;
        }
        $nw = max(1, (int) round($w * $scale));
        $nh = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($nw, $nh);
        $alpha = in_array($mime, ['image/png', 'image/webp', 'image/gif'], true);
        if ($alpha) {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            imagefill($out, 0, 0, imagecolorallocatealpha($out, 0, 0, 0, 127));
        }
        imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        ob_start();
        $alpha ? imagepng($out, null, 9) : imagejpeg($out, null, 82);
        $data = (string) ob_get_clean();
        unset($img, $out); // imagedestroy() ist seit PHP 8.0 wirkungslos und ab PHP 8.5 veraltet
        if ($data === '' || strlen($data) >= strlen($raw)) {
            return null;
        }
        return ['uri' => 'data:' . ($alpha ? 'image/png' : 'image/jpeg') . ';base64,' . base64_encode($data), 'from' => [$w, $h], 'to' => [$nw, $nh]];
    }

    private static function ensureMemory(int $need): bool
    {
        $limit = self::bytes((string) ini_get('memory_limit'));
        if ($limit < 0) {
            return true; // unbegrenzt
        }
        $used = memory_get_usage(true);
        if ($limit - $used >= $need) {
            return true;
        }
        $wanted = $used + $need + 8 * 1024 * 1024;
        if (!function_exists('ini_set') || @ini_set('memory_limit', (string) $wanted) === false) {
            return false;
        }
        return self::bytes((string) ini_get('memory_limit')) >= $wanted;
    }

    private static function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $num = (int) $value;
        switch (strtolower(substr($value, -1))) {
            case 'g': return $num * 1024 * 1024 * 1024;
            case 'm': return $num * 1024 * 1024;
            case 'k': return $num * 1024;
        }
        return $num;
    }


    private function UpdateTile(): void
    {
        $data = (string) json_encode($this->BuildTileState());
        $hash = md5($data);
        if ($this->GetBuffer('TileHash') === $hash) {
            return; // nichts geändert – offene Visualisierungen nicht unnötig beschicken
        }
        $this->SetBuffer('TileHash', $hash);
        $this->UpdateVisualizationValue($data);
    }

    private function BuildTileState(): array
    {
        $num = function (string $ident) {
            $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            return ($id !== false && $id > 0) ? GetValue($id) : null;
        };
        $packs = $num('packNum');
        if ($packs === null) {
            $packs = count(json_decode($this->ReadAttributeString('Packs'), true) ?: []);
        }
        return [
            'theme'           => $this->ReadPropertyInteger('TileTheme'),
            'online'          => (bool) $num('Online'),
            'solar'           => (int) ($num('solarInputPower') ?? 0),
            'home'            => (int) ($num('outputHomePower') ?? 0),
            'battery'         => (int) ($num('BatteryPower') ?? 0),
            'soc'             => $num('electricLevel'),
            'packs'           => (int) $packs,
            'remainDischarge' => $num('remainOutTime'),
            'remainCharge'    => $num('remainInputTime'),
            'bypass'          => (bool) $num('pass'),
            'inverter'        => $this->ReadLinkedPower('InverterPowerVariable'),
            'direct'          => $this->ReadDirectPower(),
            'energyHub'       => round((float) ($num('SolarEnergyToday') ?? 0), 2),
            'energyPlant'     => $this->ReadLinkedEnergy('InverterEnergyTodayVariable'),
            'temp'            => $num('BatteryTemperature'),
            'objects'         => $this->TileObjects(),
            'sunrise'         => $this->LocationTime('Sunrise'),
            'sunset'          => $this->LocationTime('Sunset'),
        ];
    }

    /** Objekte, die beim Antippen in der Kachel geöffnet werden (openObject, ab Symcon 9.0). */
    private function TileObjects(): array
    {
        $id = function (string $ident): int {
            $vid = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
            return ($vid !== false && $vid > 0) ? (int) $vid : 0;
        };
        $prop = function (string $name): int {
            $vid = $this->ReadPropertyInteger($name);
            return ($vid > 0 && IPS_ObjectExists($vid)) ? $vid : 0;
        };
        $inverter = $prop('InverterPowerVariable');
        return [
            'hub'    => $this->InstanceID,
            'pv'     => $id('solarInputPower'),
            'bat'    => $id('BatteryPower'),
            'soc'    => $id('electricLevel'),
            'time'   => $id('remainOutTime') ?: $id('remainInputTime'),
            'home'   => $inverter ?: $id('outputHomePower'),
            'inv'    => $inverter > 0 ? (int) IPS_GetParent($inverter) : 0,
            'direct' => $prop('DirectPVVariable') ?: ($inverter > 0 ? (int) IPS_GetParent($inverter) : 0),
            'energy' => $prop('InverterEnergyTodayVariable') ?: $id('SolarEnergyToday'),
            'temp'   => $id('BatteryTemperature'),
        ];
    }

    /** Sonnenauf-/-untergang aus der Standort-Instanz von Symcon (null, wenn keine vorhanden). */
    private function LocationTime(string $ident): ?int
    {
        foreach (IPS_GetInstanceListByModuleID('{45E97A63-F870-408A-B259-2933F7EABF74}') as $id) {
            $vid = @IPS_GetObjectIDByIdent($ident, $id);
            if ($vid !== false && $vid > 0) {
                $v = (int) GetValue($vid);
                return $v > 0 ? $v : null;
            }
        }
        return null;
    }

    /** Summe der direkt am Wechselrichter angeschlossenen Eingänge (null = nicht konfiguriert). */
    private function ReadDirectPower(): ?int
    {
        $a = $this->ReadLinkedPower('DirectPVVariable');
        $b = $this->ReadLinkedPower('DirectPVVariable2');
        if ($a === null && $b === null) {
            return null;
        }
        return (int) ($a ?? 0) + (int) ($b ?? 0);
    }

    /** Liest eine ausgewählte Energievariable in kWh (Wh-Profile werden umgerechnet). */
    private function ReadLinkedEnergy(string $property): ?float
    {
        $vid = $this->ReadPropertyInteger($property);
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return null;
        }
        $value = (float) GetValue($vid);
        if (strcasecmp($this->LinkedSuffix($vid), 'Wh') === 0) {
            $value /= 1000;
        }
        return round(max(0, $value), 2);
    }

    /** Liest eine ausgewählte Leistungsvariable in Watt (kW-Profile werden umgerechnet). */
    private function ReadLinkedPower(string $property): ?int
    {
        $vid = $this->ReadPropertyInteger($property);
        if ($vid <= 0 || !IPS_VariableExists($vid)) {
            return null;
        }
        $value = (float) GetValue($vid);
        if (strcasecmp($this->LinkedSuffix($vid), 'kW') === 0) {
            $value *= 1000;
        }
        return (int) round(max(0, $value));
    }

    /** Einheit einer ausgewählten Variable: aus der Darstellung (Symcon 8+), sonst aus dem Variablenprofil. */
    private function LinkedSuffix(int $vid): string
    {
        $var = IPS_GetVariable($vid);
        $presentations = [];
        if (function_exists('IPS_GetVariablePresentation')) {
            $presentations[] = @IPS_GetVariablePresentation($vid);
        }
        $presentations[] = $var['VariableCustomPresentation'] ?? [];
        $presentations[] = $var['VariablePresentation'] ?? [];
        $profile = '';
        foreach ($presentations as $p) {
            if (!is_array($p) || $p === []) {
                continue;
            }
            if (isset($p['SUFFIX']) && trim((string) $p['SUFFIX']) !== '') {
                return trim((string) $p['SUFFIX']);
            }
            if ($profile === '' && !empty($p['PROFILE'])) {
                $profile = (string) $p['PROFILE']; // Darstellung „Profil“ (Legacy)
            }
        }
        if ($profile === '') {
            $profile = $var['VariableCustomProfile'] !== '' ? $var['VariableCustomProfile'] : $var['VariableProfile'];
        }
        if ($profile !== '' && IPS_VariableProfileExists($profile)) {
            return trim(IPS_GetVariableProfile($profile)['Suffix']);
        }
        return '';
    }

    // ---------------------------------------------------------------------
    // MQTT
    // ---------------------------------------------------------------------

    private function WriteProperties(array $properties): bool
    {
        return $this->Publish('properties/write', ['properties' => $properties]);
    }

    private function Publish(string $suffix, array $message): bool
    {
        $productKey = $this->GetProductKey();
        $deviceKey = $this->ReadPropertyString('DeviceKey');
        if ($productKey === '' || $deviceKey === '') {
            $this->SendDebug('TX', 'Product Key / Device Key fehlt', 0);
            return false;
        }
        if (!$this->HasActiveParent()) {
            $this->SendDebug('TX', 'Keine aktive MQTT-Verbindung', 0);
            return false;
        }

        $messageId = ((int) $this->GetBuffer('MessageId') % 999999) + 1;
        $this->SetBuffer('MessageId', (string) $messageId); // im Speicher, nicht in den Einstellungen

        $message['messageId'] = $messageId;
        $message['deviceId'] = $deviceKey;
        $message['timestamp'] = time();

        $topic = 'iot/' . $productKey . '/' . $deviceKey . '/' . $suffix;
        $payload = json_encode($message, JSON_UNESCAPED_SLASHES);
        $this->SendDebug('TX ' . $suffix, $payload, 0);

        $this->SendDataToParent(json_encode([
            'DataID'           => self::MQTT_TX,
            'PacketType'       => 3,
            'QualityOfService' => 0,
            'Retain'           => false,
            'Topic'            => $topic,
            'Payload'          => $this->EncodePayload($payload),
        ], JSON_UNESCAPED_SLASHES));
        return true;
    }

    // IPSModuleStrict: Nutzdaten im Datenfluss (auch „Payload“ beim MQTT Client) sind HEX-kodiert,
    // nicht UTF-8 wie bei IPSModule. Mit UTF-8 kamen gesendete Befehle beim Gerät nicht lesbar an.
    private function EncodePayload(string $payload): string
    {
        return bin2hex($payload);
    }

    private function DecodePayload(string $payload): string
    {
        // Regelfall: HEX-kodiert
        if ($payload !== '' && strlen($payload) % 2 === 0 && ctype_xdigit($payload)) {
            $decoded = (string) hex2bin($payload);
            if (json_decode($decoded) !== null) {
                return $decoded;
            }
        }
        // Rückfall für ältere Symcon-Versionen bzw. Klartext
        if (json_decode($payload) !== null) {
            return $payload;
        }
        return mb_convert_encoding($payload, 'ISO-8859-1', 'UTF-8');
    }

    // ---------------------------------------------------------------------
    // Hilfsfunktionen
    // ---------------------------------------------------------------------

    private function GetProductKey(): string
    {
        $key = trim($this->ReadPropertyString('ProductKey'));
        if ($key === '') {
            $key = self::PRODUCT_KEYS[$this->ReadPropertyString('Model')] ?? '';
        }
        return $key;
    }

    private function EnsureVariable(string $ident, string $name, int $type, string $pres, int $position, bool $writable): void
    {
        if ($this->VariableExists($ident)) {
            return;
        }
        $this->MaintainVariable($ident, $name, $type, $this->Presentation($pres), $position, true);
        if ($writable) {
            $this->EnableAction($ident);
        }
    }

    private function ValueOrZero(string $ident): int
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        return ($id !== false && $id > 0) ? (int) GetValue($id) : 0;
    }

    private function VariableExists(string $ident): bool
    {
        $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        return $id !== false && $id > 0;
    }

    /** Entfernt die Variablenprofile früherer Versionen (ZEND.*), sobald keine Variable sie mehr nutzt. */
    private function RemoveOldProfiles(): void
    {
        $old = ['ZEND.Watt', 'ZEND.OutputLimit', 'ZEND.SoC', 'ZEND.SocMax', 'ZEND.SocMin', 'ZEND.Minutes', 'ZEND.dBm',
                'ZEND.PackState', 'ZEND.PassMode', 'ZEND.HubState'];
        $existing = array_values(array_filter($old, 'IPS_VariableProfileExists'));
        if (count($existing) === 0) {
            return;
        }
        $used = [];
        foreach (IPS_GetVariableList() as $vid) {
            $v = IPS_GetVariable($vid);
            $used[$v['VariableProfile']] = true;
            $used[$v['VariableCustomProfile']] = true;
        }
        foreach ($existing as $profile) {
            if (!isset($used[$profile])) {
                IPS_DeleteVariableProfile($profile);
            }
        }
    }

    /**
     * Darstellungen der Variablen (Symcon 8/9) statt eigener Variablenprofile.
     */
    private function Presentation(string $key): array
    {
        $value = static function (string $suffix, string $icon, int $digits = 0): array {
            return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => $suffix, 'DIGITS' => $digits, 'ICON' => $icon];
        };
        $slider = static function (int $min, int $max, int $step, string $suffix, string $icon): array {
            return ['PRESENTATION' => VARIABLE_PRESENTATION_SLIDER, 'MIN' => $min, 'MAX' => $max, 'STEP_SIZE' => $step, 'SUFFIX' => $suffix, 'ICON' => $icon];
        };
        $enum = static function (array $options, string $icon): array {
            $list = [];
            foreach ($options as $v => [$text, $optIcon, $color]) {
                $list[] = ['Value' => $v, 'Caption' => $text, 'IconActive' => $optIcon !== '', 'IconValue' => $optIcon,
                    'ColorActive' => $color >= 0, 'Color' => $color];
            }
            return ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'ICON' => $icon, 'OPTIONS' => json_encode($list)];
        };
        $show = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        $max = max(100, $this->ReadPropertyInteger('MaxOutputPower'));

        switch ($key) {
            case 'soc':       return $value(' %', 'battery-half');
            case 'solar':     return $value(' W', 'solar-panel');
            case 'home':      return $value(' W', 'house');
            case 'charge':    return $value(' W', 'arrow-down');
            case 'discharge': return $value(' W', 'arrow-up');
            case 'battery':   return $value(' W', 'battery-full');
            case 'watt':      return $value(' W', 'bolt');
            case 'minutes':   return $value(' min', 'clock');
            case 'count':     return $value('', 'layer-group');
            case 'dbm':       return $value(' dBm', 'wifi');
            case 'kwh':       return $value(' kWh', 'solar-panel', 2);
            case 'temp':      return $value(' °C', 'temperature-half', 1);
            case 'volt':      return $value(' V', 'car-battery', 2);
            case 'ampere':    return $value(' A', 'wave-square', 1);
            case 'limit':     return $slider(0, $max, 10, ' W', 'gauge');
            case 'socMax':    return $slider(70, 100, 5, ' %', 'battery-full');
            case 'socMin':    return $slider(0, 50, 5, ' %', 'battery-quarter');
            case 'switch':    return ['PRESENTATION' => VARIABLE_PRESENTATION_SWITCH, 'ICON' => 'toggle-on'];
            case 'passMode':  return $enum([0 => ['Automatisch', 'wand-magic-sparkles', -1], 1 => ['Immer aus', 'circle-xmark', -1], 2 => ['Immer an', 'circle-check', -1]], 'shuffle');
            case 'packState': return $enum([0 => ['Ruhezustand', 'pause', -1], 1 => ['Laden', 'arrow-down', 0x2FBF71], 2 => ['Entladen', 'arrow-up', 0xF5A623], 3 => ['USV', 'plug', -1]], 'battery-half');
            case 'hubState':  return $enum([0 => ['Ausgabe stoppen, Standby', 'moon', -1], 1 => ['Ausgabe stoppen, ausschalten', 'power-off', -1]], 'power-off');
            case 'online':    return $show + ['ICON' => 'signal'];
            case 'bypass':    return $show + ['ICON' => 'shuffle'];
            case 'power':     return $show + ['ICON' => 'power-off'];
            case 'heat':      return $show + ['ICON' => 'fire'];
            case 'wifiOn':    return $show + ['ICON' => 'wifi'];
            case 'datetime':
                return defined('VARIABLE_PRESENTATION_DATE_TIME')
                    ? ['PRESENTATION' => VARIABLE_PRESENTATION_DATE_TIME, 'ICON' => 'clock']
                    : $show + ['ICON' => 'clock'];
        }
        return $show;
    }
}
