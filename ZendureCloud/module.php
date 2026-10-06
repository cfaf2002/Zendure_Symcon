<?php

declare(strict_types=1);

/**
 * Zendure Cloud
 *
 * Splitter zwischen dem Symcon-MQTT-Client und den Zendure-Geräteinstanzen.
 * - holt mit dem Cloud-Key aus der Zendure-App die Geräteliste und die MQTT-Zugangsdaten
 * - richtet den übergeordneten MQTT-Client (inkl. Client Socket) passend ein
 * - reicht MQTT-Pakete transparent zwischen MQTT-Client und Geräten durch
 *
 * Autor: Armin Frohwerk
 */
class ZendureCloud extends IPSModuleStrict
{
    private const MQTT_TX = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';
    private const MQTT_RX = '{7F7632D9-FA40-4F38-8DEA-C83CD4325A32}';
    private const CONFIG_TX = '{E66E03E9-BA01-44EA-B561-88D3C300C517}';

    // Schlüssel für die Request-Signatur der Zendure-Cloud-API (aus der offiziellen Zendure-HA-Integration)
    private const SIGN_KEY = 'C*dafwArEOXK';
    private const CLIENT_ID = 'zenHa';

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('AppToken', '');
        $this->RegisterPropertyBoolean('AutoConfigureParent', true);
        $this->RegisterPropertyInteger('RefreshHours', 12);

        $this->RegisterAttributeString('DeviceList', '[]');
        $this->RegisterAttributeString('MqttInfo', '{}');

        $this->RegisterTimer('Refresh', 0, 'ZENDC_RefreshDevices($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetReceiveDataFilter('');

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            $this->RegisterMessage(0, IPS_KERNELSTARTED);
            return;
        }

        if (trim($this->ReadPropertyString('AppToken')) === '') {
            $this->SetTimerInterval('Refresh', 0);
            $this->SetStatus(104);
            return;
        }

