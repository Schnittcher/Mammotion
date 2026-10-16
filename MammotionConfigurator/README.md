# MammotionConfigurator
Listet die Mäher des Mammotion-Kontos und legt die Geräteinstanzen (MammotionMower) mit Geräte-ID und Gerätenamen an. Bereits angelegte Mäher werden erkannt und mit ihrer Instanz verknüpft. Die Instanz braucht eine verbundene und angemeldete MammotionCloud.

## Inhaltsverzeichnis
- [MammotionConfigurator](#mammotionconfigurator)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [1. Konfiguration](#1-konfiguration)
  - [2. Variablen](#2-variablen)
  - [3. Spenden](#3-spenden)
  - [4. Lizenz](#4-lizenz)

## 1. Konfiguration

Feld | Beschreibung
------------ | ----------------
Geräte | Eine Liste als Baum mit den Gruppen "Mäher" und "RTK-Stationen". Eine Gruppe erscheint nur, wenn sie Einträge hat. Die Liste wird beim Öffnen des Formulars einmal abgefragt und bleibt leer, solange die MammotionCloud nicht angemeldet ist.

Gruppe | Beschreibung
------------ | ----------------
Mäher | Die Mäher des Kontos mit Name (Spitzname, sonst Gerätename), Modell und Zustand (Online oder Offline). Mit "Erstellen" wird die Instanz MammotionMower angelegt.
RTK-Stationen | Geräte, deren Modellname mit "RTK" beginnt. Mit "Erstellen" wird die Instanz MammotionRtk angelegt. Die Cloud liefert Stationen in derselben Geräteliste wie die Mäher, deshalb trennt der Konfigurator sie.

## 2. Variablen
Die Instanz legt keine Variablen an.

## 3. Spenden
Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

[![PayPal](https://img.shields.io/badge/PayPal-Spenden-00457C?logo=paypal&logoColor=white&style=for-the-badge)](https://www.paypal.com/donate?hosted_button_id=EK4JRP87XLSHW) [![Amazon Wunschzettel](https://img.shields.io/badge/Amazon-Wunschzettel-FF9900?logo=amazon&logoColor=white&style=for-the-badge)](https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share)

## 4. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
