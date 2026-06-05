<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('default_charset', 'UTF-8');
ini_set('error_log', APP_ROOT . '/error.log');

require_once APP_ROOT . '/src/Services/sp_api_functions.php'; // Enthält callItemsAPI, getInfoByASIN, etc.
require_once APP_ROOT . '/config/db_connection.php';
require_once APP_ROOT . '/config/marketplaces.php'; // To get DB names for Buybox tables

$dbConnection = $dbConnectionTric4Calc;
$message = '';
$message_type = '';

// --- Determine current country from directory path ---
$current_marketplace_code = isset($_GET['country']) ? strtoupper(filter_input(INPUT_GET, 'country', FILTER_SANITIZE_STRING)) : (isset($_POST['country']) ? strtoupper(filter_input(INPUT_POST, 'country', FILTER_SANITIZE_STRING)) : ''); if(empty($current_marketplace_code)) die("Missing country in addNew.php"); // Ensure consistency, e.g., IT
$currency_symbol = '€'; // Default currency symbol

// Validate if the determined marketplace code is valid
if (!isset($marketplaces[$current_marketplace_code])) {
    $message = 'Fehler: Unbekannter Marketplace-Code aus Verzeichnispfad: ' . htmlspecialchars($current_marketplace_code);
    $message_type = 'error';
} else {
    $currency_symbol = $marketplaces[$current_marketplace_code]['currencyCode'] === 'GBP' ? '£' : ($marketplaces[$current_marketplace_code]['currencyCode'] === 'SEK' ? 'kr' : '€');
}

$country_specific_buybox_table = '';
if (isset($marketplaces[$current_marketplace_code]['dbName'])) {
    $country_specific_buybox_table = $marketplaces[$current_marketplace_code]['dbName'];
} else {
    if ($message_type !== 'error') {
      $message = 'Fehler: Konnte den spezifischen Buybox-Tabellennamen für ' . htmlspecialchars($current_marketplace_code) . ' nicht finden.';
      $message_type = 'error';
    }
}

if (isset($marketplaces[$current_marketplace_code]['name'])) {
    $current_marketplace_name = $marketplaces[$current_marketplace_code]['name'];
}

// --- Variablen initialisieren ---
$action_type_submitted = filter_input(INPUT_POST, 'action_type', FILTER_SANITIZE_STRING) ?: 'update'; 
$asin_submitted = filter_input(INPUT_POST, 'asin', FILTER_SANITIZE_STRING) ?: '';
$produktid_submitted = filter_input(INPUT_POST, 'produktid', FILTER_VALIDATE_INT) ?: null; 

$min_preis_str = filter_input(INPUT_POST, 'min_preis') ?: '';
$max_preis_str = filter_input(INPUT_POST, 'max_preis') ?: '';
$stepsize_small_str = filter_input(INPUT_POST, 'stepsize_small') ?: '0.01';
$stepsize_big_str = filter_input(INPUT_POST, 'stepsize_big') ?: '0.10';

// Neue Felder für Create (Artikel table)
$new_artikelname_submitted = filter_input(INPUT_POST, 'new_artikelname', FILTER_SANITIZE_STRING) ?: '';
$new_ean_submitted = filter_input(INPUT_POST, 'new_ean', FILTER_SANITIZE_STRING) ?: '';
$new_sku_submitted = filter_input(INPUT_POST, 'new_sku', FILTER_SANITIZE_STRING) ?: '';


