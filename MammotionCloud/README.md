# MammotionCloud
Verbindung zur Mammotion Open API (Splitter). Die Instanz holt und erneuert das Token und führt alle Anfragen für den Konfigurator und die Mäher aus. Sie wird vor dem Konfigurator und den Mähern angelegt. Als Verbindung braucht sie eine Schnittstelle: Ohne Live-Aktualisierung genügt ein Virtual IO (immer aktiv, tut nichts), für den LUBA 3 AWD ein SSE-Client.

## Inhaltsverzeichnis
- [MammotionCloud](#mammotioncloud)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [1. Konfiguration](#1-konfiguration)
  - [2. Variablen](#2-variablen)
  - [3. Funktionen](#3-funktionen)
  - [4. Spenden](#4-spenden)
  - [5. Lizenz](#5-lizenz)

## 1. Konfiguration

Feld | Beschreibung
------------ | ----------------
Client-ID | Client-ID aus dem Developer-Portal von Mammotion.
Client-Secret | Client-Secret aus dem Developer-Portal. Es wird nur beim Anlegen der Zugangsdaten angezeigt.
Sprache der Cloud-Meldungen | Sprache (de-DE oder en-US), in der die Cloud Fehlertexte und Lösungen liefert. Standard: Deutsch.
Redirect-URI (optional) | Wird beim Freischalten des lokalen Zugangs mitgesendet, falls Mammotion sie verlangt.

Die Taste "Verbindung testen" holt ein neues Token und zählt die Mäher des Kontos.

**Verbindung der Instanz:** Symcon verlangt für einen Splitter eine Verbindung, ein optionaler Parent existiert nicht. Beim Anlegen der Cloud bietet Symcon einen Virtual IO an (schon auf aktiv gestellt), das ist der Standard. Er tut nichts und zeigt keine Meldung. Ein inaktiver Parent würde in der Konsole als "Instanz nicht aktiv" angezeigt.\n\n**Live-Aktualisierung (SSE):** Sie gibt es nur für den LUBA 3 AWD. Dafür ändert man die Schnittstelle der Cloud im Instanzdialog ("Schnittstelle ändern") auf einen SSE-Client. Die Cloud trägt dann URL, Token und Aktiv im SSE-Client selbst ein, sobald ein Mäher im Modus "Abfrage und Live-Aktualisierung" läuft, und erneuert die Subscription der Mäher alle acht Minuten. Läuft kein Mäher mehr in diesem Modus, schaltet sie den SSE-Client wieder aus. Steht ein Mäher auf Live-Aktualisierung, die Schnittstelle der Cloud ist aber kein SSE-Client, schreibt die Cloud einmal eine Warnung ins Meldungsfenster. Das Token steht im Header des SSE-Clients. Pro Developer-Konto ist nur eine Live-Verbindung erlaubt: Eine zweite Verbindung desselben Kontos verdrängt diese, die Live-Aktualisierung wird dann abgeschaltet und nicht automatisch neu verbunden (die Cloud selbst bleibt aktiv, Abfragen und Befehle laufen weiter). Erneutes Übernehmen erlaubt die Live-Verbindung wieder.

## 2. Variablen
Die Instanz legt keine Variablen an.

## 3. Funktionen
Alle Funktionen außer `MAMC_TestConnection` und `MAMC_RenewSubscription` liefern JSON: `{"success":true,"data":...}` oder `{"success":false,"message":"...","code":0}`. Optionale Parameter gibt es nicht, alle Werte werden übergeben.

`string MAMC_TestConnection(integer $InstanzID);`
Holt ein neues Token, zählt die Mäher und liefert einen Text für die Anzeige.

`string MAMC_ListMowers(integer $InstanzID);`
Liefert die Geräte des Kontos (Geräte-ID, Name, Spitzname, Modell, Online).

`string MAMC_GetMower(integer $InstanzID, string $DeviceID);`
Liefert Zustand, Akku, Ladestatus, Firmware und Netzwerk eines Mähers.

`string MAMC_SendAction(integer $InstanzID, string $DeviceID, string $Action, string $TaskName);`
Sendet einen Befehl: `CMD_START` (sofort mähen, ohne Plan), `START` (Aufgabe starten, `$TaskName` erforderlich), `PAUSE`, `RESUME`, `STOP`, `RETURN`, `CANCEL_RETURN`. Bei den anderen Befehlen bleibt `$TaskName` leer. Die Antwort bestätigt nur die Zustellung des Befehls.

`string MAMC_GetWorkParams(integer $InstanzID, string $DeviceID);`
Liefert die aktuellen Arbeitsparameter. Die Cloud beantwortet die erste Abfrage oft nur mit einer Zustellbestätigung und lauter Nullen.

`string MAMC_GetPlan(integer $InstanzID, string $DeviceID);`
Liefert die Aufgaben, die in der Mammotion-App angelegt sind.

`string MAMC_SearchErrorCodes(integer $InstanzID, string $DeviceID, integer $Page, integer $PageSize);`
Liefert die Fehlermeldungen des Mähers seitenweise.

`string MAMC_FetchMaterial(integer $InstanzID, string $DeviceID);`
Veranlasst den Mäher, Material und Karten in die Cloud zu laden. Das Ergebnis kommt über die Live-Verbindung (nur LUBA 3 AWD).

`string MAMC_SaveLocalNetwork(integer $InstanzID, string $DeviceName, boolean $Enabled);`
Schaltet den lokalen Zugang des Mähers in der Mammotion-Cloud ein oder aus. Der Mäher wird über seinen Gerätenamen angesprochen.

`string MAMC_QueryLocalNetwork(integer $InstanzID, string $DeviceName);`
Fragt den lokalen Zugang ab. Bei leerem Gerätenamen kommen alle Mäher des Kontos.

`string MAMC_GetWsTicket(integer $InstanzID, string $DeviceName, string $IP);`
Holt ein kurzlebiges Ticket für die lokale WebSocket-Verbindung. Bei leerer `$IP` ist das Ticket nicht an eine Adresse gebunden.

`void MAMC_RenewSubscription(integer $InstanzID);`
Schaltet den SSE-Client (falls die Schnittstelle einer ist) je nach Bedarf ein oder aus, trägt das aktuelle Token ein und meldet die Mäher im Live-Modus an. Läuft automatisch alle acht Minuten, sobald die Verbindung steht und wenn ein Mäher seinen Modus ändert.

## 4. Spenden
Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

[![PayPal](https://img.shields.io/badge/PayPal-Spenden-00457C?logo=paypal&logoColor=white&style=for-the-badge)](https://www.paypal.com/donate?hosted_button_id=EK4JRP87XLSHW) [![Amazon Wunschzettel](https://img.shields.io/badge/Amazon-Wunschzettel-FF9900?logo=amazon&logoColor=white&style=for-the-badge)](https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share)

## 5. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
