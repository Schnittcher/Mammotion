<?php

declare(strict_types=1);

//Properties der Live-Aktualisierung (siehe Developer-Doku, /v1/devices/subscriptions) und ihre Vorbelegung. Mäher und Cloud nutzen
//dieselbe Liste: Symcon speichert aus der Liste im Formular nur den Haken, die Zuordnung zum Namen läuft über die Reihenfolge.
final class MammotionLive
{
    public const PROPERTIES = [
        'DEV_ST'   => true, 'CHG_ST' => true, 'SELF_CHK' => true, 'SENS_ST' => false, 'LOC_SRC' => false, 'BAT_PCT' => true,
        'BMS_INFO' => false, 'COORD' => false, 'NET_ST' => true, 'WK_PRG' => true, 'KNF_HGT' => true, 'KNF_ST' => true,
        'WK_TRK'   => false, 'DEV_VER' => true, 'BASE_STN' => false, 'OTA_PRG' => false, 'MAP_UPLD' => false, 'LOG_SYNC' => false,
        'DEV_INF'  => false, 'TSK_CHG' => true, 'ENR_BIND' => false, 'ENR_UNBIND' => false,
    ];

    //Abonnieren-Haken je Property in der Reihenfolge von PROPERTIES. Fehlt eine Zeile, gilt die Vorgabe.
    public static function Flags(string $RowsJson): array
    {
        $rows = json_decode($RowsJson, true) ?: [];
        $flags = [];
        foreach (array_values(self::PROPERTIES) as $index => $default) {
            $flags[] = isset($rows[$index]['Subscribe']) ? (bool) $rows[$index]['Subscribe'] : $default;
        }
        return $flags;
    }

    //Die gewählten Property-Namen
    public static function Selected(string $RowsJson): array
    {
        $selected = [];
        foreach (array_keys(self::PROPERTIES) as $index => $property) {
            if (self::Flags($RowsJson)[$index]) {
                $selected[] = $property;
            }
        }
        return $selected;
    }
}
