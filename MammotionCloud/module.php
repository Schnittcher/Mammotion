<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/MammotionApi.php';
require_once __DIR__ . '/../libs/MammotionLive.php';

class MammotionCloud extends IPSModuleStrict
{
    //Schnittstelle Gerät -> Cloud (siehe module.json)
    private const GUID_TO_PARENT = '{33F5BD76-EACE-472E-AC52-C2328AD1E5B3}';
    private const GUID_SSE_CLIENT = '{2FADB4B7-FDAB-3C64-3E2C-068A4809849A}';
    private const GUID_VIRTUAL_IO = '{6179ED6A-FC31-413C-BB8E-1204150CF376}';
    private const GUID_MOWER = '{C8864B25-D486-48C5-89D9-0C031B439055}';

    private const SSE_URL = 'https://api-open.mammotion.com/developer-sse/api/client/sse';
    //Eine Subscription gilt 10 Minuten. Erneuert wird davor, danach liefert der Server keine Daten mehr.
    private const SUBSCRIPTION_INTERVAL_MS = 480000;
    private const SUBSCRIPTION_BATCH = 20;

    private const STATUS_NO_CREDENTIALS = 201;
    private const STATUS_AUTH_FAILED = 202;

    public function Create(): void
    {
        parent::Create();

        $this->RegisterPropertyString('ClientID', '');
        $this->RegisterPropertyString('ClientSecret', '');
        $this->RegisterPropertyString('Language', 'de-DE');
        $this->RegisterPropertyString('RedirectUri', '');

        $this->RegisterTimer('SubscriptionTimer', 0, 'MAMC_RenewSubscription($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges(): void
    {
        parent::ApplyChanges();

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);
        $this->SetTimerInterval('SubscriptionTimer', 0);
        //Neue Zugangsdaten gelten nur mit neuem Token, ein erneutes Übernehmen erlaubt nach einer Verdrängung auch die Live-Verbindung wieder.
        $this->ClearToken();
        $this->SetBuffer('SseReplaced', '');
        $this->SetBuffer('NoSseNotified', '');

        if (IPS_GetKernelRunlevel() !== KR_READY) {
            return;
        }

        $this->EnableVirtualIo();
        $parentID = $this->GetSseParentID();
        if ($parentID > 0) {
            $this->RegisterMessage($parentID, IM_CHANGESTATUS);
        }

        if ($this->ReadPropertyString('ClientID') === '' || $this->ReadPropertyString('ClientSecret') === '') {
            $this->SetStatus(self::STATUS_NO_CREDENTIALS);
            $this->ConfigureSse(false, []);
            return;
        }

        try {
            $this->GetAccessToken();
        } catch (MammotionApiException $e) {
            $this->SendDebug('ApplyChanges', 'Token: ' . $e->getMessage(), 0);
            return;
        }

        if ($parentID > 0) {
            $this->SetTimerInterval('SubscriptionTimer', self::SUBSCRIPTION_INTERVAL_MS);
            $this->RenewSubscription();
        }
    }

    public function MessageSink(int $TimeStamp, int $SenderID, int $Message, array $Data): void
    {
        if ($Message === IPS_KERNELMESSAGE && $Data[0] === KR_READY) {
            $this->ApplyChanges();
            return;
        }
        //Sobald die SSE-Verbindung steht, muss subscribt werden: ohne Subscription kommen keine Daten.
        if ($Message === IM_CHANGESTATUS && $SenderID === $this->GetSseParentID() && $Data[0] === IS_ACTIVE) {
            $this->RenewSubscription();
        }
    }

    //Die Verbindung zum SSE-Client gehört zum Splitter, bleibt aber leer und inaktiv, solange kein Mäher im Live-Modus läuft.
    //Daten vom SSE-Client als {"Data":"<Text>","DataID":…,"Event":…,"ID":…,"Retry":…}. Kommentar- und Heartbeat-Zeilen liefert der Client nicht.
    //Das Format der Ereignisse selbst ist nicht dokumentiert: Der Text geht unverändert an die Mäher, die ihn selbst zuordnen.
    public function ReceiveData(string $JSONString): string
    {
        $this->SendDebug('SSE', substr($JSONString, 0, 2000), 0);
        $frame = json_decode($JSONString, true);
        $payload = is_array($frame) ? (string) ($frame['Data'] ?? $frame['Buffer'] ?? '') : '';
        if ($payload === '') {
            return '';
        }
        $event = json_decode($payload, true);
        if (is_array($event) && ($event['pushTag'] ?? '') === 'sc' && (string) ($event['pushType'] ?? '') === '1') {
            //Eine andere SSE-Verbindung desselben Developer-Kontos hat diese verdrängt. Nicht neu verbinden, sonst verdrängen sich beide endlos.
            //Die Cloud bleibt aktiv (Abfragen und Befehle laufen weiter), nur die Live-Verbindung wird abgeschaltet.
            $this->LogMessage('SSE connection was replaced by another connection of the same developer account. Live updates are switched off until the settings are applied again.', KL_WARNING);
            $this->SetBuffer('SseReplaced', '1');
            $this->SetTimerInterval('SubscriptionTimer', 0);
            $this->ConfigureSse(false, []);
            return '';
        }
        foreach ($this->GetMowers() as $mowerID) {
            MAMM_HandleEvent($mowerID, $payload);
        }
        return '';
    }

    //Mögliche Verbindungen: Ohne Live-Aktualisierung genügt ein Virtual IO (immer aktiv, tut nichts, hier schon auf aktiv vorbelegt), für den
    //LUBA 3 AWD der SSE-Client. Ein Parent ist nötig, ein optionaler existiert in Symcon nicht. Ein inaktiver Parent würde in der Konsole als
    //"Instanz nicht aktiv" angezeigt, deshalb ist der SSE-Client nicht die Vorgabe.
    public function GetCompatibleParents(): string
    {
        return json_encode(['type' => 'require', 'modules' => [
            ['moduleID' => self::GUID_VIRTUAL_IO, 'configuration' => ['Open' => true]],
            ['moduleID' => self::GUID_SSE_CLIENT],
        ]]);
    }

    //Datenfluss von den Geräten. Jede Funktion liefert JSON {"success":bool,"data":...} oder {"success":false,"message":...}.
    public function ForwardData(string $JSONString): string
    {
        $data = json_decode($JSONString, true);
        if (!is_array($data) || ($data['DataID'] ?? '') !== self::GUID_TO_PARENT) {
            return $this->Failure('Invalid request');
        }
        $deviceID = (string) ($data['DeviceID'] ?? '');
        $deviceName = (string) ($data['DeviceName'] ?? '');
        return match ($data['Function'] ?? '') {
            'GetMower'          => $this->GetMower($deviceID),
            'SendAction'        => $this->SendAction($deviceID, (string) ($data['Action'] ?? ''), (string) ($data['TaskName'] ?? '')),
            'GetWorkParams'     => $this->GetWorkParams($deviceID),
            'GetPlan'           => $this->GetPlan($deviceID),
            'SearchErrorCodes'  => $this->SearchErrorCodes($deviceID, (int) ($data['Page'] ?? 1), (int) ($data['PageSize'] ?? 10)),
            'FetchMaterial'     => $this->FetchMaterial($deviceID),
            'SaveLocalNetwork'  => $this->SaveLocalNetwork($deviceName, (bool) ($data['Enabled'] ?? false)),
            'QueryLocalNetwork' => $this->QueryLocalNetwork($deviceName),
            'ListMowers'        => $this->ListMowers(),
            default             => $this->Failure('Unknown function'),
        };
    }

    public function TestConnection(): string
    {
        if ($this->ReadPropertyString('ClientID') === '' || $this->ReadPropertyString('ClientSecret') === '') {
            return $this->Translate('Client ID and client secret are required.');
        }
        try {
            $this->GetAccessToken(true);
            $mowers = $this->Call('GET', '/v1/mowers');
        } catch (MammotionApiException $e) {
            return sprintf($this->Translate('Connection failed: %s'), $e->getMessage());
        }
        return sprintf($this->Translate('Connection successful, %d mower(s) found.'), is_array($mowers) ? count($mowers) : 0);
    }

    public function ListMowers(): string
    {
        return $this->Execute(fn () => $this->Call('GET', '/v1/mowers'));
    }

    public function GetMower(string $DeviceID): string
    {
        return $this->Execute(fn () => $this->Call('GET', '/v1/mower/' . rawurlencode($DeviceID)));
    }

    /**
     * Aktionen: CMD_START (sofort mähen, ohne Plan), START (Aufgabe, braucht $TaskName), PAUSE, RESUME, STOP, RETURN, CANCEL_RETURN.
     */
    public function SendAction(string $DeviceID, string $Action, string $TaskName): string
    {
        $allowed = ['CMD_START', 'START', 'PAUSE', 'RESUME', 'STOP', 'RETURN', 'CANCEL_RETURN'];
        if (!in_array($Action, $allowed, true)) {
            return $this->Failure('Unknown action');
        }
        if ($Action === 'START' && $TaskName === '') {
            return $this->Failure('Task name is required for START');
        }
        $body = ['deviceId' => $DeviceID, 'action' => $Action];
        if ($Action === 'START') {
            $body['params'] = ['taskName' => $TaskName];
        }
        return $this->Execute(function () use ($body)
        {
            $result = $this->Call('POST', '/v1/mower/action', $body);
            //Der Befehl wurde angenommen, ausgeführt ist er damit nicht: commandResult meldet nur die Zustellung.
            if (is_array($result) && ($result['commandResult'] ?? true) === false) {
                throw new MammotionApiException((string) ($result['resultMessage'] ?? 'Command refused'));
            }
            return $result;
        });
    }

    public function GetWorkParams(string $DeviceID): string
    {
        return $this->Execute(fn () => $this->Call('GET', '/v1/mower/' . rawurlencode($DeviceID) . '/work-params'));
    }

    public function GetPlan(string $DeviceID): string
    {
        return $this->Execute(fn () => $this->Call('GET', '/v1/mower/' . rawurlencode($DeviceID) . '/plan'));
    }

    public function SearchErrorCodes(string $DeviceID, int $Page, int $PageSize): string
    {
        return $this->Execute(fn () => $this->Call('POST', '/v1/mower/error-codes/search', [
            'deviceId'   => $DeviceID,
            'pageNumber' => max(1, $Page),
            'pageSize'   => max(1, $PageSize),
        ]));
    }

    //Veranlasst den Mäher, Material und Karten in die Cloud zu laden. Das Ergebnis kommt per SSE (nur LUBA 3 AWD).
    public function FetchMaterial(string $DeviceID): string
    {
        return $this->Execute(fn () => $this->Call('POST', '/v1/mower/material/fetch', ['deviceIds' => [$DeviceID]]));
    }

    //Schaltet den lokalen Zugang (HA Local Network) eines Mähers ein oder aus. Der Mäher wird über seinen Gerätenamen angesprochen.
    public function SaveLocalNetwork(string $DeviceName, bool $Enabled): string
    {
        $body = [
            'clientId' => $this->ReadPropertyString('ClientID'),
            'devices'  => [['deviceName' => $DeviceName, 'enabled' => $Enabled ? 1 : 0]],
        ];
        if ($this->ReadPropertyString('RedirectUri') !== '') {
            $body['redirectUri'] = $this->ReadPropertyString('RedirectUri');
        }
        return $this->Execute(fn () => $this->Call('POST', '/v1/ha/local-network/save', $body));
    }

    public function QueryLocalNetwork(string $DeviceName): string
    {
        $body = ['clientId' => $this->ReadPropertyString('ClientID')];
        if ($DeviceName !== '') {
            $body['deviceName'] = $DeviceName;
        }
        return $this->Execute(fn () => $this->Call('POST', '/v1/ha/local-network/query', $body));
    }

    //Holt ein kurzlebiges Ticket für die lokale WebSocket-Verbindung. Das Ticket enthält eine Signatur und wird nicht protokolliert.
    public function GetWsTicket(string $DeviceName, string $IP): string
    {
        $body = ['deviceName' => $DeviceName, 'timestamp' => time()];
        if ($IP !== '') {
            $body['ip'] = $IP;
        }
        return $this->Execute(fn () => $this->Call('POST', '/v1/mower/ws/ticket', $body), false);
    }

    //Trägt das aktuelle Token im SSE-Client ein und meldet die Mäher im Live-Modus an. Läuft per Timer, weil eine Subscription nur 10 Minuten gilt.
    //Der SSE-Client wird nur aktiviert, wenn mindestens ein Mäher im Modus "Abfrage und Live-Aktualisierung" läuft, sonst bleibt er inaktiv.
    public function RenewSubscription(): void
    {
        if ($this->GetBuffer('SseReplaced') === '1' || $this->GetStatus() !== IS_ACTIVE) {
            return;
        }
        if ($this->GetSseParentID() === 0) {
            if ($this->CollectSubscriptions() !== [] && $this->GetBuffer('NoSseNotified') !== '1') {
                $this->SetBuffer('NoSseNotified', '1');
                $this->LogMessage('A mower is set to live updates, but the interface of the MammotionCloud is not an SSE client. Change the interface of the cloud instance to an SSE client.', KL_WARNING);
            }
            return;
        }
        $subscriptions = $this->CollectSubscriptions();
        if ($subscriptions === []) {
            $this->ConfigureSse(false, []);
            return;
        }
        try {
            $this->ConfigureSse(true, [
                ['Name' => 'Authorization', 'Value' => 'Bearer ' . $this->GetAccessToken()],
                ['Name' => 'SSE-Client', 'Value' => 'developer'],
                ['Name' => 'Accept', 'Value' => 'text/event-stream'],
            ]);
            foreach (array_chunk($subscriptions, self::SUBSCRIPTION_BATCH) as $batch) {
                $this->SendDebug('Subscription', 'Response: ' . json_encode($this->Call('POST', '/v1/devices/subscriptions', $batch), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 0);
            }
            $this->SendDebug('Subscription', count($subscriptions) . ' device(s)', 0);
        } catch (MammotionApiException $e) {
            $this->SendDebug('Subscription', $e->getMessage(), 0);
        }
    }

    //Die Mäher dieser Cloud im Live-Modus mit den gewünschten Properties (Konfiguration UpdateMode des Mähers, SubscribeProperties über MammotionLive).
    private function CollectSubscriptions(): array
    {
        $subscriptions = [];
        foreach ($this->GetMowers() as $mowerID) {
            if (IPS_GetProperty($mowerID, 'UpdateMode') !== 1) {
                continue;
            }
            $properties = MammotionLive::Selected(IPS_GetProperty($mowerID, 'SubscribeProperties'));
            $deviceID = (string) IPS_GetProperty($mowerID, 'DeviceID');
            if ($deviceID !== '' && $properties !== []) {
                $subscriptions[] = ['deviceId' => $deviceID, 'properties' => $properties];
            }
        }
        return $subscriptions;
    }

    private function GetMowers(): array
    {
        return array_values(array_filter(
            IPS_GetInstanceListByModuleID(self::GUID_MOWER),
            fn (int $id) => IPS_GetInstance($id)['ConnectionID'] === $this->InstanceID
        ));
    }

    //Ein Virtual IO als Verbindung muss aktiv sein, sonst zeigt die Konsole "Instanz nicht aktiv". Wird hier bei Bedarf eingeschaltet.
    private function EnableVirtualIo(): void
    {
        $parentID = (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID === 0 || IPS_GetInstance($parentID)['ModuleInfo']['ModuleID'] !== self::GUID_VIRTUAL_IO) {
            return;
        }
        if (empty(json_decode(IPS_GetConfiguration($parentID), true)['Open'])) {
            IPS_SetProperty($parentID, 'Open', true);
            IPS_ApplyChanges($parentID);
        }
    }

    //Die ID des angeschlossenen SSE-Clients oder 0, wenn keiner (oder ein anderer Parent) angeschlossen ist.
    private function GetSseParentID(): int
    {
        $parentID = (int) IPS_GetInstance($this->InstanceID)['ConnectionID'];
        if ($parentID === 0 || IPS_GetInstance($parentID)['ModuleInfo']['ModuleID'] !== self::GUID_SSE_CLIENT) {
            return 0;
        }
        return $parentID;
    }

    //Stellt den SSE-Client (Parent) ein. Er braucht das aktuelle Token im Header, deshalb wird bei jedem Token-Wechsel nachgezogen.
    private function ConfigureSse(bool $Active, array $Headers): void
    {
        $parentID = $this->GetSseParentID();
        if ($parentID === 0) {
            return;
        }
        $current = json_decode(IPS_GetConfiguration($parentID), true);
        if (!$Active) {
            if (!empty($current['Active'])) {
                IPS_SetProperty($parentID, 'Active', false);
                IPS_ApplyChanges($parentID);
            }
            return;
        }
        $desired = ['URL' => self::SSE_URL, 'Headers' => json_encode($Headers), 'VerifyPeer' => true, 'VerifyHost' => true, 'Active' => true];
        $changed = false;
        foreach ($desired as $property => $value) {
            if (($current[$property] ?? null) !== $value) {
                IPS_SetProperty($parentID, $property, $value);
                $changed = true;
            }
        }
        if ($changed) {
            IPS_ApplyChanges($parentID);
        }
    }
    private function Api(): MammotionApi
    {
        return new MammotionApi($this->ReadPropertyString('Language'));
    }

    private function ClearToken(): void
    {
        $this->SetBuffer('AccessToken', '');
        $this->SetBuffer('RefreshToken', '');
        $this->SetBuffer('ExpiresAt', '0');
    }

    //Liefert ein gültiges Token. Ein Token läuft nach einer Stunde ab und wird kurz davor erneuert, zuerst per Refresh-Token,
    //sonst per Client-Credentials. Die Sperre verhindert parallele Token-Anfragen mehrerer Skripte.
    private function GetAccessToken(bool $Force = false): string
    {
        $lock = 'MammotionToken' . $this->InstanceID;
        if (!IPS_SemaphoreEnter($lock, 10000)) {
            throw new MammotionApiException('Token lock timeout');
        }
        try {
            $token = $this->GetBuffer('AccessToken');
            if (!$Force && $token !== '' && time() < (int) $this->GetBuffer('ExpiresAt') - 60) {
                return $token;
            }
            $api = $this->Api();
            $clientID = $this->ReadPropertyString('ClientID');
            $secret = $this->ReadPropertyString('ClientSecret');
            $result = null;
            $refresh = $this->GetBuffer('RefreshToken');
            if ($refresh !== '' && !$Force) {
                try {
                    $result = $api->requestToken($clientID, $secret, $refresh);
                } catch (MammotionApiException) {
                    $result = null;
                }
            }
            try {
                $result ??= $api->requestToken($clientID, $secret);
            } catch (MammotionApiException $e) {
                if ($e->getHttpStatus() >= 400 && $e->getHttpStatus() < 500) {
                    $this->SetStatus(self::STATUS_AUTH_FAILED);
                }
                throw $e;
            }
            $this->SetBuffer('AccessToken', $result['AccessToken']);
            $this->SetBuffer('RefreshToken', $result['RefreshToken']);
            $this->SetBuffer('ExpiresAt', (string) $result['ExpiresAt']);
            if ($this->GetStatus() !== IS_ACTIVE) {
                $this->SetStatus(IS_ACTIVE);
            }
            return $result['AccessToken'];
        } finally {
            IPS_SemaphoreLeave($lock);
        }
    }

    //Ruft die API auf. Bei 401 ist das Token ungültig geworden (zum Beispiel serverseitig widerrufen): einmal neu holen und wiederholen.
    private function Call(string $Method, string $Path, ?array $Body = null): mixed
    {
        $api = $this->Api();
        try {
            return $api->call($Method, $Path, $this->GetAccessToken(), $Body);
        } catch (MammotionApiException $e) {
            if ($e->getHttpStatus() !== 401) {
                throw $e;
            }
            return $api->call($Method, $Path, $this->GetAccessToken(true), $Body);
        }
    }

    private function Execute(callable $Function, bool $LogData = true): string
    {
        try {
            $data = $Function();
        } catch (MammotionApiException $e) {
            $this->SendDebug('API', $e->getMessage(), 0);
            return $this->Failure($e->getMessage(), $e->getApiCode());
        }
        $result = json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($LogData) {
            $this->SendDebug('API', substr($result, 0, 1000), 0);
        }
        return $result;
    }

    private function Failure(string $Message, int $Code = 0): string
    {
        return json_encode(['success' => false, 'message' => $Message, 'code' => $Code], JSON_UNESCAPED_UNICODE);
    }
}
