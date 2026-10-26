<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/MammotionDevice.php';
require_once __DIR__ . '/../libs/MammotionTile.php';
require_once __DIR__ . '/../libs/MammotionLive.php';

class MammotionMower extends IPSModuleStrict
{
    use MammotionDevice;
    use MammotionTile;

    private const GUID_CLOUD = '{DF97F504-8ADE-4710-B12E-DCA702277C96}';

    private const MODE_POLLING = 0;
    private const MODE_PUSH = 1;

    private const STATUS_NO_DEVICE = 201;

    //Mindestabstand zwischen zwei durch SSE-Ereignisse ausgelösten Abfragen (Sekunden)
    private const EVENT_REFRESH_GAP = 10;

    //Zustand des Mähers laut Cloud (Feld status), als Zahl abgelegt
    private const STATES = [
        'Standby'     => 0,
        'Working'     => 1,
        'Mowing'      => 1,
        'Paused'      => 2,
        'TaskPaused'  => 2,
        'Mapping'     => 3,
        'Updating'    => 4,
        'Offline'     => 5,
        'Returning'   => 6,
        'Abnormal'    => 7,
    ];
    private const STATE_UNKNOWN = 8;

    //Befehle der Variable "Control" und die zugehörige Cloud-Aktion
    private const CONTROL_ACTIONS = [
        1 => 'CMD_START',
        2 => 'PAUSE',
        3 => 'RESUME',
        4 => 'STOP',
        5 => 'RETURN',
        6 => 'CANCEL_RETURN',
    ];

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('DeviceID', '');
        $this->RegisterPropertyString('DeviceName', '');
        $this->RegisterPropertyInteger('PollInterval', 60);
        $this->RegisterPropertyInteger('UpdateMode', self::MODE_POLLING);
        $this->RegisterPropertyBoolean('ReadWorkParams', true);

        $rows = [];
        foreach (MammotionLive::PROPERTIES as $property => $default) {
            $rows[] = ['Property' => $property, 'Subscribe' => $default];
        }
        $this->RegisterPropertyString('SubscribeProperties', json_encode($rows));

        $this->RegisterTimer('PollTimer', 0, 'MAMM_Update($_IPS[\'TARGET\']);');

        $this->RegisterTile();
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->SetTimerInterval('PollTimer', 0);
        $this->MaintainVariables();
        $this->UpdateTile(true);

        if ($this->ReadPropertyString('DeviceID') === '') {
            $this->SetStatus(self::STATUS_NO_DEVICE);
            return;
        }
        $this->SetStatus(IS_ACTIVE);

        if (IPS_GetKernelRunlevel() === KR_READY) {
            //Erste Abfrage kurz nach dem Übernehmen, nicht im ApplyChanges selbst.
            $this->SetTimerInterval('PollTimer', 2000);
            //Ein Wechsel des Modus (Abfrage oder Live-Aktualisierung) gilt sofort: Die Cloud schaltet die Live-Verbindung ein oder aus.
            $cloudID = (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
            if ($cloudID > 0 && IPS_GetInstance($cloudID)['ModuleInfo']['ModuleID'] === self::GUID_CLOUD) {
                MAMC_RenewSubscription($cloudID);
            }
        }
    }

