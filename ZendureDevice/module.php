<?php

declare(strict_types=1);

/**
 * Zendure SolarFlow Hub (Hub 1200 / Hub 2000)
 *
 * Liest die Werte des Hubs per MQTT (Zendure-Cloud oder lokaler Broker) und steuert ihn.
 *
 * Autor: Armin Frohwerk
 */
class ZendureDevice extends IPSModule
{
    private const MQTT_TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';

    private const PRODUCT_KEYS = [
        'Hub 1200' => '73bkTV',
        'Hub 2000' => 'A8yh63',
    ];

    /**
     * Bekannte Eigenschaften des Hubs.
     * ident => [Name, Typ, Profil, Position, Umrechnung, schreibbar]
     * Typ: 0 Boolean, 1 Integer, 2 Float. Umrechnung: Divisor oder Spezialfall.
     */
    private const PROPERTIES = [
        'electricLevel'   => ['Ladezustand', 1, 'ZEND.SoC', 10, 1, false],
        'solarInputPower' => ['PV-Leistung', 1, 'ZEND.Watt', 20, 1, false],
        'solarPower1'     => ['PV-Leistung Eingang 1', 1, 'ZEND.Watt', 21, 1, false],
        'solarPower2'     => ['PV-Leistung Eingang 2', 1, 'ZEND.Watt', 22, 1, false],
        'outputHomePower' => ['Ausgang zum Haus', 1, 'ZEND.Watt', 30, 1, false],
        'outputPackPower' => ['Ladeleistung Akku', 1, 'ZEND.Watt', 31, 1, false],
        'packInputPower'  => ['Entladeleistung Akku', 1, 'ZEND.Watt', 32, 1, false],
        'packState'       => ['Akkustatus', 1, 'ZEND.PackState', 40, 1, false],
        'remainOutTime'   => ['Restlaufzeit Entladen', 1, 'ZEND.Minutes', 41, 1, false],
        'remainInputTime' => ['Restzeit Laden', 1, 'ZEND.Minutes', 42, 1, false],
        'packNum'         => ['Anzahl Akkus', 1, '', 43, 1, false],
        'outputLimit'     => ['Ausgangsleistung (Limit)', 1, 'ZEND.OutputLimit', 50, 1, true],
        'inputLimit'      => ['Eingangsleistung (Limit)', 1, 'ZEND.OutputLimit', 51, 1, true],
        'socSet'          => ['Ladegrenze', 1, 'ZEND.SocMax', 52, 10, true],
        'minSoc'          => ['Entladegrenze', 1, 'ZEND.SocMin', 53, 10, true],
        'passMode'        => ['Bypass-Modus', 1, 'ZEND.PassMode', 54, 1, true],
        'autoRecover'     => ['Bypass automatisch zurücksetzen', 0, '~Switch', 55, 1, true],
        'buzzerSwitch'    => ['Signalton', 0, '~Switch', 56, 1, true],
        'pass'            => ['Bypass aktiv', 0, '', 60, 1, false],
        'masterSwitch'    => ['Hauptschalter', 0, '', 61, 1, false],
        'hubState'        => ['Verhalten bei leerem Akku', 1, 'ZEND.HubState', 62, 1, false],
        'inverseMaxPower' => ['Max. Wechselrichterleistung', 1, 'ZEND.Watt', 63, 1, false],
        'heatState'       => ['Akkuheizung aktiv', 0, '', 64, 1, false],
        'wifiState'       => ['WLAN verbunden', 0, '', 65, 1, false],
        'rssi'            => ['WLAN-Signal', 1, 'ZEND.dBm', 66, 1, false],
    ];

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Model', 'Hub 2000');
        $this->RegisterPropertyString('ProductKey', '');
        $this->RegisterPropertyString('DeviceKey', '');
        $this->RegisterPropertyString('SerialNumber', '');
        $this->RegisterPropertyInteger('UpdateInterval', 60);
        $this->RegisterPropertyInteger('MaxOutputPower', 1200);
        $this->RegisterPropertyBoolean('ShowPacks', true);

        $this->RegisterAttributeString('Packs', '[]');
        $this->RegisterAttributeInteger('MessageId', 0);

