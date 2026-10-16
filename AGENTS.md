# Projektregeln

Lies zuerst das zentrale Regelwerk `E:\IP-Symcon\KI\AGENTS.md` und die für die Aufgabe relevanten Dokumente unter `E:\IP-Symcon\KI\standards\`. Es gilt für dieses Repository.

## Projektspezifischer Kontext

- Zweck: Mammotion-Mäher (LUBA, YUKA) über die offizielle Mammotion Open API in IP-Symcon steuern und anzeigen.
- Relevante Module: MammotionCloud (I/O), MammotionConfigurator, MammotionMower, MammotionRtk.
- Projektkontext: [`PROJECT_CONTEXT.md`](PROJECT_CONTEXT.md)
- Zusätzliche Einstiegspunkte: `libs/MammotionApi.php` (HTTP-Client der Open API).

## Ergänzungen oder Abweichungen

- Die Verzeichnisse `_Mammotion` und `BackupMammotion` neben diesem Projekt sind ein früherer, inoffizieller Ansatz über die App-Cloud (Aliyun-MQTT). Sie sind nicht Teil dieses Repositories. Aus ihnen wird nichts kopiert, auch keine GUIDs. Ihr Protobuf-Decoder ist die Grundlage für die geplante lokale Verbindung und wird dafür bewusst und getrennt übernommen.
- Öffentliche Methoden der Module haben keine optionalen Parameter: Die von Symcon erzeugten Funktionen (`MAMC_…`, `MAMM_…`) lehnen sie ab (geprüft auf Symcon 9.1).
