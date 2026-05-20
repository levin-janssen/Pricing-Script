# Tricoma Pricing Script & Marketplace Feed Builder

Ein umfassendes, PHP-basiertes Tool zur automatisierten Preisberechnung, Verwaltung von Preisgrenzen sowie zur Generierung von Marketplace-Feeds. Das Tool integriert sich tief in externe Marktplätze wie Amazon (via Selling Partner API) und ManoMano und unterstützt bei der Margenkontrolle und automatischen Bestands- und Preisaktualisierungen.

---

## 📋 Inhaltsverzeichnis

1. [Über das Projekt](#über-das-projekt)
2. [Hauptfunktionen](#hauptfunktionen)
3. [Technologie-Stack](#technologie-stack)
4. [Projektstruktur](#projektstruktur)
5. [Voraussetzungen](#voraussetzungen)
6. [Installation & Setup](#installation--setup)
7. [Nutzung & Workflows](#nutzung--workflows)
8. [API Integrationen](#api-integrationen)
9. [Fehlerbehebung und Logs](#fehlerbehebung-und-logs)

---

## 💡 Über das Projekt

Dieses System wurde entwickelt, um die komplexe Preisgestaltung für verschiedene Marktplätze zu zentralisieren. Es bietet eine Benutzeroberfläche zur Überprüfung von Produktdaten, berechnet Durchschnitts- und Zielpreise und stellt sicher, dass gesetzte Preisgrenzen (Mindestpreis / Maximalpreis) eingehalten werden. Parallel erstellt es automatisch Feed-Dateien, um diese Datenbestände an Systeme wie Amazon und ManoMano zu übertragen.

---

## 🚀 Hauptfunktionen

* **Automatisierte Preisberechnung:** Dynamische Preisanpassung basierend auf aktuellen Einkaufspreisen, Durchschnittskosten und hinterlegten Margenvorgaben (`get_avg_price.php`, `get_current_price.php`).
* **Preisgrenzen-Kontrolle:** Strikte Einhaltung von Minimum- und Maximum-Preisen pro Kanal (`get_preisgrenzen.php`).
* **Amazon SP-API Integration:** Direkter Import von Amazon-Produktdetails sowie Update von Preisen via Amazon Selling Partner API.
* **Feed Generierung:** Automatisierter Aufbau von Export-Feeds für externe Plattformen (`AmazonFeedBuilder.php`, `ManoManoFeedBuilder.php`).
* **Such- und Analyse-Dashboard:** Übersichtliche Frontend-Suche zur Kontrolle von Artikelzuständen und Preisen (`search.php`, `results.php`).
* **Umfassendes Reporting:** Zentrale Fehlererfassung und Reporting für fehlgeschlagene API-Aufrufe oder unvollständige Daten (`report.php`, `error_report.php`).

---

## 🛠 Technologie-Stack

* **Backend:** PHP (unterstützt Composer für Abhängigkeiten)
* **Datenbank:** MySQL / MariaDB (für lokale Caching- und Mapping-Tabellen)
* **Frontend:** HTML5, CSS3 (`style.css`, `landingpage.css`, `results.css`, `addNew.css`)
* **APIs:** 
  * Amazon Selling Partner API (`amzn-spapi/sdk`)
  * AWS SDK für PHP (`aws/aws-sdk-php`)
* **Libraries:** GuzzleHTTP (HTTP Client), Dotenv (`vlucas/phpdotenv` für Umgebungsvariablen)

---

## 📂 Projektstruktur

Die Codebasis ist logisch in Kernfunktionen, API-Aufrufe, Frontend-Elemente und Abhängigkeiten unterteilt:

```text
/
├── index.php / checkindex.html    # Haupt-Einstiegspunkte (Dashboards und Landingpages)
├── db_connection.php              # Globale Datenbankverbindung
├── composer.json                  # Definition der PHP-Paketabhängigkeiten
│
├── 📊 Preis-Logik (Kern des Systems)
│   ├── pricing.php                # Hauptlogik für Preisupdates und -kalkulation
│   ├── get_current_price.php      # Ruft den aktuellen Marktplatz/Webshop-Preis ab
│   ├── get_avg_price.php          # Errechnet den gleitenden Durchschnittspreis (EK/VK)
│   └── get_preisgrenzen.php       # Holt und validiert die gesetzten Min-/Max-Preise
│
├── 🛒 Marktplatz-Schnittstellen (Feeds)
│   ├── marketplaces.php           # Zentrale Steuerdatei für alle Marktplätze
│   ├── AmazonFeedBuilder.php      # Logik zur Erstellung spezifischer Amazon XML/CSV Feeds
│   └── ManoManoFeedBuilder.php    # Logik zur Erstellung der ManoMano Händler-Feeds
│
├── 🔌 Amazon SP-API & Externe Daten
│   ├── sp_api_functions.php           # Helper-Funktionen zur Interaktion mit Amazon SP-API
│   └── get_amazon_product_details.php # Holt Produktdaten (ASIN, BuyBox-Preis, etc.)
│
├── 📋 Datenverwaltung & Formulare
│   ├── addNew.php (und /DE/addNew.php) # Web-Interface zum Hinzufügen neuer Artikel
│   ├── submit.php                 # Formular-Verarbeiter (Speichert Daten in die DB)
│   └── check.php                  # Validierungsskript für Bestandsdaten
│
├── 🔍 Produktsuche & Frontend
│   ├── search.php                 # Suchformular
│   ├── results.php                # Anzeigelogik der Suchergebnisse
│   └── *.css                      # Zugehörige Stylesheets (landingpage.css, results.css, ...)
│
├── 📈 Berichte & Logs
│   ├── report.php                 # Übersicht erfolgreicher Preis- & Feed-Updates
│   └── error_report.php           # Log für fehlgeschlagene API Calls, fehlende ASINs etc.
│
├── Workspace_products.php         # Generelle Datenverarbeitung für interne Tricoma Workspace Produkte
│
└── vendor/                        # Composer Packages (Guzzle, AWS, Amazon SDK, Dotenv etc.)
```

---

## ⚙️ Voraussetzungen

1. **Webserver:** Apache oder Nginx
2. **PHP:** Version 7.4 oder höher (Empfohlen: 8.x)
3. **Datenbank:** MySQL (ab Version 5.7) oder MariaDB (ab Version 10.x)
4. **Composer:** Zur Verwaltung der PHP-Abhängigkeiten.

---

## 🔧 Installation & Setup

1. **Repository klonen / übertragen**
   Stellen Sie sicher, dass alle Dateien auf dem zu verwendenden Webserver / in das Workspace-Verzeichnis hochgeladen sind.

2. **Abhängigkeiten installieren**
   Führen Sie im Stammverzeichnis folgendes Kommando aus, falls `vendor` nicht auf dem neuesten Stand ist:
   ```bash
   composer install
   ```

3. **Umgebungsvariablen konfigurieren**
   Kopieren Sie die `.env.example` (falls vorhanden) zu `.env` und füllen Sie die Zugangsdaten aus:
   * Datenbank-Zugangsdaten (Host, User, Pass, DB-Name)
   * Amazon SP-API Zugangsdaten (`LWA_CLIENT_ID`, `LWA_CLIENT_SECRET`, `AWS_IAM_USER_KEY`, etc.)
   * Weitere Tricoma / API Keys.

4. **Datenbank einrichten**
   Das System geht davon aus, dass die unter `db_connection.php` aufgerufene Struktur existiert. Importieren Sie notwendige Tabellen zum Speichern der ASIN-Verknüpfungen und Preisgrenzen im Vorfeld.

---

## 🖥 Nutzung & Workflows

### 1. Neues Produkt aufnehmen (`addNew.php`)
Ein Administrator öffnet das Formular via `addNew.php`, gibt die Produktkennung ein und wählt die gewünschten Preisstrategien oder -kanäle aus. Die Validierung und Speicherung erfolgt durch `submit.php`.

### 2. Preisaktualisierung anstoßen (`pricing.php`)
Die Preisroutinen können via Web-Aufruf oder als Cronjob (automatisiert im Hintergrund) angestoßen werden. 
* Es wird der Basispreis (`get_avg_price.php`) ermittelt.
* Das Skript vergleicht mit Amazon Wettbewerbern (`get_amazon_product_details.php`).
* Das finale Ergebnis wird durch `get_preisgrenzen.php` gegen Hard-Limits validiert.

### 3. Feeds für Marktplätze übermitteln
Die `AmazonFeedBuilder.php` und `ManoManoFeedBuilder.php` werden routinemäßig (z.B. per Cronjob) ausgeführt. Sie extrahieren geänderte Preis- / Bestandsdaten aus der Datenbank, generieren das erwartete API-Feed-Format und übermitteln dieses authentifiziert an das jeweilige Marktplatz-Netzwerk.

### 4. Produkte überprüfen
Über `search.php` kann intern nach Artikelnummern oder Bezeichnungen gesucht werden, um in `results.php` schnell herauszufinden: *Warum hat dieser Artikel welchen Preis?*

---

## 🌐 API Integrationen

Dieses Projekt verlässt sich stark auf externe Authentifizierungen. Die Sicherheit der API Tokens ist von höchster Bedeutung.
* **Amazon SP-API (Selling Partner API):** Genutzt für asynchrones Feeds Management (Pricing/Inventory) sowie Catalog Items API für BuyBox Analysen. (Genutzt wird das im `vendor/amzn-spapi/sdk` hinterlegte SDK sowie Guzzle).
* **AWS SDK:** Notwendig für Authentifizierungs-Signaturen (AWS Signature Version 4) zur Kommunikation mit den Amazon Endpunkten.

---

## 🚨 Fehlerbehebung und Logs

* **Weiße Seite / 500 Server Error:** PHP Error Logs aktivieren. Prüfen ob `.env` korrekt geladen wird (`vlucas/phpdotenv`).
* **Amazon API lehnt ab (403 Forbidden / 401 Unauthorized):** Überprüfen Sie, ob die LWA (Login with Amazon) Tokens in der `.env` abgelaufen sind oder die AWS IAM Policies aktualisiert werden müssen.
* **Preise Updaten nicht:** Konsultieren Sie `error_report.php` oder das System-Log. Manchmal werden Mindestmargen (Preisgrenze) unterschritten, weshalb der Algorithmus ein preisliches "Dumping" verweigert.

---

*Dieses README dokumentiert den aktuellen Stand des Tricoma Pricing Scripts und sollte von allen beteiligten Entwicklern kontinuierlich gepflegt und erweitert werden.*