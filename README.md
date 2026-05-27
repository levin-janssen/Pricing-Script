# Tricoma Pricing Script (intern)

> **Hinweis:** Dieses Projekt ist ausschließlich für die interne Nutzung bestimmt und ist eng mit dem Tricoma ERP verzahnt. Keine öffentliche Bereitstellung.

## Kurzüberblick

Das Pricing Script ist eine Middleware zwischen Tricoma und Marktplätzen wie Amazon und ManoMano. Es berechnet Preise, setzt harte Preisgrenzen, erstellt Feeds und stellt interne UIs für Support und Pflege bereit.

## Funktionen im Überblick

- Preisberechnung auf Basis von Einkaufspreisen, Aufschlägen und Versand
- Repricing gegen Amazon-BuyBox (SP-API)
- Harte Preisgrenzen zur Margensicherung
- Feed-Generierung und Upload für Amazon/ManoMano
- Interne UIs: Artikel anlegen, prüfen, suchen, Reports
- Logging und Fehlerreporting

## Technischer Ablauf (vereinfacht)

1. Ein Einstiegspunkt im Webroot (z. B. `pricing.php`, `search.php`) lädt `bootstrap.php` und delegiert nach `src/Http/`.
2. Die eigentliche Logik in `src/Http/` nutzt Konfigurationen aus `config/` und Services aus `src/Services/`.
3. Pricing-Run:
   - `pricing.php` lädt aktive Produkte.
   - `get_avg_price.php` und `get_current_price.php` ermitteln Basiswerte.
   - `get_amazon_product_details.php` holt Marktdaten von Amazon.
   - `get_preisgrenzen.php` validiert den Zielpreis.
   - Ergebnis wird in der lokalen DB gespeichert.
4. Feed-Upload:
   - `marketplaces.php` startet `AmazonFeedBuilder.php` oder `ManoManoFeedBuilder.php`.
   - `sp_api_functions.php` signiert Requests und sendet den Feed an Amazon.
5. Support-Flow:
   - `search.php` -> `results.php` zeigt letzte Runs und Preisgrenzen.
6. Logs laufen über `src/Support/Logger.php` nach `logs/`, Fehler in `error_report.php`.

## Projektstruktur

| Pfad | Rolle |
|------|-------|
| `*.php` (Webroot) | Öffentliche URLs, nur Stubs und Weiterleitungen |
| `src/Http/` | Seiten und Endpunkte, eigentliche Logik |
| `src/Services/` | Marktplatz-Integrationen und Feed Builder |
| `src/Support/` | Logger und Hilfsfunktionen |
| `config/` | DB- und Marktplatz-Konfiguration |
| `bootstrap.php` | Setzt `APP_ROOT` und startet die App |

## Technik und Abhängigkeiten

- PHP (klassisches Skript-Setup, keine Framework-Routing-Schicht)
- Composer für Libraries (`vendor/`)
- AWS SDK und Amazon SP-API SDK
- Guzzle für HTTP Requests
- PDO für Datenbankzugriffe

## Konfiguration und Secrets

- `config/db_connection.php` definiert PDO-Verbindungen und API-Konstanten.
- `config/marketplaces.php` enthält Marktplatz-Definitionen und Links.
- Secrets (Tokens, Keys) liegen serverseitig und werden nicht im Repo gepflegt.

## Einstieg für Entwickler

1. Projekt im Netzlaufwerk öffnen (Test-Umgebung).
2. Falls `vendor/` fehlt oder aktualisiert werden muss: `composer install`.
3. Schreibrechte für `logs/` sicherstellen.
4. Einstiegspunkte über die bekannten Web-URLs testen (z. B. `search.php`).

## Debugging-Hinweise

- `error_report.php` zeigt API- und Upload-Fehler.
- `log_viewer.php` bzw. Dateien in `logs/` zeigen Detail-Logs.
- Bei Preisabweichungen: `search.php` -> `results.php` prüfen, ob `get_preisgrenzen.php` blockiert hat.