    //Die Liste der Live-Properties zeigt die Namen: Symcon speichert aus einer Liste nur die bearbeitbaren Spalten, der Name
    //steht deshalb nur in der Anzeige. Die Zuordnung läuft über die Reihenfolge (MammotionLive::PROPERTIES).
    public function GetConfigurationForm(): string
    {
        $form = json_decode(file_get_contents(__DIR__ . '/form.json'), true);
        //Die Live-Aktualisierung gibt es nur beim LUBA 3 AWD: Bei anderen Modellen entfallen Modus und Property-Liste.
        //Das Modell kennt die Instanz erst nach der ersten Abfrage. Steht der Modus schon auf Live, bleiben die Felder sichtbar.
        $model = (string) $this->TileValue('Model');
        $liveAvailable = preg_match('/luba\s*3(?!\d)/i', $model) === 1 || $this->ReadPropertyInteger('UpdateMode') === self::MODE_PUSH;
        if (!$liveAvailable) {
            $form['elements'] = array_values(array_filter(
                $form['elements'],
                fn (array $element) => !in_array($element['name'] ?? '', ['UpdateMode', 'SubscribeProperties'], true)
            ));
            foreach ($form['elements'] as &$label) {
                if (($label['type'] ?? '') === 'Label') {
                    $label['caption'] = '0 switches polling off. Values between 1 and 14 are treated as 15 seconds.';
                }
            }
            unset($label);
        }
        foreach ($form['elements'] as &$element) {
            if (($element['name'] ?? '') === 'SubscribeProperties') {
                $flags = MammotionLive::Flags($this->ReadPropertyString('SubscribeProperties'));
                $values = [];
                foreach (array_keys(MammotionLive::PROPERTIES) as $index => $property) {
                    $values[] = ['Property' => $property, 'Subscribe' => $flags[$index]];
                }
                $element['values'] = $values;
            }
        }
        unset($element);
        return json_encode($form);
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        switch ($Ident) {
            case 'Control':
                $action = self::CONTROL_ACTIONS[(int) $Value] ?? null;
                if ($action === null) {
                    $this->SendDebug('RequestAction', 'Unknown control value', 0);
                    return;
                }
                $this->Command($action, '');
                $this->SetValue('Control', (int) $Value);
                break;
            case 'Task':
                $this->Command('START', (string) $Value);
                $this->SetValue('Task', (string) $Value);
                break;
            default:
                $this->SendDebug('RequestAction', 'Unknown ident ' . $Ident, 0);
        }
    }

    //Ereignis der Live-Verbindung (ruft die MammotionCloud auf). Das Ereignisformat ist nicht dokumentiert: Ein Ereignis, das
    //diesen Mäher betrifft, löst deshalb nur eine Abfrage über REST aus, statt den Inhalt selbst auszuwerten.
    //Liefert true, wenn das Ereignis zu diesem Mäher gehört.
    public function HandleEvent(string $Event): bool
    {
        if ($this->ReadPropertyInteger('UpdateMode') !== self::MODE_PUSH) {
            return false;
        }
        $deviceID = $this->ReadPropertyString('DeviceID');
        $deviceName = $this->ReadPropertyString('DeviceName');
        if ($deviceID === '' || (!str_contains($Event, $deviceID) && ($deviceName === '' || !str_contains($Event, $deviceName)))) {
            return false;
        }
        $this->SendDebug('Event', substr($Event, 0, 1000), 0);
        if (time() - (int) $this->GetBuffer('LastEventRefresh') >= self::EVENT_REFRESH_GAP) {
            $this->SetBuffer('LastEventRefresh', (string) time());
            $this->SetTimerInterval('PollTimer', 1000);
        }
        return true;
    }

    //Holt den Zustand des Mähers und setzt die Variablen. Läuft per Timer und nach jedem Befehl.
    public function Update(): void
    {
        $interval = max(15, $this->ReadPropertyInteger('PollInterval')) * 1000;
        $this->SetTimerInterval('PollTimer', $this->ReadPropertyInteger('PollInterval') > 0 ? $interval : 0);
        if ($this->GetStatus() !== IS_ACTIVE) {
            return;
        }

        $detail = $this->FetchDetail();
        if ($detail === null) {
            return;
        }
        $this->ApplyDetail($detail);
        $this->UpdateTile($this->StoreImage($detail));

        $count = (int) $this->GetBuffer('UpdateCount');
        $this->SetBuffer('UpdateCount', (string) ($count + 1));
        $online = (int) ($detail['online'] ?? 0) === 1;
        //Plan und Arbeitsparameter ändern sich selten: nicht bei jeder Abfrage holen.
        if ($online && $count % 30 === 0) {
            $this->UpdatePlan();
        }
        if ($online && $count % 5 === 0 && $this->ReadPropertyBoolean('ReadWorkParams')) {
            $this->UpdateWorkParams();
        }
    }

