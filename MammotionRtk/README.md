# MammotionRtk
Eine RTK-Referenzstation von Mammotion. Die Instanz zeigt Modell, Firmware und Online-Zustand der Station und kann ihre Fehlermeldungen abrufen. Sie wird normalerweise über den MammotionConfigurator angelegt (Bereich "RTK-Stationen") und braucht eine MammotionCloud als Verbindung. Die Open API bietet für Stationen nicht mehr: Akku, Position oder Satellitenzahl liefert sie nicht.

## Inhaltsverzeichnis
- [MammotionRtk](#mammotionrtk)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [1. Konfiguration](#1-konfiguration)
  - [2. Variablen](#2-variablen)
  - [3. Funktionen](#3-funktionen)
  - [4. Spenden](#4-spenden)
  - [5. Lizenz](#5-lizenz)

## 1. Konfiguration

Feld | Beschreibung
------------ | ----------------
Geräte-ID | ID der Station in der Mammotion-Cloud. Pflichtfeld, wird vom Konfigurator gesetzt.
Gerätename | Name der Station. Wird vom Konfigurator gesetzt.
Abfrageintervall | Abstand der Abfragen in Sekunden. 0 schaltet die Abfrage aus, Werte von 1 bis 29 gelten als 30 Sekunden. Standard: 300.

**Kachel:** Die Instanz hat eine Kachel für die Kachel-Visualisierung mit Produktbild, Modell und Online-Zustand. Das Produktbild lädt die Instanz von der Mammotion-Cloud und legt es als Medienobjekt "Produktbild" lokal ab. Der Vollbildtyp der Kachel braucht Symcon 9.1, unter 9.0 bleibt die maximierte Ansicht leer.

## 2. Variablen

Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
Online | `Online` | Ja/Nein | Station ist mit der Cloud verbunden.
Modell | `Model` | Text | Modellbezeichnung, zum Beispiel RTK300.
Firmware | `Firmware` | Text | Firmware-Version.
Produktbild | `Image` | Medienobjekt (Bild) | Produktbild der Station, lokal abgelegt.

## 3. Funktionen
Optionale Parameter gibt es nicht, alle Werte werden übergeben.

`void MAMR_Update(integer $InstanzID);`
Holt Modell, Firmware und Online-Zustand. Läuft automatisch im eingestellten Abstand.

`string MAMR_GetErrorCodes(integer $InstanzID, integer $Count);`
Liefert die letzten Fehlermeldungen der Station als JSON-Liste mit Code, Beschreibung, Lösung und Zeitpunkt.

## 4. Spenden
Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

[![PayPal](https://img.shields.io/badge/PayPal-Spenden-00457C?logo=paypal&logoColor=white&style=for-the-badge)](https://www.paypal.com/donate?hosted_button_id=EK4JRP87XLSHW) [![Amazon Wunschzettel](https://img.shields.io/badge/Amazon-Wunschzettel-FF9900?logo=amazon&logoColor=white&style=for-the-badge)](https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share)

## 5. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
