<?php

declare(strict_types=1);

class MammotionConfigurator extends IPSModuleStrict
{
    private const GUID_MOWER = '{C8864B25-D486-48C5-89D9-0C031B439055}';
    private const GUID_RTK = '{FC93C45D-FBE1-48E7-84D5-D6351533BD49}';
    private const GUID_TO_PARENT = '{33F5BD76-EACE-472E-AC52-C2328AD1E5B3}';

    //Kennungen der Gruppenzeilen im Baum (müssen größer als 0 sein)
    private const GROUP_MOWERS = 1;
    private const GROUP_RTK = 2;

    public function Create(): void
    {
        parent::Create();
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        foreach ($form['actions'] as &$action) {
            if (($action['name'] ?? '') === 'Devices') {
                $action['values'] = $this->TreeValues();
            }
        }
        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    //Ein Baum mit den Gruppen Mäher und RTK-Stationen. Eine Gruppe erscheint nur, wenn sie Einträge hat.
    //Die Geräteliste der Cloud enthält auch Zubehör: RTK-Stationen bekommen ihre eigene Instanz und Gruppe.
    private function TreeValues(): array
    {
        $mowers = [];
        $stations = [];
        foreach ($this->ListDevices() as $device) {
            if (preg_match('/^\s*RTK/i', (string) ($device['model'] ?? '')) === 1) {
                $stations[] = $device;
            } else {
                $mowers[] = $device;
            }
        }

        $values = [];
        foreach ([
            [self::GROUP_MOWERS, 'Mowers', $this->DeviceRows($mowers, self::GUID_MOWER, self::GROUP_MOWERS)],
            [self::GROUP_RTK, 'RTK stations', $this->DeviceRows($stations, self::GUID_RTK, self::GROUP_RTK)],
        ] as [$id, $caption, $rows]) {
            if ($rows === []) {
                continue;
            }
            $values[] = ['id' => $id, 'name' => $this->Translate($caption), 'expanded' => true];
            array_push($values, ...$rows);
        }
        return $values;
    }

    //Die Geräte des Kontos. Beim Öffnen des Formulars einmal abgefragt, bei fehlender Verbindung bleibt die Liste leer.
    private function ListDevices(): array
    {
        if (!$this->IsCloudActive()) {
            return [];
        }
        $response = $this->SendDataToParent(json_encode(['DataID' => self::GUID_TO_PARENT, 'Function' => 'ListMowers']));
        $result = json_decode((string) $response, true);
        return is_array($result['data'] ?? null) ? $result['data'] : [];
    }

    private function DeviceRows(array $Devices, string $ModuleID, int $Group): array
    {
        $rows = [];
        foreach ($Devices as $device) {
            $deviceID = (string) ($device['id'] ?? '');
            $label = $this->DeviceLabel($device);
            $rows[] = [
                'parent'     => $Group,
                'name'       => $label,
                'model'      => (string) ($device['model'] ?? ''),
                'state'      => $this->Translate((int) ($device['online'] ?? 0) === 1 ? 'Online' : 'Offline'),
                'instanceID' => $this->FindDeviceInstance($deviceID, $ModuleID),
                'create'     => [
                    'moduleID'      => $ModuleID,
                    'configuration' => ['DeviceID' => $deviceID, 'DeviceName' => (string) ($device['name'] ?? '')],
                    'name'          => $label,
                ],
            ];
        }
        return $rows;
    }

    //Spitzname, sonst Gerätename
    private function DeviceLabel(array $Device): string
    {
        $nickname = (string) ($Device['nickname'] ?? '');
        return $nickname !== '' ? $nickname : (string) ($Device['name'] ?? '');
    }

    //HasActiveParent() prüft die ganze Kette bis zu einem Parent der Cloud. Hier zählt nur die Cloud-Instanz selbst.
    private function IsCloudActive(): bool
    {
        $parentID = (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
        return $parentID > 0 && IPS_GetInstance($parentID)['InstanceStatus'] === IS_ACTIVE;
    }

    private function FindDeviceInstance(string $DeviceID, string $ModuleID): int
    {
        $parentID = (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
        foreach (IPS_GetInstanceListByModuleID($ModuleID) as $instanceID) {
            if ((int) IPS_GetInstance($instanceID)['ConnectionID'] === $parentID && IPS_GetProperty($instanceID, 'DeviceID') === $DeviceID) {
                return $instanceID;
            }
        }
        return 0;
    }
}