    public function UpdatePlan(): void
    {
        $result = $this->Request(['Function' => 'GetPlan']);
        if (!($result['success'] ?? false) || !is_array($result['data'])) {
            return;
        }
        $options = [];
        foreach ($result['data'] as $task) {
            $name = (string) ($task['taskName'] ?? '');
            if ($name !== '') {
                $options[$name] = $name;
            }
        }
        $this->MaintainVariable('Task', $this->Translate('Start task'), VARIABLETYPE_STRING, $this->EnumerationPresentation($options, 'Calendar'), 20, true);
        $this->EnableAction('Task');
        $this->UpdateTile();
    }

    public function UpdateWorkParams(): void
    {
        $result = $this->Request(['Function' => 'GetWorkParams']);
        $params = $result['data'] ?? null;
        if (!($result['success'] ?? false) || !is_array($params)) {
            return;
        }
        //Die Cloud beantwortet die erste Abfrage oft nur mit einer Zustellbestätigung und lauter Nullen: dann nichts überschreiben.
        $values = array_filter($params, fn ($key) => !in_array($key, ['commandResult', 'resultMessage', 'visualHashs'], true), ARRAY_FILTER_USE_KEY);
        if (array_sum(array_map('floatval', $values)) == 0.0) {
            return;
        }
        foreach ($this->WorkParamVariables() as $ident => $definition) {
            if (isset($params[$definition['Field']])) {
                $this->SetValue($ident, $params[$definition['Field']]);
            }
        }
    }

    //Sendet einen Befehl: CMD_START, START (mit Aufgabenname), PAUSE, RESUME, STOP, RETURN, CANCEL_RETURN.
    public function Command(string $Action, string $TaskName): bool
    {
        $result = $this->Request(['Function' => 'SendAction', 'Action' => $Action, 'TaskName' => $TaskName]);
        $this->SendDebug('Command', $Action . ' ' . $TaskName . ' -> ' . json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);
        if (!($result['success'] ?? false)) {
            $message = sprintf('Command %s failed: %s', $Action, (string) ($result['message'] ?? ''));
            //Mähen ohne Aufgabe lehnt die Cloud bei manchen Modellen ab (gemeldet beim Yuka Mini 600: "Parameter ist nicht richtig").
            if ($Action === 'CMD_START') {
                $message .= '. ' . $this->Translate('This model may not accept mowing without a task. Start a task instead.');
            }
            $this->LogMessage($message, KL_WARNING);
            return false;
        }
        //Der Mäher meldet den neuen Zustand erst nach einigen Sekunden.
        $this->SetTimerInterval('PollTimer', 5000);
        return true;
    }

