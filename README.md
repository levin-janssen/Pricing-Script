# Pricing & Inventory Script

Tool zur Preisberechnung und Bestandssynchronisation zwischen der lokalen Datenbank und angebundenen Marktplätzen (Amazon, Otto, eBay, ManoMano).

## Projektstruktur

- `/config/` - Konfigurationen (DB-Verbindung, Environment-Loader, Marktplatz-Parameter)
- `/src/Http/` - Web-Interfaces & Entrypoints (z.B. `pricing.php`, `bestandsabweichungen.php`)
- `/src/Services/` - Kernlogik, API-Kommunikation & Feed-Builder (z.B. `sp_api_functions.php`)
- `/src/Support/` - Hilfsklassen (z.B. `Logger.php`)
- `/logs/` - Error- und API-Logs 

## Häufige Aufgaben

### API-Keys & Secrets aktualisieren
- `.env` Datei im Root-Verzeichnis anpassen

### Neuen Marktplatz hinzufügen
- Parameter in `config/marketplaces.php` ergänzen.

### Bestandsabweichungen analysieren
1. Betroffene SKUs in `bestandsabweichungen.php` finden.
2. Über `log_viewer.php` oder die Dateien in `/logs/` prüfen, ob API-Limits (Rate Limiting) oder Verbindungsausfälle vorliegen.

### Anpassungen an der Amazon SP-API
- API-Verbindungslogik anpassen in: `src/Services/sp_api_functions.php`
- Payload/Feed-Struktur anpassen in: `src/Services/AmazonFeedBuilder.php`