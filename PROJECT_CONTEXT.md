# Projektkontext

## Zweck und Benutzer

- Projektzweck: Mammotion-Mäher über die offizielle Open API (`api-open.mammotion.com`) in IP-Symcon.
- Hauptnutzer: Besitzer von Mammotion-Mähern mit Entwickler-Zugangsdaten.
- Nicht im Umfang: die inoffizielle App-Cloud (Aliyun), Bluetooth, Kartendarstellung. Die lokale WebSocket-Verbindung ist vorbereitet, aber noch nicht umgesetzt.

## Unterstützte Plattform

- IP-Symcon: 9.0 (getestet auf 9.1)
- PHP: 8.5
- Dienste: Mammotion Open API und OAuth (`id.mammotion.com`). Nur Mäher-Modelle von 2025 (Herstellerangabe).

## Einstiegspunkte

| Bereich | Datei oder Verzeichnis | Bedeutung |
| --- | --- | --- |
| Bibliothek | `library.json` | Name `Mammotion`, GUID neu erzeugt |
| Splitter | `MammotionCloud/module.php` | Token, REST, lokaler Zugang, SSE-Verbindung (nur bei Live) |
| RTK | `MammotionRtk/module.php` | RTK-Referenzstation: Modell, Firmware, Online (die API bietet nicht mehr) |
| Gerät | `MammotionMower/module.php` | Variablen, Befehle, Abfrage |
| Konfigurator | `MammotionConfigurator/module.php` | Mäher des Kontos anlegen |
| API-Client | `libs/MammotionApi.php` | curl-Client, Token-Anfrage, Antwortumschlag |
| Gemeinsam | `libs/MammotionDevice.php` | Trait der Geräte: Anfrage an die Cloud, Zustandsabfrage, Boolean-Darstellung |
| Qualität | `.github/workflows/style.yml`, `tests.yml` | Aufrufe der zentralen Workflows |

## Architektur und Datenfluss

```text
Mäher-Instanzen (MammotionMower), RTK (MammotionRtk) -> MammotionCloud (Splitter) -> Virtual IO (Standard) oder SSE-Client (nur LUBA 3 AWD, Live)
MammotionConfigurator                                -> MammotionCloud
```

- Die Geräte schicken JSON mit `Function` an die Cloud (`ForwardData`): `GetMower`, `SendAction`, `GetWorkParams`, `GetPlan`, `SearchErrorCodes`, `FetchMaterial`, `SaveLocalNetwork`, `QueryLocalNetwork`, `ListMowers`. Antwort ist `{"success":bool,"data":...}`.
- Die Cloud hält das Token (Buffer, Semaphore) und wiederholt bei 401 einmal mit neuem Token.
- **Die Cloud braucht immer einen Parent.** Symcon zeigt bei einem Splitter ohne Parent einen orangen Hinweis ("Die Instanz benötigt eine übergeordnete Instanz"). Ein optionaler Parent existiert nicht, auch `GetCompatibleParents` mit `connect` entfernt den Hinweis nicht (auf Dev geprüft). Ein angeschlossener, aber inaktiver SSE-Client ersetzt den Balken durch "Instanz nicht aktiv". Deshalb ist der **Virtual IO** (immer aktiv, tut nichts, Eigenschaft `Open`) der Standard-Parent: `GetCompatibleParents` bietet ihn zuerst an, vorbelegt mit `Open` = true, danach den SSE-Client. `EnableVirtualIo()` schaltet einen inaktiven Virtual IO bei `ApplyChanges` selbst ein.
- Für den LUBA 3 AWD wird die Schnittstelle der Cloud auf einen SSE-Client geändert. Ein Mäher im Modus "Abfrage und Live-Aktualisierung" macht die Cloud dann zum Anwender: Sie trägt URL, Header (Token) und Aktiv im Parent ein (`IPS_SetProperty`) und meldet die Mäher an (`/v1/devices/subscriptions`, Timer alle 8 Minuten, eine Subscription gilt 10). Läuft kein Mäher in diesem Modus, schaltet sie den SSE-Client auf inaktiv. Der Mäher ruft nach dem Übernehmen `MAMC_RenewSubscription()` der Cloud. Ist ein Mäher auf Live gestellt, die Schnittstelle aber kein SSE-Client, schreibt die Cloud einmal eine Warnung.
- SSE-Daten kommen als `{"Data":"<Text>","DataID":…,"Event":…,"ID":…,"Retry":…}` an (auf Symcon 9.1 geprüft, Kommentar- und Heartbeat-Zeilen werden nicht geliefert). Die Cloud ruft für jeden Mäher `MAMM_HandleEvent()`. Der Mäher löst bei einem Ereignis mit seiner ID nur eine REST-Abfrage aus, weil das Ereignisformat der Open API nicht dokumentiert ist.
- Verdrängung (`pushTag` `sc`, `pushType` 1): Die Cloud schaltet nur die Live-Verbindung ab und bleibt aktiv (Status 102), damit Abfragen und Befehle weiterlaufen. Erneutes Übernehmen der Cloud erlaubt die Live-Verbindung wieder.
- Geräte und Konfigurator prüfen den Cloud-Status direkt (Status 102).
- Transport und Auswertung sind im Gerät getrennt: `FetchDetail()` beschafft das Array, `ApplyDetail()` setzt die Variablen. Ein lokaler Transport liefert später dasselbe Array.
## Öffentliche und persistente Verträge