    //Letzte Fehlermeldungen des Mähers als JSON-Liste (Code, Beschreibung, Lösung, Zeitpunkt).
    public function GetErrorCodes(int $Count): string
    {
        $result = $this->Request(['Function' => 'SearchErrorCodes', 'Page' => 1, 'PageSize' => $Count]);
        return json_encode($result['data']['records'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    //Schaltet den lokalen Zugang (HA Local Network) des Mähers ein oder aus. Voraussetzung für die lokale Verbindung.
    public function SetLocalAccess(bool $Enabled): string
    {
        $result = $this->Request(['Function' => 'SaveLocalNetwork', 'Enabled' => $Enabled]);
        return $this->DescribeLocalAccess($result);
    }

    public function GetLocalAccess(): string
    {
        return $this->DescribeLocalAccess($this->Request(['Function' => 'QueryLocalNetwork']));
    }

    //Fordert die Karten- und Materialdaten des Mähers an. Die Daten kommen per SSE (nur LUBA 3 AWD).
    public function FetchMaterial(): bool
    {
        $result = $this->Request(['Function' => 'FetchMaterial']);
        return (bool) ($result['success'] ?? false);
    }

    private function ApplyDetail(array $Detail): void
    {
        $online = (int) ($Detail['online'] ?? 0) === 1;
        $this->SetValue('Online', $online);
        $state = self::STATE_UNKNOWN;
        foreach (self::STATES as $name => $value) {
            if (strcasecmp($name, (string) ($Detail['status'] ?? '')) === 0) {
                $state = $value;
            }
        }
        $rawStatus = (string) ($Detail['status'] ?? '');
        //Jede Abfrage steht im Debug-Fenster, damit sich Zustandswechsel (zum Beispiel die Ankunft an der Ladestation) nachvollziehen lassen.
        $this->SendDebug('Update', sprintf('status=%s charge=%s battery=%s online=%d', $rawStatus, (string) ($Detail['chargeStatus'] ?? ''), (string) ($Detail['batteryLevel'] ?? ''), $online ? 1 : 0), 0);
        if ($state === self::STATE_UNKNOWN) {
            //Die Doku nennt nicht alle Werte der Cloud: Der Wert steht im Debug-Fenster, damit er sich zuordnen lässt.
            $this->SendDebug('Status', sprintf('Unknown status "%s" (charge status %s)', $rawStatus, (string) ($Detail['chargeStatus'] ?? '')), 0);
        }
        $this->SetValue('Status', $state);
        if (isset($Detail['batteryLevel'])) {
            $this->SetValue('Battery', (int) $Detail['batteryLevel']);
        }
        if (isset($Detail['chargeStatus'])) {
            //Die Doku kennt 0 (lädt nicht) und 1 (lädt). Gemessen wurde zusätzlich 2 (LUBA 2 am Dock bei 96 bis 97 %), 1 auch bei vollem Akku
            //am Dock (LUBA 2 und Yuka Mini 600). Als "lädt" gilt deshalb jeder Wert über 0, so wie die Cloud ihn meldet.
            $this->SetValue('Charging', (int) $Detail['chargeStatus'] > 0);
        }
        $this->SetValue('Model', (string) ($Detail['model'] ?? ''));
        $this->SetValue('Firmware', (string) ($Detail['version'] ?? ''));
        $network = is_array($Detail['network'] ?? null) ? $Detail['network'] : [];
        $this->SetValue('NetworkType', (int) ($network['usedNetwork'] ?? 0));
        $this->SetValue('WifiRssi', (int) ($network['wifiRssi'] ?? 0));
        $this->SetValue('WifiIP', (string) ($network['wifiIp'] ?? ''));
        $this->SetValue('CellularRssi', (int) ($network['cellularRssi'] ?? 0));
    }

    private function DescribeLocalAccess(array $Result): string
    {
        if (!($Result['success'] ?? false)) {
            return sprintf($this->Translate('Request failed: %s'), (string) ($Result['message'] ?? ''));
        }
        $deviceName = $this->ReadPropertyString('DeviceName');
        foreach ($Result['data'] ?? [] as $row) {
            if (($row['deviceName'] ?? '') !== $deviceName) {
                continue;
            }
            $dispatch = [0 => 'pending', 1 => 'delivered', 2 => 'failed', 4 => 'not supported'][(int) ($row['dispatchStatus'] ?? 0)] ?? 'unknown';
            $text = sprintf($this->Translate('Local access: %s, configuration %s'), $this->Translate(!empty($row['enabled']) ? 'enabled' : 'disabled'), $this->Translate($dispatch));
            if (!empty($row['failReason'])) {
                $text .= ' (' . $row['failReason'] . ')';
            }
            return $text;
        }
        return $this->Translate('No local access configured for this mower.');
    }

    private function MaintainVariables(): void
    {
        $this->MaintainVariable('Online', $this->Translate('Online'), VARIABLETYPE_BOOLEAN, $this->BooleanPresentation('Online', 'Offline'), 1, true);
        $this->MaintainVariable('Status', $this->Translate('Status'), VARIABLETYPE_INTEGER, $this->ValueOptions([
            0 => 'Standby', 1 => 'Mowing', 2 => 'Paused', 3 => 'Mapping', 4 => 'Updating', 5 => 'Offline', 6 => 'Returning to dock', 7 => 'Error', 8 => 'Unknown',
        ]), 2, true);
        $this->MaintainVariable('Battery', $this->Translate('Battery'), VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' %'], 3, true);
        $this->MaintainVariable('Charging', $this->Translate('Charging'), VARIABLETYPE_BOOLEAN, $this->BooleanPresentation('Charging', 'Not charging'), 4, true);
        $this->MaintainVariable('Control', $this->Translate('Control'), VARIABLETYPE_INTEGER, $this->EnumerationPresentation([
            1 => 'Start mowing', 2 => 'Pause', 3 => 'Resume', 4 => 'Stop', 5 => 'Return to dock', 6 => 'Cancel return',
        ], 'Gear', true), 10, true);
        $this->EnableAction('Control');
        $this->MaintainVariable('Model', $this->Translate('Model'), VARIABLETYPE_STRING, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 30, true);
        $this->MaintainVariable('Firmware', $this->Translate('Firmware'), VARIABLETYPE_STRING, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 31, true);
        $this->MaintainVariable('NetworkType', $this->Translate('Network'), VARIABLETYPE_INTEGER, $this->ValueOptions([0 => 'Unknown', 1 => 'Wi-Fi', 2 => 'Cellular']), 32, true);
        $this->MaintainVariable('WifiIP', $this->Translate('Wi-Fi IP address'), VARIABLETYPE_STRING, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION], 33, true);
        $this->MaintainVariable('WifiRssi', $this->Translate('Wi-Fi signal'), VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' dBm'], 34, true);
        $this->MaintainVariable('CellularRssi', $this->Translate('Cellular signal'), VARIABLETYPE_INTEGER, ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'SUFFIX' => ' dBm'], 35, true);

