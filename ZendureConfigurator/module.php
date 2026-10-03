<?php

declare(strict_types=1);

/**
 * Zendure Konfigurator
 *
 * Listet die Geräte des Zendure-Kontos und legt Geräteinstanzen an.
 *
 * Autor: Armin Frohwerk
 */
class ZendureConfigurator extends IPSModule
{
    private const CONFIG_TX = '{E66E03E9-BA01-44EA-B561-88D3C300C517}';
    private const CLOUD_GUID = '{AEE5084B-5CC2-4B5B-8BD2-62308EB3845B}';
    private const DEVICE_GUID = '{EEA9BD1E-4878-4A2D-9165-F23C8A4BA200}';

    public function Create()
    {
        parent::Create();
        $this->ConnectParent(self::CLOUD_GUID);
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // MQTT-Daten werden im Konfigurator nicht benötigt
        $this->SetReceiveDataFilter('ZENDURE_CONFIGURATOR_IGNORE');
    }

    public function ReceiveData($JSONString)
    {
        return '';
    }

    public function Refresh(): void
    {
        $this->Request('Refresh');
        $this->ReloadForm();
    }

    public function GetConfigurationForm()
    {
        $devices = $this->Request('GetDevices');

        $existing = [];
        foreach (IPS_GetInstanceListByModuleID(self::DEVICE_GUID) as $id) {
            $key = (string) IPS_GetProperty($id, 'DeviceKey');
            if ($key !== '') {
                $existing[$key] = $id;
            }
        }

        $values = [];
        foreach ($devices as $dev) {
            $instanceID = $existing[$dev['deviceKey']] ?? 0;
            unset($existing[$dev['deviceKey']]);
            $values[] = [
                'name'       => $dev['name'],
                'model'      => $dev['model'],
                'sn'         => $dev['sn'],
                'productKey' => $dev['productKey'],
                'deviceKey'  => $dev['deviceKey'],
                'instanceID' => $instanceID,
                'create'     => [
                    'moduleID'      => self::DEVICE_GUID,
                    'configuration' => [
                        'ProductKey'   => $dev['productKey'],
                        'DeviceKey'    => $dev['deviceKey'],
                        'SerialNumber' => $dev['sn'],
                        'Model'        => $dev['model'],
                    ],
                ],
            ];
        }

        // Instanzen, die (noch) nicht im Konto gefunden wurden
        foreach ($existing as $key => $id) {
            $values[] = [
                'name'       => IPS_GetName($id),
                'model'      => (string) IPS_GetProperty($id, 'Model'),
                'sn'         => (string) IPS_GetProperty($id, 'SerialNumber'),
                'productKey' => (string) IPS_GetProperty($id, 'ProductKey'),
                'deviceKey'  => $key,
                'instanceID' => $id,
            ];
        }

        $hint = $this->CloudStatusText();

        return json_encode([
            'elements' => [],
            'actions'  => [
                ['type' => 'Label', 'caption' => $hint],
                [
                    'type'     => 'Configurator',
                    'name'     => 'Devices',
                    'caption'  => 'Geräte',
                    'rowCount' => 8,
                    'add'      => false,
                    'delete'   => true,
                    'sort'     => ['column' => 'name', 'direction' => 'ascending'],
                    'columns'  => [
                        ['caption' => 'Name', 'name' => 'name', 'width' => 'auto'],
                        ['caption' => 'Modell', 'name' => 'model', 'width' => '160px'],
                        ['caption' => 'Seriennummer', 'name' => 'sn', 'width' => '180px'],
                        ['caption' => 'Product Key', 'name' => 'productKey', 'width' => '110px'],
                        ['caption' => 'Device Key', 'name' => 'deviceKey', 'width' => '130px'],
                    ],
                    'values' => $values,
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Geräteliste neu abrufen',
                    'onClick' => 'ZENDK_Refresh($id);',
                ],
            ],
        ]);
    }

    /**
     * Fragt die Zendure-Cloud-Instanz direkt ab (unabhängig davon, ob MQTT schon verbunden ist).
     */
    private function Request(string $function): array
    {
        $cloudID = $this->GetCloudID();
        if ($cloudID === 0) {
            return [];
        }
        try {
            if ($function === 'Refresh') {
                @ZENDC_RefreshDevices($cloudID);
            }
            $list = json_decode((string) @ZENDC_GetDevices($cloudID), true);
        } catch (Throwable $e) {
            $this->SendDebug('Request', $e->getMessage(), 0);
            return [];
        }
        return is_array($list) ? $list : [];
    }

    private function GetCloudID(): int
    {
        $parentID = (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID > 0 && IPS_GetInstance($parentID)['ModuleInfo']['ModuleID'] === self::CLOUD_GUID) {
            return $parentID;
        }
        return 0;
    }

    private function CloudStatusText(): string
    {
        $cloudID = $this->GetCloudID();
        if ($cloudID === 0) {
            return 'Keine Instanz "Zendure Cloud" als Gateway verbunden.';
        }
        switch (IPS_GetInstance($cloudID)['InstanceStatus']) {
            case 102:
                return 'Gefundene Geräte im Zendure-Konto:';
            case 104:
                return 'In der Instanz "Zendure Cloud" (#' . $cloudID . ') ist noch kein Cloud-Key eingetragen.';
            case 201:
                return 'Der Cloud-Key wurde von Zendure abgelehnt oder ist unvollständig – bitte in "Zendure Cloud" (#' . $cloudID . ') neu einfügen.';
            case 202:
                return 'Die Zendure-Cloud ist nicht erreichbar – Internetverbindung von Symcon prüfen.';
            default:
                return 'Status der Instanz "Zendure Cloud" (#' . $cloudID . '): ' . IPS_GetInstance($cloudID)['InstanceStatus'];
        }
    }
}