- Properties: siehe `form.json` und die Modul-READMEs. Idents der Variablen siehe `MammotionMower/README.md`.
- Öffentliche Methoden: siehe Modul-READMEs, keine optionalen Parameter.
- Datenflussschnittstelle Gerät zu Cloud: `{33F5BD76-EACE-472E-AC52-C2328AD1E5B3}`. Die Cloud sendet aktuell nichts an Kinder, die Schnittstelle `{3257C609-57DE-436D-8FD7-F8A4631A02D1}` (Cloud zu Gerät, in `childRequirements` der Cloud und `implemented` von Mäher und Konfigurator) bleibt trotzdem: Ohne sie verweigert Symcon die Verbindung (geprüft auf 9.1). Der SSE-Client (`{5A709184-B602-D394-227F-207611A33BDF}`) ist die Schnittstelle, die die Cloud für den optionalen SSE-Parent implementiert.

## Prüfung und Veröffentlichung

- Lokale Prüfungen: `symcon-check` im Modulordner. `.style` ist ein Submodul und wurde noch nicht eingerichtet, weil es noch kein Git-Repository gibt.
- Praxistests: `symcon-dev`. Erfolgspfade wurden gegen einen lokalen Mock der Open API geprüft (Token, 401-Wiederholung, Mäher, Plan, Arbeitsparameter, Befehle, lokaler Zugang, Ticket, Subscription, SSE). Gegen die echte API ist nur der Fehlerfall mit falschen Zugangsdaten geprüft.

## Bekannte Risiken und offene Fragen

- Es gibt keinen Test mit echten Zugangsdaten und echtem Mäher. Das SSE-Nutzdatenformat der Open API (Inhalt von `Data`) ist unbekannt (Q6).
- Einheiten der Arbeitsparameter nennt die Spezifikation nicht, deshalb haben die Variablen keine.
- Die Spezifikation und das Portal weichen beim Erfolgscode ab (`0` und `200`), beides gilt als Erfolg.
- Lokale Verbindung: Die WLAN-IP des Mähers liefert `GET /v1/mower/{id}` (`network.wifiIp`, Variable `WifiIP`). Port, Übergabe des Tickets, Zertifikatsprüfung und Nachrichtenformat sind nicht dokumentiert und müssen gemessen werden. Der Protobuf-Decoder aus `_Mammotion` kann dafür dienen.
- Ein Gerät kann nur einen Parent haben. Für die lokale Verbindung ist noch zu klären, wie sie neben der Cloud-Instanz angebunden wird (Entscheidung nach der Messung).
