# MammotionMower
Ein Mammotion-Mäher. Die Instanz zeigt Zustand, Akku und Netzwerk, bietet die Steuerung (Start, Pause, Fortsetzen, Stopp, Rückkehr zur Ladestation), startet Aufgaben aus der Mammotion-App und zeigt die Arbeitsparameter. Sie wird normalerweise über den MammotionConfigurator angelegt und braucht eine MammotionCloud als Verbindung.

## Inhaltsverzeichnis
- [MammotionMower](#mammotionmower)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [1. Konfiguration](#1-konfiguration)
  - [2. Variablen](#2-variablen)
    - [2.1 Zustand](#21-zustand)
    - [2.2 Steuerung](#22-steuerung)
    - [2.3 Arbeitsparameter](#23-arbeitsparameter)
  - [3. Funktionen](#3-funktionen)
  - [4. Spenden](#4-spenden)
  - [5. Lizenz](#5-lizenz)

## 1. Konfiguration

Feld | Beschreibung
------------ | ----------------
Geräte-ID | ID des Mähers in der Mammotion-Cloud. Pflichtfeld, wird vom Konfigurator gesetzt.
Gerätename | Name des Mähers (zum Beispiel `Luba-…`). Wird für den lokalen Zugang gebraucht, vom Konfigurator gesetzt.
Aktualisierung | "Abfrage" holt den Zustand in festen Abständen. "Abfrage und Live-Aktualisierung (LUBA 3 AWD)" meldet den Mäher zusätzlich bei der Live-Verbindung der MammotionCloud an. Ein Ereignis löst dann sofort eine Abfrage aus, höchstens alle 10 Sekunden. Dafür muss die Schnittstelle der MammotionCloud ein SSE-Client sein (statt des Virtual IO). Die Cloud schaltet ihn selbst ein, sobald ein Mäher in diesem Modus läuft, und wieder aus, wenn keiner mehr.
Abfrageintervall | Abstand der Abfragen in Sekunden. 0 schaltet die Abfrage aus, Werte von 1 bis 14 gelten als 15 Sekunden. Standard: 60. Mit Live-Aktualisierung genügt zum Beispiel 300.
Arbeitsparameter lesen | Legt die Variablen aus 2.3 an und holt die Werte alle fünf Abfragen. Ist die Option aus, werden die Variablen entfernt.
Properties für die Live-Aktualisierung | Welche Properties im Live-Modus abonniert werden. Vorbelegt sind DEV_ST, CHG_ST, SELF_CHK, BAT_PCT, NET_ST, WK_PRG, KNF_HGT, KNF_ST, DEV_VER und TSK_CHG. Positionsdaten (COORD) und Mähspur (WK_TRK) sind aus, weil sie sehr häufig kommen.

Unter "Lokaler Zugang" kann der lokale Zugang des Mähers in der Mammotion-Cloud freigeschaltet, abgeschaltet und abgefragt werden. Das ist die Voraussetzung für eine lokale Verbindung. Mammotion bietet ihn nur für einige Mäher-Modelle an: Für die anderen antwortet die Cloud mit "Unsupported device" (Anzeige: "vom Gerät nicht unterstützt"), und auch das Ticket für die lokale Verbindung wird dann nicht ausgestellt. Die lokale Verbindung selbst gibt es noch nicht.

**Kachel:** Die Instanz hat eine Kachel für die Kachel-Visualisierung mit Produktbild, Zustand und Akku. Darunter stehen die Aufgabenauswahl mit "Aufgabe starten" und die Knöpfe Pause, Fortsetzen, Stopp und Zur Ladestation, bei der Rückfahrt zusätzlich Rückfahrt abbrechen. Mähen ohne Aufgabe ("Mähen starten") gibt es nur über die Variable "Steuerung". Das Produktbild lädt die Instanz von der Mammotion-Cloud und legt es als Medienobjekt "Produktbild" lokal ab. Der Vollbildtyp der Kachel braucht Symcon 9.1, unter 9.0 bleibt die maximierte Ansicht leer.

## 2. Variablen

### 2.1 Zustand
Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
Online | `Online` | Ja/Nein | Mäher ist mit der Cloud verbunden.
Status | `Status` | Ganzzahl | Bereit, Mäht, Pausiert, Kartierung, Update, Offline, Fährt zur Ladestation, Fehler, Unbekannt.
Akku | `Battery` | Ganzzahl (%) | Ladestand.
Lädt | `Charging` | Ja/Nein | Mäher lädt. Die Cloud meldet dafür bei einem LUBA 2 den Wert 2, bei einem Yuka Mini 600 den Wert 1 bei nicht vollem Akku, die Doku kennt nur 0 und 1. Bei vollem Akku gilt der Wert 1 als "lädt nicht".
Modell | `Model` | Text | Modellbezeichnung.
Firmware | `Firmware` | Text | Firmware-Version.
Produktbild | `Image` | Medienobjekt (Bild) | Produktbild des Mähers, lokal abgelegt.
Netzwerk | `NetworkType` | Ganzzahl | WLAN oder Mobilfunk.
WLAN-IP-Adresse | `WifiIP` | Text | IP-Adresse des Mähers im WLAN.
WLAN-Signal | `WifiRssi` | Ganzzahl (dBm) | Signalstärke im WLAN.
Mobilfunk-Signal | `CellularRssi` | Ganzzahl (dBm) | Signalstärke im Mobilfunknetz.

### 2.2 Steuerung
Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
Steuerung | `Control` | Ganzzahl, steuerbar | Mähen starten (ohne Plan), Pause, Fortsetzen, Stopp, Zur Ladestation, Rückfahrt abbrechen. "Mähen starten" nimmt die Cloud bei manchen Modellen nicht an (gemeldet beim Yuka Mini 600, bei einem LUBA 2 startet der Mäher nicht): Dann steht eine Warnung im Meldungsfenster, und die Aufgabe startet über "Aufgabe starten".
Aufgabe starten | `Task` | Text, steuerbar | Aufgaben aus der Mammotion-App. Die Variable entsteht, sobald die Aufgabenliste einmal geholt wurde (alle 30 Abfragen aktualisiert). Eine Auswahl startet die Aufgabe.

Ein Befehl bestätigt nur die Zustellung an den Mäher. Der neue Zustand wird nach etwa fünf Sekunden abgefragt.

### 2.3 Arbeitsparameter
Nur mit der Option "Arbeitsparameter lesen". Die Cloud nennt für die Zahlenwerte keine Einheiten. Die Werte werden nur übernommen, wenn die Cloud sie wirklich geliefert hat, nicht bei einer leeren Bestätigung.

Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
Betriebsart | `JobContent` | Ganzzahl | Mähen, Sammeln oder Mähen und Sammeln.
Bahnmuster | `ChannelMode` | Ganzzahl | Einfache Bahnen, Kreuzmuster, Zonenbahnen, keine Bahnen.
Bahnabstand | `ChannelWidth` | Ganzzahl | Abstand der Bahnen.
Arbeitsrichtung | `Toward` | Ganzzahl | Richtung der Bahnen.
Winkelbezug | `TowardMode` | Ganzzahl | Relativ, absolut oder zufällig.
Winkel der Doppelbahn | `TowardIncludedAngle` | Ganzzahl | Winkel bei Kreuzmuster.
Schnitthöhe | `KnifeHeight` | Ganzzahl | Höhe der Messer.
Arbeitsgeschwindigkeit | `Speed` | Ganzzahl | Geschwindigkeit beim Mähen.
Hindernisvermeidung | `UltraWave` | Ganzzahl | Langsam berühren, nicht berühren oder nicht berühren und auf dem Rasen bleiben.
Kantenrunden | `EdgeMode` | Ganzzahl | Anzahl der Runden an der Kante.
Kantenüberlappung | `RideBoundaryDistance` | Kommazahl | Überlappung an der Kante.
Reihenfolge | `BoundaryZigzagOrder` | Ganzzahl | Erst Kante oder erst Bahnen.
Runden um Hindernisse | `ForbiddenAreaCircleTimes` | Ganzzahl | Runden um Sperrflächen.
Häufigkeit des Entleerens | `DumpPeriodSqm` | Ganzzahl | Wie oft der Fangkorb geleert wird.

## 3. Funktionen
Optionale Parameter gibt es nicht, alle Werte werden übergeben.

`void MAMM_Update(integer $InstanzID);`
Holt den Zustand des Mähers. Läuft automatisch im eingestellten Abstand und nach jedem Befehl.

`void MAMM_UpdatePlan(integer $InstanzID);`
Holt die Aufgabenliste und aktualisiert die Auswahl der Variable "Aufgabe starten".

`void MAMM_UpdateWorkParams(integer $InstanzID);`
Holt die Arbeitsparameter.

`boolean MAMM_Command(integer $InstanzID, string $Action, string $TaskName);`
Sendet einen Befehl: `CMD_START`, `START` (mit Aufgabenname), `PAUSE`, `RESUME`, `STOP`, `RETURN`, `CANCEL_RETURN`. Liefert `true`, wenn die Cloud den Befehl angenommen hat. Bei einem Fehler steht eine Warnung im Meldungsfenster.

`string MAMM_GetErrorCodes(integer $InstanzID, integer $Count);`
Liefert die letzten Fehlermeldungen des Mähers als JSON-Liste mit Code, Beschreibung, Lösung und Zeitpunkt.

`string MAMM_SetLocalAccess(integer $InstanzID, boolean $Enabled);`
Schaltet den lokalen Zugang des Mähers in der Cloud ein oder aus und liefert den Zustand als Text.

`string MAMM_GetLocalAccess(integer $InstanzID);`
Fragt den lokalen Zugang ab (aktiviert oder deaktiviert, Konfiguration zugestellt, ausstehend oder fehlgeschlagen).

`boolean MAMM_HandleEvent(integer $InstanzID, string $Event);`
Wird von der MammotionCloud mit dem Text eines Live-Ereignisses aufgerufen. Gehört das Ereignis zu diesem Mäher und läuft er im Live-Modus, wird kurz darauf der Zustand abgefragt (höchstens alle 10 Sekunden). Liefert `true`, wenn das Ereignis zu diesem Mäher gehört.

`boolean MAMM_FetchMaterial(integer $InstanzID);`
Veranlasst den Mäher, Material und Karten in die Cloud zu laden. Das Ergebnis kommt über die Live-Verbindung (nur LUBA 3 AWD).

## 4. Spenden
Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

[![PayPal](https://img.shields.io/badge/PayPal-Spenden-00457C?logo=paypal&logoColor=white&style=for-the-badge)](https://www.paypal.com/donate?hosted_button_id=EK4JRP87XLSHW) [![Amazon Wunschzettel](https://img.shields.io/badge/Amazon-Wunschzettel-FF9900?logo=amazon&logoColor=white&style=for-the-badge)](https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share)

## 5. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