        $this->RegisterTimer('Poll', 0, 'ZEND_RequestUpdate($_IPS[\'TARGET\']);');
        // Gateway: "Zendure Cloud" (Cloud-Betrieb) oder direkt ein MQTT Server/Client (lokaler Betrieb)
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $this->RegisterProfiles();

        $this->RegisterVariableBoolean('Online', 'Online', '~Alert.Reversed', 0);
        $this->RegisterVariableInteger('LastUpdate', 'Letzte Meldung', '~UnixTimestamp', 1);
        $this->RegisterVariableInteger('BatteryPower', 'Akkuleistung (+ Laden / − Entladen)', 'ZEND.Watt', 33);
        $this->RegisterVariableInteger('DischargePower', 'Entladeleistung vorgeben', 'ZEND.OutputLimit', 49);
        $this->EnableAction('DischargePower');

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

    public function RequestAction($Ident, $Value)
    {
        switch ($Ident) {
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

    public function ReceiveData($JSONString)
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
        $this->SetValue('LastUpdate', time());
        if (!$this->GetValue('Online')) {
            $this->SetValue('Online', true);
        }

        $props = $json['properties'] ?? [];
        if (is_array($props)) {
            foreach ($props as $key => $value) {
                if (!isset(self::PROPERTIES[$key]) || !is_numeric($value)) {
                    continue;
                }
                [$name, $type, $profile, $position, $divisor, $writable] = self::PROPERTIES[$key];
                $this->EnsureVariable($key, $name, $type, $profile, $position, $writable);

                switch ($type) {
                    case 0:
                        $this->SetValue($key, (int) $value !== 0);
                        break;
                    case 1:
                        $this->SetValue($key, (int) round($value / $divisor));
                        break;
                    default:
                        $this->SetValue($key, (float) $value / $divisor);
                }
            }

            if (array_key_exists('outputPackPower', $props) || array_key_exists('packInputPower', $props)) {
                $charge = $this->ValueOrZero('outputPackPower');
                $discharge = $this->ValueOrZero('packInputPower');
                $this->SetValue('BatteryPower', $charge - $discharge);
            }
        }

        if ($this->ReadPropertyBoolean('ShowPacks') && !empty($json['packData']) && is_array($json['packData'])) {
            $this->HandlePacks($json['packData']);
        }
    }

    private function HandlePacks(array $packs): void
    {
        $known = json_decode($this->ReadAttributeString('Packs'), true) ?: [];

        foreach ($packs as $pack) {
            $sn = (string) ($pack['sn'] ?? '');
            if ($sn === '') {
                continue;
            }
            $index = array_search($sn, $known, true);
            if ($index === false) {
                $known[] = $sn;
                $index = count($known) - 1;
                $this->WriteAttributeString('Packs', json_encode($known));
            }
            $n = $index + 1;
            $prefix = 'Pack' . $n . '_';
            $label = 'Akku ' . $n . ' ';
            $pos = 100 + $n * 10;

            if (isset($pack['socLevel'])) {
                $this->EnsureVariable($prefix . 'SoC', $label . 'Ladezustand', 1, 'ZEND.SoC', $pos, false);
                $this->SetValue($prefix . 'SoC', (int) $pack['socLevel']);
            }
            if (isset($pack['maxTemp'])) {
                $this->EnsureVariable($prefix . 'Temp', $label . 'Temperatur', 2, '~Temperature', $pos + 1, false);
                $this->SetValue($prefix . 'Temp', round(((float) $pack['maxTemp'] - 2731) / 10, 1));
            }
            if (isset($pack['totalVol'])) {
                $this->EnsureVariable($prefix . 'Volt', $label . 'Spannung', 2, '~Volt', $pos + 2, false);
                $this->SetValue($prefix . 'Volt', round((float) $pack['totalVol'] / 100, 2));
            }
            if (isset($pack['batcur'])) {
                $cur = (int) $pack['batcur'];
                if ($cur >= 32768) {
                    $cur -= 65536;
                }
                $this->EnsureVariable($prefix . 'Current', $label . 'Strom', 2, '~Ampere', $pos + 3, false);
                $this->SetValue($prefix . 'Current', round($cur / 10, 1));
            }
            if (isset($pack['power'])) {
                $this->EnsureVariable($prefix . 'Power', $label . 'Leistung', 1, 'ZEND.Watt', $pos + 4, false);
                $this->SetValue($prefix . 'Power', (int) $pack['power']);
            }
        }
    }

    private function CheckOnline(): void
    {
        $last = $this->GetValue('LastUpdate');
        $timeout = max(10, $this->ReadPropertyInteger('UpdateInterval')) * 3 + 30;
        $online = $last > 0 && (time() - $last) <= $timeout;
        if ($this->GetValue('Online') !== $online) {
            $this->SetValue('Online', $online);
        }
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

        $messageId = $this->ReadAttributeInteger('MessageId') + 1;
        if ($messageId > 999999) {
            $messageId = 1;
        }
        $this->WriteAttributeInteger('MessageId', $messageId);

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

    private function EncodePayload(string $payload): string
    {
        return mb_convert_encoding($payload, 'UTF-8', 'ISO-8859-1');
    }

    private function DecodePayload(string $payload): string
    {
        $decoded = mb_convert_encoding($payload, 'ISO-8859-1', 'UTF-8');
        if (json_decode($decoded) === null && json_decode($payload) !== null) {
            $decoded = $payload;
        }
        // Fallback für hex-kodierte Payloads
        if (json_decode($decoded) === null && strlen($payload) % 2 === 0 && ctype_xdigit($payload)) {
            $decoded = (string) hex2bin($payload);
        }
        return $decoded;
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

    private function EnsureVariable(string $ident, string $name, int $type, string $profile, int $position, bool $writable): void
    {
        if ($this->VariableExists($ident)) {
            return;
        }
        $this->MaintainVariable($ident, $name, $type, $profile, $position, true);
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

    private function RegisterProfiles(): void
    {
        $max = max(100, $this->ReadPropertyInteger('MaxOutputPower'));

        $this->CreateIntegerProfile('ZEND.Watt', 'Electricity', '', ' W', 0, 0, 0);
        $this->CreateIntegerProfile('ZEND.OutputLimit', 'Electricity', '', ' W', 0, $max, 10);
        $this->CreateIntegerProfile('ZEND.SoC', 'Battery', '', ' %', 0, 100, 1);
        $this->CreateIntegerProfile('ZEND.SocMax', 'Battery', '', ' %', 70, 100, 5);
        $this->CreateIntegerProfile('ZEND.SocMin', 'Battery', '', ' %', 0, 50, 5);
        $this->CreateIntegerProfile('ZEND.Minutes', 'Clock', '', ' min', 0, 0, 0);
        $this->CreateIntegerProfile('ZEND.dBm', 'Intensity', '', ' dBm', -100, 0, 0);

        $this->CreateIntegerProfile('ZEND.PackState', 'Battery', '', '', 0, 3, 0, [
            [0, 'Ruhezustand', '', -1],
            [1, 'Laden', '', 0x00AA00],
            [2, 'Entladen', '', 0xFF8800],
            [3, 'USV', '', -1],
        ]);
        $this->CreateIntegerProfile('ZEND.PassMode', 'Shuffle', '', '', 0, 2, 0, [
            [0, 'Automatisch', '', -1],
            [1, 'Immer aus', '', -1],
            [2, 'Immer an', '', -1],
        ]);
        $this->CreateIntegerProfile('ZEND.HubState', 'Power', '', '', 0, 1, 0, [
            [0, 'Ausgabe stoppen, Standby', '', -1],
            [1, 'Ausgabe stoppen, ausschalten', '', -1],
        ]);
    }

    private function CreateIntegerProfile(string $name, string $icon, string $prefix, string $suffix, int $min, int $max, int $step, array $associations = []): void
    {
        if (!IPS_VariableProfileExists($name)) {
            IPS_CreateVariableProfile($name, 1);
        }
        IPS_SetVariableProfileIcon($name, $icon);
        IPS_SetVariableProfileText($name, $prefix, $suffix);
        IPS_SetVariableProfileValues($name, $min, $max, $step);
        foreach ($associations as [$value, $text, $aIcon, $color]) {
            IPS_SetVariableProfileAssociation($name, $value, $text, $aIcon, $color);
        }
    }
}
