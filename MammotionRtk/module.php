<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/MammotionDevice.php';
require_once __DIR__ . '/../libs/MammotionTile.php';

//RTK-Referenzstation. Die Open API hat dafür keine eigenen Endpunkte: Die Station erscheint in der Geräteliste, die Geräteabfrage
//liefert nur Modell, Firmware und Online-Zustand (geprüft mit einer RTK300), die Fehlermeldungen lassen sich abfragen.
class MammotionRtk extends IPSModuleStrict
{
    use MammotionDevice;
    use MammotionTile;

    private const GUID_CLOUD = '{DF97F504-8ADE-4710-B12E-DCA702277C96}';

    private const STATUS_NO_DEVICE = 201;
    private const MIN_INTERVAL = 30;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('DeviceName', '');
        $this->RegisterPropertyInteger('PollInterval', 300);

        $this->RegisterTimer('PollTimer', 0, 'MAMR_Update($_IPS[\'TARGET\']);');
        $this->RegisterTile();
    }

    //Als Verbindung kommt nur die MammotionCloud infrage.
    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'require', 'moduleIDs' => [self::GUID_CLOUD]]);
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('PollTimer', 0);
        $this->MaintainVariable('Online', $this->Translate('Online'), VARIABLETYPE_BOOLEAN, $this->BooleanPresentation('Online', 'Offline'), 1, true);
        $this->MaintainVariable('Model', $this->Translate('Model'), VARIABLETYPE_STRING, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 2, true);
        $this->MaintainVariable('Firmware', $this->Translate('Firmware'), VARIABLETYPE_STRING, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 3, true);
        $this->UpdateTile(true);

        if ($this->ReadPropertyString('DeviceID') === '') {
            $this->SetStatus(self::STATUS_NO_DEVICE);
            return;
        }
        $this->SetStatus(IS_ACTIVE);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            //Erste Abfrage kurz nach dem Übernehmen, nicht im ApplyChanges selbst.
            $this->SetTimerInterval('PollTimer', 2000);
        }
    }

    //Holt Modell, Firmware und Online-Zustand der Station. Läuft per Timer im eingestellten Abstand.
    public function Update(): void
    {
        $interval = $this->ReadPropertyInteger('PollInterval');
        $this->SetTimerInterval('PollTimer', $interval > 0 ? max(self::MIN_INTERVAL, $interval) * 1000 : 0);
        if ($this->GetStatus() !== IS_ACTIVE) {
            return;
        }
        $detail = $this->FetchDetail();
        if ($detail === null) {
            return;
        }
        $this->SetValue('Online', (int) ($detail['online'] ?? 0) === 1);
        $this->SetValue('Model', (string) ($detail['model'] ?? ''));
        $this->SetValue('Firmware', (string) ($detail['version'] ?? ''));
        $this->UpdateTile($this->StoreImage($detail));
    }

    //Letzte Fehlermeldungen der Station als JSON-Liste mit Code, Beschreibung, Lösung und Zeitpunkt.
    public function GetErrorCodes(int $Count): string
    {
        $result = $this->Request(['Function' => 'SearchErrorCodes', 'Page' => 1, 'PageSize' => $Count]);
        return json_encode($result['data']['records'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