if ($_SERVER['REQUEST_METHOD'] === 'POST' && $message_type !== 'error') {

    $min_preis = filter_var(str_replace(',', '.', $min_preis_str), FILTER_VALIDATE_FLOAT);
    $max_preis = filter_var(str_replace(',', '.', $max_preis_str), FILTER_VALIDATE_FLOAT);
    $stepsize_small = filter_var(str_replace(',', '.', $stepsize_small_str), FILTER_VALIDATE_FLOAT);
    $stepsize_big = filter_var(str_replace(',', '.', $stepsize_big_str), FILTER_VALIDATE_FLOAT);

    $validation_errors = [];
    if ($min_preis === false || $min_preis < 0) {
        $validation_errors[] = 'Ungültiger Minimalpreis.';
    }
    if ($max_preis === false || $max_preis < 0) {
        $validation_errors[] = 'Ungültiger Maximalpreis.';
    }
    if ($min_preis !== false && $max_preis !== false && $min_preis > $max_preis) {
        $validation_errors[] = 'Minimalpreis darf nicht größer als Maximalpreis sein.';
    }
    if ($stepsize_small === false || $stepsize_small <= 0) {
        $validation_errors[] = 'Kleine Schrittgröße muss > 0 sein.';
    }
    if ($stepsize_big === false || $stepsize_big <= 0) {
        $validation_errors[] = 'Große Schrittgröße muss > 0 sein.';
    }
    if ($stepsize_small !== false && $stepsize_big !== false && $stepsize_small > $stepsize_big) {
         $validation_errors[] = 'Kleine Schrittgröße darf nicht größer als große Schrittgröße sein.';
    }
    if (empty($asin_submitted) || !preg_match('/^[A-Z0-9]{10}$/', $asin_submitted)) {
        $validation_errors[] = 'Ungültige oder fehlende ASIN.';
    }

    $artikel_exists = false;
    $current_artikel_id = $produktid_submitted;

    if ($action_type_submitted === 'create') {
        if (empty($new_artikelname_submitted)) {
            $validation_errors[] = 'Produktname ist erforderlich für neuen Artikel.';
        }
        if (empty($new_ean_submitted)) {
            $validation_errors[] = 'EAN ist erforderlich für neuen Artikel.';
        }
        if (empty($new_sku_submitted)) {
            $validation_errors[] = 'Seller SKU ist erforderlich für neuen Artikel.';
        }
        try {
             $stmtCheck = $dbConnection->prepare("SELECT ID FROM Artikel WHERE asin = :asin OR sku = :sku LIMIT 1");
             $stmtCheck->bindParam(':asin', $asin_submitted);
             $stmtCheck->bindParam(':sku', $new_sku_submitted);
             $stmtCheck->execute();
             if ($stmtCheck->fetchColumn()) {
                 $validation_errors[] = 'Ein Artikel mit dieser ASIN oder SKU existiert bereits in der Tabelle Artikel.';
             }
        } catch (\PDOException $e) {
             error_log("DB Fehler bei Artikel Duplikatprüfung: " . $e->getMessage());
             $validation_errors[] = 'Fehler bei Prüfung auf Duplikate in Artikel.';
        }
    } else {
        if (empty($current_artikel_id)) {
            try {
                $stmtCheck = $dbConnection->prepare("SELECT ID FROM Artikel WHERE asin = :asin LIMIT 1");
                $stmtCheck->bindParam(':asin', $asin_submitted);
                $stmtCheck->execute();
                $fetched_id = $stmtCheck->fetchColumn();
                if ($fetched_id) {
                    $current_artikel_id = $fetched_id;
                    $artikel_exists = true;
                } else {
                    $validation_errors[] = 'Artikel mit ASIN ' . htmlspecialchars($asin_submitted) . ' nicht in der Datenbank gefunden. Legen Sie ihn zuerst an oder wählen Sie "Neues Produkt anlegen".';
                }
            } catch (\PDOException $e) {
                error_log("DB Fehler bei Artikel ID Suche: " . $e->getMessage());
                $validation_errors[] = 'Fehler bei Suche nach Artikel ID.';
            }
        } else {
             $artikel_exists = true;
        }
    }

    if (!empty($validation_errors)) {
        $message = 'Validierungsfehler: ' . implode(' ', $validation_errors);
        $message_type = 'error';
    } else {
        $dbConnection->beginTransaction();
        try {
            // Step 1: Ensure Artikel entry exists or create it
            if ($action_type_submitted === 'create' && !$artikel_exists) {
                 $stmtArtikel = $dbConnection->prepare(
                     "INSERT INTO Artikel (artikelname, ean, asin, sku)
                      VALUES (:artikelname, :ean, :asin, :sku)"
                 );
                 $ean_to_insert = !empty($new_ean_submitted) ? $new_ean_submitted : null;
                 $stmtArtikel->bindParam(':artikelname', $new_artikelname_submitted);
                 $stmtArtikel->bindParam(':ean', $ean_to_insert, $ean_to_insert !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                 $stmtArtikel->bindParam(':asin', $asin_submitted);
                 $stmtArtikel->bindParam(':sku', $new_sku_submitted);
                 $stmtArtikel->execute();
                 $current_artikel_id = $dbConnection->lastInsertId();
                 $message = 'Neuer Artikel in "Artikel" angelegt. ';
            }

            if (!$current_artikel_id) {
                throw new Exception("Konnte Artikel ID nicht bestimmen.");
            }

            // Step 2: Insert or Update Preisgrenzen table
            $stmtCheckPg = $dbConnection->prepare("SELECT ASIN FROM Preisgrenzen WHERE ASIN = :asin AND Land = :land LIMIT 1");
            $stmtCheckPg->bindParam(':asin', $asin_submitted);
            $stmtCheckPg->bindParam(':land', $current_marketplace_code);
            $stmtCheckPg->execute();
            $preisgrenze_exists = $stmtCheckPg->fetchColumn();

            if ($preisgrenze_exists) {
                $stmtPreisgrenzen = $dbConnection->prepare(
                    "UPDATE Preisgrenzen SET min_preis = :min_preis, max_preis = :max_preis, stepsize_small = :stepsize_small, stepsize_big = :stepsize_big
                     WHERE ASIN = :asin AND Land = :land"
                );
                $message .= 'Preisgrenzen für Land ' . htmlspecialchars($current_marketplace_code) . ' aktualisiert. ';
            } else {
                $stmtPreisgrenzen = $dbConnection->prepare(
                    "INSERT INTO Preisgrenzen (ASIN, Land, min_preis, max_preis, stepsize_small, stepsize_big)
                     VALUES (:asin, :land, :min_preis, :max_preis, :stepsize_small, :stepsize_big)"
                );
                $message .= 'Preisgrenzen für Land ' . htmlspecialchars($current_marketplace_code) . ' neu angelegt. ';
            }
            $stmtPreisgrenzen->bindParam(':asin', $asin_submitted);
            $stmtPreisgrenzen->bindParam(':land', $current_marketplace_code);
            $stmtPreisgrenzen->bindParam(':min_preis', $min_preis);
            $stmtPreisgrenzen->bindParam(':max_preis', $max_preis);
            $stmtPreisgrenzen->bindParam(':stepsize_small', $stepsize_small);
            $stmtPreisgrenzen->bindParam(':stepsize_big', $stepsize_big);
            $stmtPreisgrenzen->execute();

            // Step 3: Initialize Buybox (using country specific table)
            $apiData = callItemsAPI($asin_submitted, $marketplaces[$current_marketplace_code]['marketplaceId']);
            $eigenerPreis = getOwnPriceByASIN($asin_submitted, $marketplaces[$current_marketplace_code]['marketplaceId']);
            $buyboxpreis = getInfoByASIN($apiData, "buyboxpreis");
            $niedrigsterPreis = getLowestPrice($apiData);


             if ($apiData === null) {
                 throw new Exception("Fehler beim Abrufen der Preisdaten von der Amazon API für ASIN $asin_submitted für Marketplace $current_marketplace_code.");
             }

            if (!empty($country_specific_buybox_table)) {
                $stmtBuybox = $dbConnection->prepare(
                    "INSERT INTO $country_specific_buybox_table (produktid, eigenerPreis, niedrigsterPreis, buyboxPreis, datum, action)
                     VALUES (:produktid, :eigenerPreis, :niedrigsterPreis, :buyboxPreis, NOW(), 'init')"
                );
                $stmtBuybox->bindParam(':produktid', $current_artikel_id, PDO::PARAM_INT);
                $stmtBuybox->bindValue(':eigenerPreis', $eigenerPreis, $eigenerPreis !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmtBuybox->bindValue(':niedrigsterPreis', $niedrigsterPreis, $niedrigsterPreis !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmtBuybox->bindValue(':buyboxPreis', $buyboxpreis, $buyboxpreis !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
                $stmtBuybox->execute();
                $message .= strtoupper($current_marketplace_code) . '-BuyBox initialisiert.';
            } else {
                 throw new Exception("Buybox Tabellenname für $current_marketplace_code nicht konfiguriert.");
            }

            $dbConnection->commit();
            $message_type = 'success';

            // Reset form values
            $asin_submitted = '';
            $produktid_submitted = null;
            $min_preis_str = '';
            $max_preis_str = '';
            $stepsize_small_str = '0.01';
            $stepsize_big_str = '0.10';
            $new_artikelname_submitted = '';
            $new_ean_submitted = '';
            $new_sku_submitted = '';
            $action_type_submitted = 'update';

        } catch (\PDOException $e) {
            if ($dbConnection->inTransaction()) $dbConnection->rollBack();
            error_log("Datenbankfehler in addNew.php POST ($current_marketplace_code): " . $e->getMessage());
            $message = "DB Fehler: " . htmlspecialchars($e->getMessage());
            $message_type = 'error';
        } catch (\Exception $e) {
             if ($dbConnection->inTransaction()) $dbConnection->rollBack();
             error_log("Allgemeiner Fehler in addNew.php POST ($current_marketplace_code): " . $e->getMessage());
             $message = "Fehler: " . htmlspecialchars($e->getMessage());
             $message_type = 'error';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Produkt anlegen - <?= htmlspecialchars(strtoupper($current_marketplace_code)) ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="img/tag.ico" sizes="32x32">

    <style>
        :root {
            --ink: #0f172a;
            --muted: #64748b;
            --muted-light: #94a3b8;
            --accent: #ea580c;
            --primary: #2563eb;
            --surface: #ffffff;
            --surface-soft: #f8fafc;
            --stroke: #e2e8f0;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            --radius: 12px;
            --radius-sm: 8px;
            --ring: rgba(37, 99, 235, 0.25);
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: "Space Grotesk", system-ui, -apple-system, sans-serif;
            color: var(--ink);
            background: radial-gradient(1200px circle at top left, #fff1e6 0%, #f2f6ff 42%, #eefbf7 70%, #f4f4f4 100%);
            background-attachment: fixed;
            padding: 40px 20px 80px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .page {
            max-width: 900px;
            margin: 0 auto;
        }

        /* --- Header Actions --- */
        .top-nav {
            margin-bottom: 24px;
        }

        .btn-ghost {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 42px;
            padding: 0 20px;
            border-radius: var(--radius-sm);
            font-family: inherit;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            background: var(--surface);
            color: var(--ink);
            border: 1px solid var(--stroke);
        }
        .btn-ghost:hover {
            background: var(--surface-soft);
            border-color: var(--muted-light);
        }

        /* --- Hero Section --- */
        .hero {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 32px;
        }

        .hero .eyebrow {
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--primary);
        }

        .hero h1 {
            font-size: 2.25rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .flag-icon {
            height: 1.1em;
            border-radius: 4px;
            box-shadow: var(--shadow-sm);
        }

        /* --- Alerts --- */
        .alert {
            padding: 16px 20px;
            border-radius: var(--radius-sm);
            margin-bottom: 24px;
            font-size: 0.95rem;
            font-weight: 500;
            display: flex;
            align-items: center;
        }
        .alert.error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .alert.success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0; }

        /* --- Form & Panel --- */
        .panel {
            background: var(--surface);
            padding: 32px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--stroke);
        }

        .panel h3 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--ink);
            border-bottom: 1px solid var(--stroke);
            padding-bottom: 12px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 24px;
        }

        .field label {
            font-size: 0.9rem;
            font-weight: 600;
            color: var(--ink);
        }
        
        .field label.required::after {
            content: " *";
            color: var(--accent);
        }

        input[type="text"], input[type="number"], select {
            width: 100%;
            height: 46px;
            padding: 0 16px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--stroke);
            font-family: inherit;
            font-size: 1rem;
            background: var(--surface);
            color: var(--ink);
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
        }
        
        input[type="text"]::placeholder, input[type="number"]::placeholder {
            color: var(--muted-light);
        }

        input:hover, select:hover { border-color: var(--muted-light); }
        input:focus, select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--ring);
        }

        /* --- Info Boxes (for dynamic JS results) --- */
        .info-box {
            background: var(--surface-soft);
            border: 1px solid var(--stroke);
            padding: 16px;
            border-radius: var(--radius-sm);
            font-size: 0.95rem;
            color: var(--muted);
            margin-bottom: 24px;
            line-height: 1.5;
        }
        
        .info-box strong {
            color: var(--ink);
        }

        /* --- Grids for layout --- */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0 20px;
        }
        
        .grid-3 {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 0 20px;
        }

        /* --- Buttons --- */
        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 48px;
            padding: 0 24px;
            border-radius: var(--radius-sm);
            font-family: inherit;
            font-weight: 600;
            font-size: 1rem;
            cursor: pointer;
            transition: all 0.2s ease;
            background: var(--primary);
            color: white;
            border: none;
            box-shadow: var(--shadow-sm);
            width: 100%;
            margin-top: 10px;
        }

        .btn-primary:hover:not(:disabled) {
            background: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }
        
        .btn-primary:disabled {
            background: var(--stroke);
            color: var(--muted);
            cursor: not-allowed;
            box-shadow: none;
        }
        
        /* Dynamic loading text */
        .loading-text {
            color: var(--primary);
            font-size: 0.9rem;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: -16px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            body { padding: 24px 12px 60px; }
            .hero h1 { font-size: 1.8rem; }
            
            /* Panel and Grid stacking */
            .panel { padding: 16px; }
            .grid-2, .grid-3 { grid-template-columns: 1fr; gap: 16px; }
            
            /* Navigation and Buttons */
            .top-nav { margin-bottom: 16px; }
            .btn-ghost { width: 100%; height: 50px; }
            .btn-primary { width: 100%; height: 50px; margin-top: 16px; }
            
            /* Input touch targets */
            input[type="text"], input[type="number"], select { height: 50px; font-size: 1rem; }
            .alert { padding: 12px 16px; flex-direction: column; align-items: flex-start; gap: 8px; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/global_header.php'; ?>

    <script> const currencySymbolJS = '<?= $currency_symbol ?>'; </script>
    <div class="page">
        
        <div class="top-nav">
            <a href="search.php?country=<?= urlencode($current_marketplace_code) ?>" class="btn-ghost">&laquo; Zurück zur Übersicht</a>
        </div>

        <header class="hero">
            <p class="eyebrow">Pricing Manager</p>
            <h1>
                <img class="flag-icon" src="img/<?= htmlspecialchars($current_marketplace_code) ?>.png" alt="<?= htmlspecialchars($current_marketplace_code) ?>">
                Produkt anlegen
            </h1>
        </header>

        <?php if ($message): ?>
            <div class="alert <?= htmlspecialchars($message_type) ?>">
                <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <div class="panel">
            <form action="addNew.php" method="POST" id="add-product-form">
                <input type="hidden" name="country" value="<?= htmlspecialchars($current_marketplace_code) ?>">
                <input type="hidden" id="produktid" name="produktid" value="<?= htmlspecialchars($produktid_submitted ?? '') ?>">
                <input type="hidden" id="action_type" name="action_type" value="<?= htmlspecialchars($action_type_submitted) ?>">
                <input type="hidden" id="current_marketplace_code" name="current_marketplace_code" value="<?= htmlspecialchars($current_marketplace_code) ?>">

                <div class="field">
                    <label for="asin-input" class="required">ASIN eingeben</label>
                    <input type="text" id="asin-input" name="asin" placeholder="z.B. B08XYZ1234" required autocomplete="off" pattern="^[A-Z0-9]{10}$" title="Gültige 10-stellige ASIN." value="<?= htmlspecialchars($asin_submitted) ?>">
                </div>

                <div id="loading-indicator" class="loading-text" style="display: none;">Suche Produkte...</div>
                
                <div id="product-results" class="info-box">
                    <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $message_type === 'success'): ?>
                        Bitte ASIN eingeben. Bestehende Artikeldetails (Name, SKU) werden geladen. Preisgrenzen sind spezifisch für Land <?= htmlspecialchars(strtoupper($current_marketplace_code)) ?>.
                    <?php endif; ?>
                </div>

                <div id="new-product-inputs" style="<?= ($action_type_submitted === 'create' && $message_type !== 'success') ? 'display: block;' : 'display: none;' ?>">
                    <h3>Neue Artikel-Stammdaten</h3>
                    <div id="sku-loading-indicator" class="loading-text" style="display: none;">Lade Amazon Details...</div>
                    <div id="sku-error-message" style="color: var(--danger); font-size: 0.9em; margin-bottom: 16px; font-weight: 500;"></div>
                    
                    <div class="grid-3">
                        <div class="field">
                            <label for="new_artikelname" class="required">Produktname</label>
                            <input type="text" id="new_artikelname" name="new_artikelname" placeholder="Vollständiger Produktname" value="<?= htmlspecialchars($new_artikelname_submitted) ?>">
                        </div>
                        <div class="field">
                            <label for="new_ean" class="required">EAN</label>
                            <input type="text" id="new_ean" name="new_ean" placeholder="13-stellige EAN" value="<?= htmlspecialchars($new_ean_submitted) ?>">
                        </div>
                        <div class="field">
                            <label for="new_sku" class="required">Seller SKU (Amazon)</label>
                            <select id="new_sku" name="new_sku">
                                <option value="">-- SKU wählen --</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div id="price-inputs" style="display: none;">
                    <h3>Preisgrenzen für ASIN <span id="preisgrenzen-asin-display" style="font-family: monospace; color: var(--primary);"></span> in <?= htmlspecialchars($current_marketplace_name) ?></h3>
                    
                    <div id="current-price-display" class="info-box" style="margin-bottom: 24px; padding: 12px 16px;"></div>

                    <div class="grid-2">
                        <div class="field">
                            <label for="min_preis" class="required">Minimalpreis (<?= $currency_symbol ?>)</label>
                            <input type="number" step="0.01" id="min_preis" name="min_preis" placeholder="z.B. 9.99" required value="<?= htmlspecialchars($min_preis_str) ?>">
                        </div>
                        <div class="field">
                            <label for="max_preis" class="required">Maximalpreis (<?= $currency_symbol ?>)</label>
                            <input type="number" step="0.01" id="max_preis" name="max_preis" placeholder="z.B. 19.99" required value="<?= htmlspecialchars($max_preis_str) ?>">
                        </div>
                        <div class="field">
                            <label for="stepsize_small" class="required">Step Klein (< 10 <?= $currency_symbol ?>)</label>
                            <input type="number" step="0.01" id="stepsize_small" name="stepsize_small" placeholder="z.B. 0.01" required value="<?= htmlspecialchars($stepsize_small_str) ?>">
                        </div>
                        <div class="field">
                            <label for="stepsize_big" class="required">Step Groß (>= 10 <?= $currency_symbol ?>)</label>
                            <input type="number" step="0.01" id="stepsize_big" name="stepsize_big" placeholder="z.B. 0.10" required value="<?= htmlspecialchars($stepsize_big_str) ?>">
                        </div>
                    </div>
                </div>

                <button type="submit" id="submit-button" class="btn-primary" disabled>
                    Daten prüfen / Produkt auswählen
                </button>
            </form>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const asinInput = document.getElementById('asin-input');
        const productResultsDiv = document.getElementById('product-results');
        const produktIdInput = document.getElementById('produktid'); 
        const actionTypeInput = document.getElementById('action_type'); 
        const currentMarketplaceCodeInput = document.getElementById('current_marketplace_code');

        const newProductInputsDiv = document.getElementById('new-product-inputs');
        const newArtikelnameInput = document.getElementById('new_artikelname');
        const newEanInput = document.getElementById('new_ean');
        const newSkuSelect = document.getElementById('new_sku');
        const skuLoadingIndicator = document.getElementById('sku-loading-indicator');
        const skuErrorMessageDiv = document.getElementById('sku-error-message');

        const priceInputsDiv = document.getElementById('price-inputs');
        const preisgrenzenAsinDisplay = document.getElementById('preisgrenzen-asin-display');
        const currentPriceDisplay = document.getElementById('current-price-display');
        const minPreisInput = document.getElementById('min_preis');
        const maxPreisInput = document.getElementById('max_preis');
        const stepsizeSmallInput = document.getElementById('stepsize_small');
        const stepsizeBigInput = document.getElementById('stepsize_big');

        const submitButton = document.getElementById('submit-button');
        const loadingIndicator = document.getElementById('loading-indicator');
        let debounceTimeout;

        function resetProductAndPriceFields() {
            produktIdInput.value = '';
            actionTypeInput.value = 'update'; 

            newProductInputsDiv.style.display = 'none';
            newArtikelnameInput.value = '';
            newEanInput.value = '';
            newSkuSelect.innerHTML = '<option value="">-- SKU wählen --</option>';
            newSkuSelect.disabled = true;
            skuErrorMessageDiv.textContent = '';

            priceInputsDiv.style.display = 'none';
            preisgrenzenAsinDisplay.textContent = '';
            currentPriceDisplay.innerHTML = '';
            minPreisInput.value = '';
            maxPreisInput.value = '';
            stepsizeSmallInput.value = '0.01'; 
            stepsizeBigInput.value = '0.10';   

            productResultsDiv.innerHTML = 'Bitte ASIN eingeben.';
            submitButton.textContent = 'Daten prüfen / Produkt auswählen';
            submitButton.disabled = true;
        }

        async function fetchProductData(asin, marketplaceCode) {
            if (!asin || asin.length !== 10 || !/^[A-Z0-9]{10}$/.test(asin)) {
                resetProductAndPriceFields();
                productResultsDiv.innerHTML = 'Ungültige ASIN.';
                return;
            }

            loadingIndicator.style.display = 'flex';
            productResultsDiv.innerHTML = '';
            preisgrenzenAsinDisplay.textContent = asin;

            try {
                const artikelResponse = await fetch(`Workspace_products.php?asin=${encodeURIComponent(asin)}`);
                if (!artikelResponse.ok) throw new Error(`Artikeldaten HTTP Fehler: ${artikelResponse.status}`);
                const artikelDataArray = await artikelResponse.json();

                let artikelId = null;
                let artikelName = '';
                let artikelSku = ''; 

                if (artikelDataArray && artikelDataArray.length > 0) {
                    const artikel = artikelDataArray[0]; 
                    artikelId = artikel.ID;
                    artikelName = artikel.artikelname;
                    artikelSku = artikel.sku; 

                    produktIdInput.value = artikelId;
                    actionTypeInput.value = 'update';
                    productResultsDiv.innerHTML = `Artikel gefunden: <strong>${htmlspecialchars(artikelName)}</strong> (SKU: ${htmlspecialchars(artikelSku || 'N/A')}).<br>Preisgrenzen für Land ${htmlspecialchars(marketplaceCode)} werden geladen/erstellt.`;
                    newProductInputsDiv.style.display = 'none'; 
                    newArtikelnameInput.value = artikelName; 
                } else {
                    actionTypeInput.value = 'create'; 
                    productResultsDiv.innerHTML = 'ASIN nicht in lokaler Datenbank gefunden. Amazon-Daten werden abgerufen. Bitte ergänzen Sie ggf. Name/EAN/SKU.';
                    newProductInputsDiv.style.display = 'block'; 
                    produktIdInput.value = '';
                }

                await fetchAmazonProductDetails(asin); 

                const preisgrenzenResponse = await fetch(`get_preisgrenzen.php?asin=${encodeURIComponent(asin)}&land=${encodeURIComponent(marketplaceCode)}`);
                if (preisgrenzenResponse.ok) {
                    const preisgrenzenData = await preisgrenzenResponse.json();
                    if (preisgrenzenData && preisgrenzenData.min_preis !== undefined) {
                        minPreisInput.value = parseFloat(preisgrenzenData.min_preis).toFixed(2);
                        maxPreisInput.value = parseFloat(preisgrenzenData.max_preis).toFixed(2);
                        stepsizeSmallInput.value = parseFloat(preisgrenzenData.stepsize_small).toFixed(2);
                        stepsizeBigInput.value = parseFloat(preisgrenzenData.stepsize_big).toFixed(2);
                        if (productResultsDiv.innerHTML === '') productResultsDiv.innerHTML += '<br>';
                        productResultsDiv.innerHTML += `<br><em>Bestehende Preisgrenzen für ${htmlspecialchars(marketplaceCode)} geladen.</em>`;
                    } else {
                         if (productResultsDiv.innerHTML === '') productResultsDiv.innerHTML += '<br>';
                        productResultsDiv.innerHTML += `<br><em>Keine Preisgrenzen für ${htmlspecialchars(marketplaceCode)} gefunden. Bitte neu definieren.</em>`;
                    }
                } else {
                    console.warn('Fehler beim Laden der Preisgrenzen.');
                     if (productResultsDiv.innerHTML === '') productResultsDiv.innerHTML += '<br>';
                    productResultsDiv.innerHTML += `<br><em>Konnte Preisgrenzen für ${htmlspecialchars(marketplaceCode)} nicht laden. Bitte neu definieren.</em>`;
                }

                await loadCurrentMarketPrice(asin, marketplaceCode);

                priceInputsDiv.style.display = 'block';
                if (actionTypeInput.value === 'create') {
                    submitButton.textContent = `Neuen Artikel & Preisgrenzen für ${htmlspecialchars(marketplaceCode)} anlegen`;
                } else {
                    submitButton.textContent = `Preisgrenzen für ${htmlspecialchars(marketplaceCode)} speichern`;
                }
                checkFormValidity();


            } catch (error) {
                console.error('Fehler bei fetchProductData:', error);
                productResultsDiv.innerHTML = `Fehler: ${error.message}`;
                resetProductAndPriceFields(); 
            } finally {
                loadingIndicator.style.display = 'none';
            }
        }

        async function fetchAmazonProductDetails(asin) {
            skuLoadingIndicator.textContent = 'Lade Amazon Produktdetails...';
            skuLoadingIndicator.style.display = 'flex';
            skuErrorMessageDiv.textContent = '';
            newSkuSelect.innerHTML = '<option value="">-- Lade SKUs... --</option>';
            newEanInput.value = '';
            newArtikelnameInput.value = ''; 
            newSkuSelect.disabled = true;

            try {
                const response = await fetch(`get_amazon_product_details.php?asin=${encodeURIComponent(asin)}`);
                if (!response.ok) throw new Error(`Amazon Details HTTP Fehler: ${response.status}`);
                const result = await response.json();

                if (result.error) {
                    skuErrorMessageDiv.textContent = `Amazon API Fehler: ${htmlspecialchars(result.error)}`;
                    newSkuSelect.innerHTML = '<option value="">-- Fehler bei SKU-Abruf --</option>';
                } else {
                    if (result.skus && result.skus.length > 0) {
                        newSkuSelect.innerHTML = '<option value="">-- Bitte SKU wählen --</option>';
                        result.skus.forEach(sku => {
                            const option = document.createElement('option');
                            option.value = sku;
                            option.textContent = sku;
                            newSkuSelect.appendChild(option);
                        });
                        newSkuSelect.disabled = false;
                        
                        const currentArtikelSku = (actionTypeInput.value === 'update' && produktIdInput.value) ? newArtikelnameInput.dataset.skuFromArtikel : null;
                        if (currentArtikelSku && newSkuSelect.querySelector(`option[value="${currentArtikelSku}"]`)) {
                            newSkuSelect.value = currentArtikelSku;
                        } else if (result.skus.length === 1) { 
                            newSkuSelect.value = result.skus[0];
                        }
                    } else {
                        newSkuSelect.innerHTML = '<option value="">-- Keine SKUs via API gefunden --</option>';
                        skuErrorMessageDiv.textContent = 'Keine SKUs für diese ASIN via Amazon API gefunden.';
                    }

                    if (actionTypeInput.value === 'create' || !newArtikelnameInput.value.trim()) {
                        if (result.name) newArtikelnameInput.value = result.name;
                    }
                    if (actionTypeInput.value === 'create' || !newEanInput.value.trim()) {
                        if (result.ean) newEanInput.value = result.ean;
                    }
                }
                 if(result.warnings && result.warnings.length > 0) {
                    skuErrorMessageDiv.textContent += ` (API Warnungen: ${result.warnings.join(', ')})`;
                }

            } catch (error) {
                console.error('Fehler fetchAmazonProductDetails:', error);
                skuErrorMessageDiv.textContent = `Netzwerkfehler: ${error.message}`;
                newSkuSelect.innerHTML = '<option value="">-- Fehler --</option>';
            } finally {
                skuLoadingIndicator.style.display = 'none';
                checkFormValidity();
            }
        }

        async function loadCurrentMarketPrice(asin, marketplaceCode) {
            currentPriceDisplay.innerHTML = '<i>Lade aktuellen Marktpreis...</i>';
            try {
                const priceResponse = await fetch(`get_current_price.php?asin=${encodeURIComponent(asin)}&marketplace=${encodeURIComponent(marketplaceCode)}`);
                if (!priceResponse.ok) throw new Error(`Marktpreis HTTP Fehler: ${priceResponse.status}`);
                const priceResult = await priceResponse.json();

                if (priceResult.error) {
                    currentPriceDisplay.textContent = `Marktpreis nicht ermittelbar: ${priceResult.error}`;
                } else if (priceResult.currentPrice !== null) {
                    const currentPrice = parseFloat(priceResult.currentPrice);
                    const priceTypeInfo = priceResult.priceType ? ` (${priceResult.priceType})` : '';
                    currentPriceDisplay.innerHTML = `Aktueller Marktpreis <strong>(${htmlspecialchars(marketplaceCode)})</strong>${priceTypeInfo}: <strong>${currencySymbolJS}${currentPrice.toFixed(2)}</strong>`;
                    
                    if (!minPreisInput.value) minPreisInput.value = (currentPrice * 0.90).toFixed(2);
                    if (!maxPreisInput.value) maxPreisInput.value = (currentPrice * 1.10).toFixed(2);
                } else {
                    currentPriceDisplay.textContent = 'Marktpreis nicht verfügbar.';
                }
            } catch (error) {
                console.error('Fehler loadCurrentMarketPrice:', error);
                currentPriceDisplay.textContent = 'Fehler beim Laden des Marktpreises.';
            } finally {
                checkFormValidity();
            }
        }

        function checkFormValidity() {
            let isValid = true;
            if (!asinInput.checkValidity()) isValid = false;

            if (actionTypeInput.value === 'create') {
                if (!newArtikelnameInput.value.trim()) isValid = false;
                if (!newEanInput.value.trim()) isValid = false; 
                if (!newSkuSelect.value) isValid = false;
            }

            const minP = parseFloat(minPreisInput.value.replace(',', '.'));
            const maxP = parseFloat(maxPreisInput.value.replace(',', '.'));
            const stepS = parseFloat(stepsizeSmallInput.value.replace(',', '.'));
            const stepB = parseFloat(stepsizeBigInput.value.replace(',', '.'));

            if (isNaN(minP) || minP < 0) isValid = false;
            if (isNaN(maxP) || maxP < 0) isValid = false;
            if (!isNaN(minP) && !isNaN(maxP) && minP > maxP) isValid = false;
            if (isNaN(stepS) || stepS <= 0) isValid = false;
            if (isNaN(stepB) || stepB <= 0) isValid = false;
            if (!isNaN(stepS) && !isNaN(stepB) && stepS > stepB) isValid = false;

            if (priceInputsDiv.style.display === 'block') {
                 if (minPreisInput.value === '' || maxPreisInput.value === '' || stepsizeSmallInput.value === '' || stepsizeBigInput.value === '') {
                     isValid = false;
                 }
            } else {
                isValid = false;
            }

            submitButton.disabled = !isValid;
        }

        asinInput.addEventListener('input', () => {
            const asinValue = asinInput.value.trim().toUpperCase();
            asinInput.value = asinValue; 
            clearTimeout(debounceTimeout);
            if (asinValue.length === 10 && /^[A-Z0-9]{10}$/.test(asinValue)) {
                debounceTimeout = setTimeout(() => {
                    fetchProductData(asinValue, currentMarketplaceCodeInput.value);
                }, 500);
            } else {
                resetProductAndPriceFields();
                if (asinValue.length > 0) {
                    productResultsDiv.innerHTML = 'Bitte gültige 10-stellige ASIN eingeben.';
                }
            }
        });

        [minPreisInput, maxPreisInput, stepsizeSmallInput, stepsizeBigInput, newArtikelnameInput, newEanInput, newSkuSelect].forEach(input => {
            input.addEventListener('input', checkFormValidity);
            input.addEventListener('change', checkFormValidity); 
        });

        const initialAsin = asinInput.value.trim();
        if (initialAsin && initialAsin.length === 10 && /^[A-Z0-9]{10}$/.test(initialAsin)) {
            fetchProductData(initialAsin, currentMarketplaceCodeInput.value);
        } else {
             resetProductAndPriceFields(); 
        }

    });

    function htmlspecialchars(str) {
        if (typeof str !== 'string') return String(str);
        const map = {
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        };
        return str.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    </script>

</body>
</html>