        $this->SetTimerInterval('Refresh', max(1, $this->ReadPropertyInteger('RefreshHours')) * 3600 * 1000);
        $this->RefreshDevices();
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELSTARTED) {
            $this->UnregisterMessage(0, IPS_KERNELSTARTED);
            $this->ApplyChanges();
        }
    }

    // ---------------------------------------------------------------------
    // Öffentliche Funktionen
    // ---------------------------------------------------------------------

    /**
     * Fragt Geräteliste und MQTT-Zugangsdaten bei der Zendure-Cloud ab.
     */
    public function RefreshDevices(): bool
    {
        $token = trim($this->ReadPropertyString('AppToken'));
        if ($token === '') {
            $this->SetStatus(104);
            return false;
        }

        $decoded = base64_decode($token, true);
        if ($decoded === false || strpos($decoded, '.') === false) {
            $this->SendDebug('API', 'Cloud-Key konnte nicht dekodiert werden', 0);
            $this->SetStatus(201);
            return false;
        }
        $pos = strrpos($decoded, '.');
        $apiUrl = rtrim(substr($decoded, 0, $pos), '/');
        $appKey = substr($decoded, $pos + 1);
        if ($apiUrl === '' || $appKey === '' || stripos($apiUrl, 'https://') !== 0) {
            $this->SetStatus(201);
            return false;
        }

        $result = $this->ApiRequest($apiUrl . '/api/ha/deviceList', ['appKey' => $appKey]);
        if ($result === null) {
            $this->SetStatus(202);
            return false;
        }
        if (($result['code'] ?? 0) != 200 || empty($result['success'])) {
            $this->SendDebug('API', 'Abgelehnt: ' . json_encode($result), 0);
            $this->LogMessage('Zendure-Cloud hat die Anfrage abgelehnt: ' . ($result['msg'] ?? 'unbekannt'), KL_WARNING);
            $this->SetStatus(201);
            return false;
        }

        $data = $result['data'] ?? [];
        $devices = [];
        foreach ($data['deviceList'] ?? [] as $dev) {
            if (empty($dev['deviceKey']) || empty($dev['productKey'])) {
                continue;
            }
            $devices[] = [
                'name'       => (string) ($dev['deviceName'] ?? $dev['productModel'] ?? 'Zendure'),
                'model'      => (string) ($dev['productModel'] ?? ''),
                'productKey' => (string) $dev['productKey'],
                'deviceKey'  => (string) $dev['deviceKey'],
                'sn'         => (string) ($dev['snNumber'] ?? ''),
            ];
        }
        $this->WriteAttributeString('DeviceList', json_encode($devices));

        $mqtt = $data['mqtt'] ?? [];
        if (!empty($mqtt['url'])) {
            $url = preg_replace('#^[a-z]+://#i', '', (string) $mqtt['url']);
            $host = $url;
            $port = 1883;
            if (strpos($url, ':') !== false) {
                [$host, $p] = explode(':', $url, 2);
                $port = (int) $p;
            }
            $this->WriteAttributeString('MqttInfo', json_encode([
                'host'     => $host,
                'port'     => $port,
                'username' => (string) ($mqtt['username'] ?? ''),
                'password' => (string) ($mqtt['password'] ?? ''),
                'clientId' => (string) ($mqtt['clientId'] ?? ''),
            ]));
        } else {
            $this->LogMessage('Zendure-Cloud lieferte keine MQTT-Zugangsdaten', KL_WARNING);
        }

        $this->SendDebug('API', count($devices) . ' Gerät(e) gefunden', 0);
        $this->SetStatus(102);

        if ($this->ReadPropertyBoolean('AutoConfigureParent')) {
            $parentID = $this->GetParentID();
            if ($parentID > 0) {
                $this->UpdateParentConfiguration($parentID);
            }
        }
        return true;
    }

    /**
     * Legt bei Bedarf MQTT-Client und Client Socket an, verbindet sie und trägt die Zugangsdaten ein.
     */
    public function SetupMqttConnection(): string
    {
        $info = json_decode($this->ReadAttributeString('MqttInfo'), true);
        if (empty($info['host'])) {
            if (!$this->RefreshDevices()) {
                return 'Keine MQTT-Zugangsdaten von der Zendure-Cloud erhalten. Bitte Cloud-Key prüfen.';
            }
        }

        $parentID = $this->GetParentID();
        if ($parentID === 0) {
            $mqttGuid = $this->FindModuleByName('MQTT Client');
            if ($mqttGuid === '') {
                return 'Modul "MQTT Client" wurde in Symcon nicht gefunden.';
            }
            $parentID = IPS_CreateInstance($mqttGuid);
            IPS_SetName($parentID, 'Zendure Cloud MQTT');
            IPS_ConnectInstance($this->InstanceID, $parentID);
        }

        $socketID = IPS_GetInstance($parentID)['ConnectionID'];
        if ($socketID === 0) {
            $socketGuid = $this->FindModuleByName('Client Socket');
            if ($socketGuid === '') {
                return 'Modul "Client Socket" wurde in Symcon nicht gefunden.';
            }
            $socketID = IPS_CreateInstance($socketGuid);
            IPS_SetName($socketID, 'Zendure Cloud MQTT Socket');
            IPS_ConnectInstance($parentID, $socketID);
        }

        return $this->UpdateParentConfiguration($parentID);
    }

    /**
     * Zeigt die MQTT-Zugangsdaten an (für die manuelle Einrichtung).
     */
    public function GetConnectionInfo(): string
    {
        $info = json_decode($this->ReadAttributeString('MqttInfo'), true);
        if (empty($info['host'])) {
            return 'Noch keine Zugangsdaten vorhanden. Bitte zuerst "Geräte abrufen" ausführen.';
        }
        $lines = [
            'Server: ' . $info['host'],
            'Port: ' . $info['port'],
            'Client-ID: ' . $info['clientId'],
            'Benutzer: ' . $info['username'],
            'Passwort: ' . ($info['password'] !== '' ? '•••••••• (wird beim Einrichten automatisch eingetragen)' : '–'),
            '',
            'Abonnements:',
        ];
        foreach ($this->BuildTopics() as $t) {
            $lines[] = '  ' . $t;
        }
        return implode("\n", $lines);
    }

    public function GetDevices(): string
    {
        return $this->ReadAttributeString('DeviceList');
    }

    // ---------------------------------------------------------------------
    // Datenfluss
    // ---------------------------------------------------------------------

    public function ForwardData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (($data['DataID'] ?? '') === self::CONFIG_TX) {
            switch ($data['Function'] ?? '') {
                case 'Refresh':
                    $this->RefreshDevices();
                    return $this->ReadAttributeString('DeviceList');
                case 'GetDevices':
                default:
                    return $this->ReadAttributeString('DeviceList');
            }
        }

        if (!$this->HasActiveParent()) {
            $this->SendDebug('TX', 'Kein aktiver MQTT-Client verbunden', 0);
            return '';
        }
        $this->SendDebug('TX', $data['Topic'] ?? '', 0);
        return (string) $this->SendDataToParent($JSONString);
    }

    public function ReceiveData(string $JSONString): string
    {
        $this->SendDataToChildren($JSONString);
        return '';
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        $devices = json_decode($this->ReadAttributeString('DeviceList'), true) ?: [];
        $info = json_decode($this->ReadAttributeString('MqttInfo'), true);
        $parentID = $this->GetParentID();

        $status = [];
        $status[] = 'Gefundene Geräte: ' . count($devices);
        $status[] = 'MQTT-Server: ' . (empty($info['host']) ? '–' : $info['host'] . ':' . $info['port']);
        $status[] = 'MQTT-Client: ' . ($parentID > 0 ? IPS_GetName($parentID) . ' (#' . $parentID . ')' : 'nicht verbunden');

        array_unshift($form['actions'], ['type' => 'Label', 'caption' => implode("\n", $status)]);
        return (string) json_encode($form);
    }

    // ---------------------------------------------------------------------
    // Hilfsfunktionen
    // ---------------------------------------------------------------------

    private function ApiRequest(string $url, array $body): ?array
    {
        $timestamp = time();
        $nonce = (string) random_int(10000, 99999);

        $signParams = array_merge($body, ['timestamp' => $timestamp, 'nonce' => $nonce]);
        ksort($signParams, SORT_STRING);
        $bodyStr = '';
        foreach ($signParams as $k => $v) {
            $bodyStr .= $k . $v;
        }
        $sign = strtoupper(sha1(self::SIGN_KEY . $bodyStr . self::SIGN_KEY));

        $payload = json_encode($body);
        $this->SendDebug('API', 'POST ' . $url, 0);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'timestamp: ' . $timestamp,
                'nonce: ' . $nonce,
                'clientid: ' . self::CLIENT_ID,
                'sign: ' . $sign,
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->SendDebug('API', 'Fehler: ' . $error, 0);
            $this->LogMessage('Zendure-Cloud nicht erreichbar: ' . $error, KL_WARNING);
            return null;
        }
        $this->SendDebug('API', 'HTTP ' . $httpCode . ': ' . $this->MaskSecrets((string) $response), 0);

        $json = json_decode((string) $response, true);
        return is_array($json) ? $json : null;
    }

    private function MaskSecrets(string $text): string
    {
        return (string) preg_replace('/("password"\s*:\s*")[^"]*"/', '$1***"', $text);
    }

    private function BuildTopics(): array
    {
        $topics = [];
        foreach (json_decode($this->ReadAttributeString('DeviceList'), true) ?: [] as $dev) {
            $topics[] = '/' . $dev['productKey'] . '/' . $dev['deviceKey'] . '/#';
            $topics[] = 'iot/' . $dev['productKey'] . '/' . $dev['deviceKey'] . '/#';
        }
        return $topics;
    }

    private function GetParentID(): int
    {
        return (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
    }

    private function FindModuleByName(string $name): string
    {
        foreach (IPS_GetModuleList() as $guid) {
            $module = IPS_GetModule($guid);
            if (strcasecmp($module['ModuleName'], $name) === 0) {
                return $guid;
            }
        }
        return '';
    }

    /**
     * Trägt die Zugangsdaten in MQTT-Client und Client Socket ein.
     * Die Eigenschaftsnamen werden aus der vorhandenen Konfiguration ermittelt,
     * damit es über Symcon-Versionen hinweg funktioniert.
     */
    private function UpdateParentConfiguration(int $mqttID): string
    {
        $info = json_decode($this->ReadAttributeString('MqttInfo'), true);
        if (empty($info['host'])) {
            return 'Keine MQTT-Zugangsdaten vorhanden.';
        }

        $report = [];
        $changed = false;
        $config = json_decode(IPS_GetConfiguration($mqttID), true) ?: [];

        $wanted = [
            'clientId' => ['ClientID', 'ClientId', 'ClientIdentifier'],
            'username' => ['UserName', 'Username', 'User'],
            'password' => ['Password'],
        ];
        foreach ($wanted as $infoKey => $candidates) {
            $prop = $this->FirstExistingKey($config, $candidates);
            if ($prop === null) {
                $report[] = 'Eigenschaft für ' . $infoKey . ' am MQTT-Client nicht gefunden – bitte manuell eintragen.';
                continue;
            }
            if ((string) $config[$prop] !== (string) $info[$infoKey] && $info[$infoKey] !== '') {
                IPS_SetProperty($mqttID, $prop, $info[$infoKey]);
                $changed = true;
            }
        }

        $subProp = $this->FirstExistingKey($config, ['Subscriptions', 'Subscription']);
        if ($subProp !== null) {
            $current = json_decode((string) $config[$subProp], true);
            $topicKey = 'Topic';
            $qosKey = 'QoS';
            if (is_array($current) && isset($current[0]) && is_array($current[0])) {
                foreach (array_keys($current[0]) as $k) {
                    if (strcasecmp($k, 'topic') === 0) {
                        $topicKey = $k;
                    } elseif (strcasecmp($k, 'qos') === 0) {
                        $qosKey = $k;
                    }
                }
            }
            $subs = [];
            foreach ($this->BuildTopics() as $t) {
                $subs[] = [$topicKey => $t, $qosKey => 0];
            }
            $newValue = json_encode($subs, JSON_UNESCAPED_SLASHES);
            if (json_encode($current, JSON_UNESCAPED_SLASHES) !== $newValue) {
                IPS_SetProperty($mqttID, $subProp, $newValue);
                $changed = true;
            }
        } else {
            $report[] = 'Abonnements am MQTT-Client nicht gefunden – bitte Topics manuell eintragen.';
        }

        if ($changed) {
            IPS_ApplyChanges($mqttID);
            $report[] = 'MQTT-Client aktualisiert.';
        }

        $socketID = (int) IPS_GetInstance($mqttID)['ConnectionID'];
        if ($socketID > 0) {
            $sConfig = json_decode(IPS_GetConfiguration($socketID), true) ?: [];
            $sChanged = false;
            $set = ['Host' => $info['host'], 'Port' => (int) $info['port'], 'Open' => true];
            if ((int) $info['port'] === 8883) {
                $set['UseSSL'] = true;
            }
            foreach ($set as $prop => $value) {
                if (array_key_exists($prop, $sConfig) && $sConfig[$prop] !== $value) {
                    IPS_SetProperty($socketID, $prop, $value);
                    $sChanged = true;
                }
            }
            if ($sChanged) {
                IPS_ApplyChanges($socketID);
                $report[] = 'Client Socket aktualisiert.';
            }
        } else {
            $report[] = 'Am MQTT-Client hängt noch kein Client Socket.';
        }

        if (count($report) === 0) {
            $report[] = 'MQTT-Verbindung ist bereits aktuell.';
        }
        $text = implode("\n", $report);
        $this->SendDebug('MQTT-Setup', $text, 0);
        return $text;
    }

    private function FirstExistingKey(array $config, array $candidates): ?string
    {
        foreach ($candidates as $c) {
            if (array_key_exists($c, $config)) {
                return $c;
            }
        }
        return null;
    }
}
