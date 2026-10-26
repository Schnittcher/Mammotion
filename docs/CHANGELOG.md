# Changelog

## 26.10.2026 - Version 1.0.2

### Fixes
- "Lädt" gilt, solange die Cloud einen Ladestatus über 0 meldet, auch bei vollem Akku am Dock (Yuka Mini 600, LUBA 2).

## 25.10.2026 - Version 1.0.1

### Neu
- Das Debug-Fenster des Mähers zeigt bei jeder Abfrage Zustand, Ladestatus und Akku.

### Fixes
- Der Zustand "TaskPaused" (Yuka Mini 600) wird als "Pausiert" angezeigt.
- Mäher und RTK-Station lassen sich wieder anlegen, wenn die Kachel-Konstanten in der Symcon-Umgebung fehlen (Fehler "Undefined constant INSTANCE_VISUALIZATION_TYPE_HTML").
- Beim Anlegen von Konfigurator und Mäher erscheint keine Warnung zu ConnectParent mehr, und Mäher lassen sich über den Konfigurator anlegen.
- Meldet die Cloud einen unbekannten Zustand, steht der Wert im Debug-Fenster der Instanz.
- Lehnt die Cloud "Mähen starten" ab (zum Beispiel beim Yuka Mini 600), weist die Warnung jetzt darauf hin, stattdessen eine Aufgabe zu starten.

## 15.10.2026 - Version 1.0

### Neu
- Erste Version: Mammotion-Mäher über die offizielle Open API in IP-Symcon.
- Mähen starten, pausieren, fortsetzen, stoppen und zur Ladestation schicken, außerdem Aufgaben aus der Mammotion-App starten.
- Zustand, Akku, Ladestatus, Firmware und Netzwerk der Mäher als Variablen.
- Arbeitsparameter und Fehlermeldungen der Mäher abrufen.
- RTK-Referenzstationen als eigene Instanz mit Online-Zustand und Firmware.
- Live-Aktualisierung für LUBA 3 AWD.
- Konfigurator legt die Mäher des Kontos an.
- Mäher und RTK-Stationen haben eine Kachel für die Kachel-Visualisierung mit lokal abgelegtem Produktbild; bei Mähern mit Bedienung.
- Der lokale Zugang eines Mähers lässt sich in der Mammotion-Cloud freischalten und abfragen.
