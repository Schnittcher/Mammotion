<?php

declare(strict_types=1);

//Gemeinsame Teile der Geräteinstanzen (Mäher, RTK-Station): Anfrage an die MammotionCloud, Zustandsabfrage und Darstellung.
//Die Instanz braucht die Properties DeviceID und DeviceName.
trait MammotionDevice
{
    //Schnittstelle Gerät -> Cloud (siehe module.json)
    protected const GUID_TO_PARENT = '{33F5BD76-EACE-472E-AC52-C2328AD1E5B3}';

    //Einzige Stelle, die den Zustand beschafft. Ein lokaler Transport liefert später dasselbe Array.
    private function FetchDetail(): ?array
    {
        $result = $this->Request(['Function' => 'GetMower']);
        if (!($result['success'] ?? false) || !is_array($result['data'])) {
            return null;
        }
        $data = $result['data'];
        //Das Portal zeigt eine Liste mit einem Eintrag, die Spezifikation ein Objekt (Q14): beides annehmen.
        return array_is_list($data) ? ($data[0] ?? null) : $data;
    }

    //Sendet eine Anfrage an die Cloud und liefert die dekodierte Antwort, bei fehlender Verbindung ein Fehlerergebnis.
    private function Request(array $Request): array
    {
        if (!$this->IsCloudActive()) {
            return ['success' => false, 'message' => 'No active connection to the Mammotion cloud'];
        }
        $Request['DataID'] = self::GUID_TO_PARENT;
        $Request['DeviceID'] = $this->ReadPropertyString('DeviceID');
        $Request['DeviceName'] = $this->ReadPropertyString('DeviceName');
        $response = $this->SendDataToParent(json_encode($Request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $result = json_decode((string) $response, true);
        return is_array($result) ? $result : ['success' => false, 'message' => 'Invalid response'];
    }

    //HasActiveParent() prüft die ganze Kette bis zu einem Parent der Cloud. Hier zählt nur die Cloud-Instanz selbst.
    private function IsCloudActive(): bool
    {
        $parentID = (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
        return $parentID > 0 && IPS_GetInstance($parentID)['InstanceStatus'] === IS_ACTIVE;
    }

    //Legt das Produktbild des Geräts (Feld icon der Cloud) als Medienobjekt "Image" lokal ab. Geladen wird nur, wenn sich die Adresse
    //ändert; die Adresse steht dazu in der Info des Medienobjekts. Nur Adressen von mammotion.com werden geladen. Liefert true, wenn ein neues Bild abgelegt wurde.
    private function StoreImage(array $Detail): bool
    {
        $url = (string) ($Detail['icon'] ?? '');
        $host = (string) parse_url($url, PHP_URL_HOST);
        if ($url === '' || !str_starts_with($url, 'https://') || !preg_match('/(^|\.)mammotion\.com$/i', $host)) {
            return false;
        }
        $mediaID = @$this->GetIDForIdent('Image');
        if ($mediaID && IPS_GetObject($mediaID)['ObjectInfo'] === $url) {
            return false;
        }
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXFILESIZE => 3000000]);
        $data = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        if (!is_string($data) || $code !== 200 || @getimagesizefromstring($data) === false) {
            $this->SendDebug('Image', 'Download failed (HTTP ' . $code . ')', 0);
            return false;
        }
        if (!$mediaID) {
            $mediaID = IPS_CreateMedia(MEDIATYPE_IMAGE);
            IPS_SetParent($mediaID, $this->InstanceID);
            IPS_SetIdent($mediaID, 'Image');
            IPS_SetName($mediaID, $this->Translate('Product image'));
            IPS_SetMediaFile($mediaID, 'media/mammotion_' . $this->InstanceID . '.png', false);
        }
        IPS_SetMediaContent($mediaID, base64_encode($data));
        IPS_SetInfo($mediaID, $url);
        return true;
    }

    private function BooleanPresentation(string $True, string $False): array
    {
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'OPTIONS' => json_encode([
            $this->ValueOption(false, $this->Translate($False)),
            $this->ValueOption(true, $this->Translate($True)),
        ])];
    }

    private function ValueOption(mixed $Value, string $Caption): array
    {
        return [
            'Value'               => $Value,
            'Caption'             => $Caption,
            'IconActive'          => false,
            'IconValue'           => '',
            'ColorActive'         => false,
            'ColorValue'          => -1,
            'ContentColorActive'  => false,
            'ContentColorValue'   => -1,
        ];
    }
}