        $readParams = $this->ReadPropertyBoolean('ReadWorkParams');
        $position = 40;
        foreach ($this->WorkParamVariables() as $ident => $definition) {
            $this->MaintainVariable($ident, $this->Translate($definition['Name']), $definition['Type'], $definition['Presentation'], $position++, $readParams);
        }
        //Die Aufgabenliste legt UpdatePlan() an. Ohne Plan bleibt die Variable weg.
    }

    //Arbeitsparameter laut Schema workParam der Cloud (GET /v1/mower/{id}/work-params). Einheiten nennt die Spezifikation nicht.
    private function WorkParamVariables(): array
    {
        $value = ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION];
        return [
            'JobContent'               => ['Field' => 'jobContent', 'Name' => 'Operation mode', 'Type' => VARIABLETYPE_INTEGER, 'Presentation' => $this->ValueOptions([8 => 'Mowing', 10 => 'Collecting', 12 => 'Mowing and collecting'])],
            'ChannelMode'              => ['Field' => 'channelMode', 'Name' => 'Path pattern', 'Type' => VARIABLETYPE_INTEGER, 'Presentation' => $this->ValueOptions([0 => 'Single bow', 1 => 'Cross', 2 => 'Zoned bow', 3 => 'No bow'])],
            'ChannelWidth'             => ['Field' => 'channelWidth', 'Name' => 'Path spacing', 'Type' => VARIABLETYPE_INTEGER, 'Presentation' => $value],
            'Toward'                   => ['Field' => 'toward', 'Name' => 'Working direction', 'Type' => VARIABLETYPE_INTEGER, 'Presentation' => $value],
            'TowardMode'               => ['Field' => 'towardMode', 'Name' => 'Angle reference', 'Type' => VARIABLETYPE_INTEGER, 'Presentation' => $this->ValueOptions([0 => 'Relative', 1 => 'Absolute', 2 => 'Random'])],
            'TowardIncludedAngle'      => ['Field' => 'towardIncludedAngle', 'Name' => 'Double bow angle', 'Type' => VARIABLETYPE_INTEGER, 'Presentation' => $value],
            'KnifeHeight'              => ['Field' => 'knifeHeight', 'Name' => 'Blade height', 'Type' => VARIABLETYPE_INTEGER, 'Presentation' => $value],
            'Speed'                    => ['Field' => 'speed', 'Name' => 'Working speed', 'Type' => VARIABLETYPE_INTEGER, 'Presentation' => $value],
            'UltraWave'                => ['Field' => 'ultraWave', 'Name' => 'Obstacle avoidance', 'Type' => VARIABLETYPE_INTEGER, 'Presentation' => $this->ValueOptions([0 => 'Slow touch', 1 => 'Slow touch', 10 => 'No touch', 11 => 'No touch, stay on lawn'])],
            'EdgeMode'                 => ['Field' => 'edgeMode', 'Name' => 'Edge rounds', 'Type' => VARIABLETYPE_INTEGER, 'Presentation' => $value],
            'RideBoundaryDistance'     => ['Field' => 'rideBoundaryDistance', 'Name' => 'Edge overlap', 'Type' => VARIABLETYPE_FLOAT, 'Presentation' => $value],
            'BoundaryZigzagOrder'      => ['Field' => 'boundaryZigzagOrder', 'Name' => 'Path order', 'Type' => VARIABLETYPE_INTEGER, 'Presentation' => $this->ValueOptions([0 => 'Edge first', 1 => 'Bow first'])],
            'ForbiddenAreaCircleTimes' => ['Field' => 'forbiddenAreaCircleTimes', 'Name' => 'Obstacle perimeter rounds', 'Type' => VARIABLETYPE_INTEGER, 'Presentation' => $value],
            'DumpPeriodSqm'            => ['Field' => 'dumpPeriodSqm', 'Name' => 'Grass collection frequency', 'Type' => VARIABLETYPE_INTEGER, 'Presentation' => $value],
        ];
    }

    //Anzeige eines Zahlenwerts als Text, zum Beispiel Zustand oder Betriebsart. Bei Ganzzahlen kennt die Wertanzeige kein OPTIONS:
    //der Text kommt über je ein Intervall pro Wert (geprüft auf Symcon 9.1).
    private function ValueOptions(array $Captions): array
    {
        $intervals = [];
        foreach ($Captions as $value => $caption) {
            $intervals[] = [
                'IntervalMinValue' => $value,
                'IntervalMaxValue' => $value,
                'ConstantActive'   => true,
                'ConstantValue'    => $this->Translate($caption),
            ];
        }
        return ['PRESENTATION' => VARIABLE_PRESENTATION_VALUE_PRESENTATION, 'INTERVALS_ACTIVE' => true, 'INTERVALS' => json_encode($intervals)];
    }

    //Auswahl zum Bedienen. Beschriftungen von $Captions (Wert => Text) werden übersetzt, wenn $Translate gesetzt ist.
    private function EnumerationPresentation(array $Captions, string $Icon, bool $Translate = false): array
    {
        $options = [];
        foreach ($Captions as $value => $caption) {
            $options[] = [
                'Value'      => $value,
                'Caption'    => $Translate ? $this->Translate($caption) : (string) $caption,
                'IconActive' => false,
                'IconValue'  => '',
                'Color'      => -1,
            ];
        }
        return ['PRESENTATION' => VARIABLE_PRESENTATION_ENUMERATION, 'ICON' => $Icon, 'LAYOUT' => 1, 'OPTIONS' => json_encode($options)];
    }
}
