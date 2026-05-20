# Interne Entwicklerdokumentation: Tricoma Pricing Script & Feed Builder

> **⚠️ WICHTIGER HINWEIS:** Dieses Projekt ist **ausschließlich für die interne Firmennutzung** bestimmt. Es ist eng mit dem lokalen Tricoma ERP verzahnt und darf nicht öffentlich zugänglich gemacht werden. Das Root-Verzeichnis der Applikation liegt im internen Netzwerk unter `\\192.168.3.191\tricoma Verzeichnis TEST\Pricing Script`.

Willkommen beim internen Pricing Script. Diese Dokumentation dient als zentraler Einstiegspunkt für Programmierer, die das System weiterentwickeln, warten oder debuggen müssen. Sie erklärt detailliert den Datenfluss, die Trigger-Punkte und das Zusammenspiel der internen Skripte.

---

## 🧭 Inhaltsverzeichnis

1. [Systemarchitektur & Setup für Entwickler](#systemarchitektur--setup-für-entwickler)
2. [Der generelle Datenfluss](#der-generelle-datenfluss)
3. [Detaillierte Workflows: Was passiert wann?](#detaillierte-workflows-was-passiert-wann)
4. [Dateien und ihre exakte Aufgabe](#dateien-und-ihre-exakte-aufgabe)
5. [Debugging & Logs](#debugging--logs)

---

## 🛠 Systemarchitektur & Setup für Entwickler

Das System fungiert als Middleware zwischen unserem Tricoma ERP-System (der "Source of Truth" für Produkt- und Bestandsdaten) und externen Marktplätzen wie Amazon und ManoMano. 

**Dein lokales Setup:**
Da es sich um intern genutzte Skripte handelt, entwickelst du größtenteils direkt gegen die Testumgebung (`\tricoma Verzeichnis TEST`).
* **PHP-Abhängigkeiten:** Werden über Composer verwaltet (`vendor/`). Solltest du eine neue Library brauchen, führe dort `composer require` aus. Wir nutzen z.B. Guzzle für externe Calls und das offizielle AWS/Amazon SP-API SDK.
* **Datenbank:** Die primäre Verbindung wird in **`config/db_connection.php`** konfiguriert (die Datei `db_connection.php` im Webroot lädt nur diese kanonische Datei). Greife niemals hart codiert auf Datenbanken zu, nutze immer die exportierte Verbindung.
* **Projektstruktur:** Öffentliche URLs bleiben unverändert (z. B. `search.php?country=DE`). Im Webroot liegen nur dünne **Einstiegs-Stubs**; die Implementierung steht unter **`src/Http/`**. Gemeinsamer Code: **`config/`** (DB, Marktplätze), **`src/Support/`**, **`src/Services/`**. Jeder Stub lädt **`bootstrap.php`** (`APP_ROOT`) und danach die passende Datei in `src/Http/`.
* **Migration großer Seiten:** `results.php`, `addNew.php`, `pricing.php` und `report.php` einmalig mit `php tools/migrate_http.php` auf dem Server nachziehen (kopiert nach `src/Http/` und ersetzt den Root durch Stubs). Danach optional `tools/migrate_http.php` löschen oder per Webserver sperren.
* **Secrets:** API-Keys (für Amazon SP-API, AWS IAM Nutzer etc.) liegen auf dem Server und werden dort verarbeitet. 

---

## 🔄 Der generelle Datenfluss

Wie fließen die Daten durch unser System?

1. **ERP zu Middleware:** Produkte und Basispreise (Einkaufspreis / Listenpreis) existieren im Tricoma.
2. **Kalkulation:** Das Script erfasst diese Basispreise, prüft die Durchschnittskosten und gleicht sie bei aktiviertem Repricing mit der aktuellen Amazon-BuyBox ab.
3. **Validierung:** Vor jeder Preisänderung wird hart gegen `get_preisgrenzen.php` validiert. Fällt ein Preis unter unsere Marge, blockt das Script.
4. **Export:** Das bestätigte Pricing-Update oder Bestandsupdate wird über die FeedBuilder gesammelt und via API an die Marktplätze verteilt.

---

## ⚙️ Detaillierte Workflows: Was passiert wann?

### Workflow 1: Das Onboarding eines neuen Artikels
Wird ein neuer Artikel für das Pricing-System freigeschaltet, passiert Folgendes:
1. Der Fachbereich nutzt **`addNew.php`** (oder dessen deutsche Variante `/DE/addNew.php`). In das UI werden Basisdaten gemappt (z.B. Tricoma SKU -> Amazon ASIN).
2. Das Formular schickt einen POST-Request an **`submit.php`**.
3. **`submit.php`** verarbeitet die Daten, führt einen Check über **`check.php`** aus, um Duplikate zu vermeiden, und schreibt die Mapping-Daten (z.B. Mindestpreis, Maximalpreis, ASIN) in die lokale Tracking-Datenbank.

### Workflow 2: Das dynamische Repricing (Automatischer Run)
Dieser Prozess wird normalerweise via Cronjob, seltener manuell über das Dashboard, angestoßen.
1. **Initialisierung:** **`pricing.php`** liest alle aktiven Produkte aus der Datenbank aus, die für ein Update geflaggt sind.
2. **Preisermittlung:** 
   * **`get_avg_price.php`** zieht den durchschnittlichen Einkaufspreis, berechnet Aufschläge (Marge, Versand) und ermittelt unseren "Soll"-Preis.
   * **`get_current_price.php`** holt parallel die aktuelle Datenlage (Wie teuer bieten wir es aktuell an?).
3. **Konkurrenzanalyse (nur Amazon):** 
   * **`get_amazon_product_details.php`** nutzt das SP-API SDK in `vendor/amzn-spapi`, um die aktuelle BuyBox der gepflegten ASIN anzufragen.
4. **Schranken-Prüfung:** Das wichtigste Skript hierbei ist **`get_preisgrenzen.php`**. Es vergleicht alle ermittelten Werte. Wenn z.B. die Amazon-BuyBox bei 10 € liegt, unsere Grenze (`get_preisgrenzen`) aber bei 12 € hart abriegelt, wird der Preis *nicht* auf 10 € gesenkt, um Verluste zu vermeiden!
5. **DB Update:** Der finale, sichere Preis wird temporär in unsere Datenbank zurückgeschrieben.

### Workflow 3: Feed-Generierung & Marktplatz-Upload
Der Preis ist nun systemseitig berechnet, muss aber zum Marktplatz:
1. Dateien wie **`AmazonFeedBuilder.php`** und **`ManoManoFeedBuilder.php`** werden über **`marketplaces.php`** aufgerufen.
2. Sie sammeln alle Produkte mit neuen Preisen oder Beständen aus der Datenbank und konvertieren diese in das zielgenaue Format (für Amazon meist spezielles XML oder Flat File JSON/CSV).
3. Via AWS Signature / SP-API **`sp_api_functions.php`** wird der Payload an Amazon hochgeladen.
4. Für ManoMano geschieht ein ähnlicher Netzwerkkall über cURL / Guzzle.
5. Erfolgreiche Uploads triggern einen Eintrag in **`report.php`**. Fehler landen in **`error_report.php`**.

### Workflow 4: Interne Suche / Supportfälle prüfen
Ein Sachbearbeiter ruft im Intranet **`search.php`** auf, wenn ein Preis auf Amazon nicht stimmt.
1. Eingabe der SKU.
2. Formular leitet an **`results.php`** weiter.
3. `results.php` lädt die CSS-Styles aus `results.css`, quert die Datenbank (letzter Pricing-Run, gesetzte Preisgrenzen) und gibt dem Sachbearbeiter visuell exakt aus, an welchem Limit (`get_preisgrenzen.php`) das Script gescheitert ist (z.B. "Buybox bei 5€, aber interner Mindestpreis bei 7€").

---

## 📁 Ordnerübersicht (für neue Entwickler)

| Pfad | Rolle |
|------|--------|
| **`*.php` (Webroot)** | Öffentliche URLs — nur Stubs (`bootstrap` + `src/Http/…`) |
| **`src/Http/`** | Seiten und AJAX-Endpunkte (eigentliche Logik) |
| **`config/`** | `db_connection.php`, `marketplaces.php` |
| **`src/Services/`** | SP-API, Amazon/ManoMano Feed Builder |
| **`src/Support/`** | `Logger.php` |
| **`bootstrap.php`** | Setzt `APP_ROOT` |

Shims im Webroot (`db_connection.php`, `Logger.php`, …) leiten auf `config/` bzw. `src/` weiter, falls alte `require`-Pfade noch vorkommen.

---

## 📂 Dateien und ihre exakte Aufgabe (Glossar)

* **`Workspace_products.php`**: Handhabt die Synchronisation der Basis-Produktdaten aus dem reinen Tricoma-Workspace in unser Pricing-Script.
* **`config/db_connection.php`** (über **`db_connection.php`** im Root eingebunden): PDO-Verbindungen und SP-API-Konstanten.
* **`config/marketplaces.php`** (über **`marketplaces.php`** im Root): Marktplatz-Matrix inkl. URLs zu den bestehenden Tools (`search.php?country=…`).
* **`src/Services/sp_api_functions.php`**: Enthält alle Kernfunktionen zur Amazon Selling Partner API (Authentifizierung, Refresh-Tokens holen via LWA, Token rotieren). *Finger weg, wenn man sich mit AWS Signature V4 nicht auskennt!* Die Datei **`sp_api_functions.php`** im Webroot leitet nur noch hierher weiter.
* **`src/Services/AmazonFeedBuilder.php`** / **`ManoManoFeedBuilder.php`**: Feed-Aufbereitung; die gleichnamigen Dateien im Root sind Weiterleitungen.
* **`src/Support/Logger.php`**: Dateilogging nach `logs/` unter dem Projektroot.
* **`get_amazon_product_details.php`**: Spezielles Skript, das die Amazon Catalog API / Pricing API anfragt. Nutzt Methoden aus `sp_api_functions.php`.
* **Frontend-Dateien (`addNew.css`, `landingpage.css`, `style.css`)**: Das Design der Benutzeroberflächen für Sachbearbeiter.

---

## 🩺 Debugging & Logs

Wenn etwas schiefläuft:

1. **Preis wurde auf Amazon nicht aktualisiert?**
   * Schau im Browser in die `error_report.php` – hier werden API-Limits (Throttling) der SP-API geloggt.
   * Prüfe via `search.php` für die SKU, ob `get_preisgrenzen.php` das Setzen unterbunden hat.

2. **Feeds gehen nicht durch?**
   * Prüfe, ob die Tokens (Refresh Token) der SP-API noch gültig sind.
   * Kontrolliere die Syntax, die `AmazonFeedBuilder.php` generiert, indem du das Skript lokal dumpst (`var_dump` oder `error_log`, aber *nicht* den echten Feed-Submit Befehl aktivieren).

3. **Neues Feld von Tricoma hinzufügen?**
   * Ändere den Sync in `Workspace_products.php` und erweitere die lokalen DB-Tabellen. Gegebenenfalls dann im `$feed_array` in z.B. `AmazonFeedBuilder.php` ergänzen. 

Bei Fragen zur Infrastruktur oder Zugriff auf die `.env` Datei wende dich an deinen IT-Administrator.