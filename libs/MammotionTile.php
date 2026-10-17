<?php

declare(strict_types=1);

//Kachel für die Kachel-Visualisierung (Mäher, RTK-Station): Produktbild, Zustand, Akku und bei Mähern die Bedienung.
//Die Instanz braucht die Variablen Online und Model, bei Mähern zusätzlich Status, Battery, Charging und Control, und ruft in
//Create() RegisterTile() sowie nach jeder Änderung der Werte UpdateTile() auf. Aktionen der Kachel gehen als Ident Control an
//das RequestAction() des Mähers.
trait MammotionTile
{
    public function GetVisualizationTile(): string
    {
        $html = file_get_contents(__DIR__ . '/tile.html');
        return $html . '<script>handleMessage(' . json_encode(json_encode($this->TilePayload(true))) . ');</script>';
    }

    private function RegisterTile(): void
    {
        //Die Konstanten sind nicht in jeder Umgebung definiert (gemeldet unter Symcon 9.0: "Undefined constant"). Die Zahlenwerte stehen
        //in der Doku von SetVisualizationType: 1 = HTML, 2 = HTML auch im Vollbild (ab 9.1).
        $html = defined('INSTANCE_VISUALIZATION_TYPE_HTML') ? constant('INSTANCE_VISUALIZATION_TYPE_HTML') : 1;
        $this->SetVisualizationType(defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') ? constant('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') : $html);
    }

    //Schickt die aktuellen Werte an geöffnete Kacheln. Das Bild bleibt in der Kachel erhalten und wird nur mitgesendet, wenn es neu ist.
    private function UpdateTile(bool $WithImage = false): void
    {
        $this->UpdateVisualizationValue(json_encode($this->TilePayload($WithImage)));
    }

    private function TilePayload(bool $WithImage): array
    {
        $isMower = @$this->GetIDForIdent('Status') !== false;
        $item = [
            'type'   => $isMower ? 'mower' : 'rtk',
            'name'   => IPS_GetName($this->InstanceID),
            'model'  => (string) $this->TileValue('Model'),
            'online' => (bool) $this->TileValue('Online'),
        ];
        if ($isMower) {
            $item['state'] = (int) $this->TileValue('Status');
            $item['stateText'] = GetValueFormatted($this->GetIDForIdent('Status'));
            $item['battery'] = (int) $this->TileValue('Battery');
            $item['charging'] = (bool) $this->TileValue('Charging');
            $item['actions'] = $this->TileActions($item['state'], $item['online']);
            $item['tasks'] = $this->TileTasks();
            $item['task'] = (string) $this->TileValue('Task');
        }
        $texts = [
            'online'  => $this->Translate('Online'),
            'offline' => $this->Translate('Offline'),
        ];
        //Die Texte der Bedienung brauchen nur Mäher, die Übersetzungen stehen nur in deren locale.json.
        if ($isMower) {
            $texts += [
                'charging' => $this->Translate('Charging'),
                'start'    => $this->Translate('Start task'),
                'actions'  => [
                    1 => $this->Translate('Start mowing'),
                    2 => $this->Translate('Pause'),
                    3 => $this->Translate('Resume'),
                    4 => $this->Translate('Stop'),
                    5 => $this->Translate('Return to dock'),
                    6 => $this->Translate('Cancel return'),
                ],
            ];
        }
        $payload = ['item' => $item, 'texts' => $texts];
        if ($WithImage) {
            $payload['image'] = $this->TileImage();
        }
        return $payload;
    }

    //Schaltflächen der Steuerung (Werte der Variable Control), ohne "Mähen starten": Gestartet wird über die Aufgabe. "Rückfahrt abbrechen" nur, solange der Mäher zurückfährt (Zustand 6).
    private function TileActions(int $State, bool $Online): array
    {
        if (!$Online) {
            return [];
        }
        return $State === 6 ? [2, 3, 4, 5, 6] : [2, 3, 4, 5];
    }

    //Aufgaben aus der Mammotion-App (Auswahl der Variable Task). Die offene API kennt keine einzelnen Zonen, nur diese Aufgaben.
    private function TileTasks(): array
    {
        $variableID = @$this->GetIDForIdent('Task');
        if (!$variableID) {
            return [];
        }
        $options = json_decode((string) (IPS_GetVariablePresentation($variableID)['OPTIONS'] ?? '[]'), true) ?: [];
        return array_values(array_map(fn ($option) => (string) $option['Value'], $options));
    }

    private function TileValue(string $Ident): mixed
    {
        $variableID = @$this->GetIDForIdent($Ident);
        return $variableID ? GetValue($variableID) : null;
    }

    //Das lokal abgelegte Produktbild als Data-URI oder ein leerer Text
    private function TileImage(): string
    {
        $mediaID = @$this->GetIDForIdent('Image');
        if (!$mediaID) {
            return '';
        }
        $data = base64_decode(IPS_GetMediaContent($mediaID), true);
        $info = $data === false ? false : @getimagesizefromstring($data);
        return $info === false ? '' : 'data:' . $info['mime'] . ';base64,' . base64_encode($data);
    }
}
