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
// $currentDir removed // e.g., "IT", "DE"
$current_marketplace_code = isset($_GET['country']) ? strtoupper(filter_input(INPUT_GET, 'country', FILTER_SANITIZE_STRING)) : (isset($_POST['country']) ? strtoupper(filter_input(INPUT_POST, 'country', FILTER_SANITIZE_STRING)) : ''); if(empty($current_marketplace_code)) die("Missing country in addNew.php"); // Ensure consistency, e.g., IT
$currency_symbol = '€'; // Default currency symbol

// Validate if the determined marketplace code is valid
if (!isset($marketplaces[$current_marketplace_code])) {
    // Handle error: unknown marketplace
    $message = 'Fehler: Unbekannter Marketplace-Code aus Verzeichnispfad: ' . htmlspecialchars($current_marketplace_code);
    $message_type = 'error';
    // Stop further execution or redirect, depending on desired behavior
} else {
    $currency_symbol = $marketplaces[$current_marketplace_code]['currencyCode'] === 'GBP' ? '£' : ($marketplaces[$current_marketplace_code]['currencyCode'] === 'SEK' ? 'kr' : '€');
}


$country_specific_buybox_table = '';
if (isset($marketplaces[$current_marketplace_code]['dbName'])) {
    $country_specific_buybox_table = $marketplaces[$current_marketplace_code]['dbName'];
} else {
    if ($message_type !== 'error') { // Avoid overwriting initial error
      $message = 'Fehler: Konnte den spezifischen Buybox-Tabellennamen für ' . htmlspecialchars($current_marketplace_code) . ' nicht finden.';
      $message_type = 'error';
    }
}

if (isset($marketplaces[$current_marketplace_code]['name'])) {
    $current_marketplace_name = $marketplaces[$current_marketplace_code]['name'];
}


// --- Variablen initialisieren ---
$action_type_submitted = filter_input(INPUT_POST, 'action_type', FILTER_SANITIZE_STRING) ?: 'update'; // 'update' or 'create' refers to Artikel table context mostly
$asin_submitted = filter_input(INPUT_POST, 'asin', FILTER_SANITIZE_STRING) ?: '';
$produktid_submitted = filter_input(INPUT_POST, 'produktid', FILTER_VALIDATE_INT) ?: null; // ID from Artikel table for existing items

$min_preis_str = filter_input(INPUT_POST, 'min_preis') ?: '';
$max_preis_str = filter_input(INPUT_POST, 'max_preis') ?: '';
$stepsize_small_str = filter_input(INPUT_POST, 'stepsize_small') ?: '0.01';
$stepsize_big_str = filter_input(INPUT_POST, 'stepsize_big') ?: '0.10';

// Neue Felder für Create (Artikel table)
$new_artikelname_submitted = filter_input(INPUT_POST, 'new_artikelname', FILTER_SANITIZE_STRING) ?: '';
$new_ean_submitted = filter_input(INPUT_POST, 'new_ean', FILTER_SANITIZE_STRING) ?: '';
$new_sku_submitted = filter_input(INPUT_POST, 'new_sku', FILTER_SANITIZE_STRING) ?: '';


if ($_SERVER['REQUEST_METHOD'] === 'POST' && $message_type !== 'error') { // Proceed only if no initial config error

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

    // --- Aktionsspezifische Validierung für Artikel ---
    // action_type 'create' implies we might need to create an entry in 'Artikel' table
    // action_type 'update' implies 'Artikel' entry exists, we are adding/updating 'Preisgrenzen' for it.

    $artikel_exists = false;
    $current_artikel_id = $produktid_submitted; // Assume it's an update if ID is passed

    if ($action_type_submitted === 'create') { // Creating a new Artikel entry
        if (empty($new_artikelname_submitted)) {
            $validation_errors[] = 'Produktname ist erforderlich für neuen Artikel.';
        }
        // EAN is now mandatory as per original script logic for 'create'
        if (empty($new_ean_submitted)) {
            $validation_errors[] = 'EAN ist erforderlich für neuen Artikel.';
        }
        if (empty($new_sku_submitted)) {
            $validation_errors[] = 'Seller SKU ist erforderlich für neuen Artikel.';
        }
        // Check for duplicates in Artikel before creating new
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
    } else { // 'update' action type - means Artikel entry should exist
        if (empty($current_artikel_id)) { // If produktid was not submitted (e.g. JS error)
            // Try to fetch it if we are trying to configure an existing ASIN
            try {
                $stmtCheck = $dbConnection->prepare("SELECT ID FROM Artikel WHERE asin = :asin LIMIT 1");
                $stmtCheck->bindParam(':asin', $asin_submitted);
                $stmtCheck->execute();
                $fetched_id = $stmtCheck->fetchColumn();
                if ($fetched_id) {
                    $current_artikel_id = $fetched_id;
                    $artikel_exists = true;
                } else {
                    // This case implies ASIN not in Artikel, but action is 'update'.
                    // This should ideally be handled by JS setting action_type to 'create'
                    // Or user wants to add Preisgrenzen to an ASIN that exists in Amazon but not locally yet.
                    $validation_errors[] = 'Artikel mit ASIN ' . htmlspecialchars($asin_submitted) . ' nicht in der Datenbank gefunden. Legen Sie ihn zuerst an oder wählen Sie "Neues Produkt anlegen".';
                }
            } catch (\PDOException $e) {
                error_log("DB Fehler bei Artikel ID Suche: " . $e->getMessage());
                $validation_errors[] = 'Fehler bei Suche nach Artikel ID.';
            }
        } else {
             $artikel_exists = true; // produktid_submitted was provided
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
            // If action_type_submitted was 'update', $current_artikel_id is already set (or fetched)

            if (!$current_artikel_id) {
                throw new Exception("Konnte Artikel ID nicht bestimmen.");
            }

            // Step 2: Insert or Update Preisgrenzen table
            // Check if entry for ASIN + Land exists in Preisgrenzen
            $stmtCheckPg = $dbConnection->prepare("SELECT ASIN FROM Preisgrenzen WHERE ASIN = :asin AND Land = :land LIMIT 1");
            $stmtCheckPg->bindParam(':asin', $asin_submitted);
            $stmtCheckPg->bindParam(':land', $current_marketplace_code);
            $stmtCheckPg->execute();
            $preisgrenze_exists = $stmtCheckPg->fetchColumn();

            if ($preisgrenze_exists) { // Update existing Preisgrenzen
                $stmtPreisgrenzen = $dbConnection->prepare(
                    "UPDATE Preisgrenzen SET min_preis = :min_preis, max_preis = :max_preis, stepsize_small = :stepsize_small, stepsize_big = :stepsize_big
                     WHERE ASIN = :asin AND Land = :land"
                );
                $message .= 'Preisgrenzen für Land ' . htmlspecialchars($current_marketplace_code) . ' aktualisiert. ';
            } else { // Insert new Preisgrenzen
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
            // API data fetching (remains largely the same, but consider marketplace context if API calls need it)
            // $apiData = callItemsAPI($asin_submitted); // Consider passing marketplace ID if relevant for this API call
            // $eigenerPreis = getOwnPriceByASIN($asin_submitted); // And here
            // $buyboxpreis = getInfoByASIN($apiData, "buyboxpreis");
            // $niedrigsterPreis = getLowestPrice($apiData);

            // For now, assuming API calls are globally relevant or use default marketplace.
            // If your sp_api_functions.php needs marketplaceId for these, pass $marketplaces[$current_marketplace_code]['marketplaceId']
            $apiData = callItemsAPI($asin_submitted, $marketplaces[$current_marketplace_code]['marketplaceId']);
            $eigenerPreis = getOwnPriceByASIN($asin_submitted, $marketplaces[$current_marketplace_code]['marketplaceId']);
            $buyboxpreis = getInfoByASIN($apiData, "buyboxpreis"); // Assuming $apiData is structured correctly
            $niedrigsterPreis = getLowestPrice($apiData);


             if ($apiData === null) {
                 throw new Exception("Fehler beim Abrufen der Preisdaten von der Amazon API für ASIN $asin_submitted für Marketplace $current_marketplace_code.");
             }

            if (!empty($country_specific_buybox_table)) {
                // Check if an 'init' record already exists for this produktid in this country's buybox table
                // This might be overly simplistic; you might want to always insert a new record
                // or update the latest 'init' if one exists. The original script just inserts.
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
            $action_type_submitted = 'update'; // Default for next time

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
    <title>Produkt für Land: <?= htmlspecialchars(strtoupper($current_marketplace_code)) ?> anlegen/bearbeiten</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="landingpage.css">
    <link rel="stylesheet" href="addNew.css">
    <link rel="icon" type="image/x-icon" href="img/tag.ico" sizes="32x32">
</head>
<body>

    <h1><img src="img/<?= htmlspecialchars($current_marketplace_code) ?>.png" alt="<?= htmlspecialchars($current_marketplace_code) ?>" style="height: 1em; vertical-align: middle;"> Produkt anlegen   </h1>
    <a href="search.php?country=<?= urlencode($current_marketplace_code) ?>" style="display: inline-block; margin-bottom: 20px;">&laquo; Zurück zur Übersicht</a>

    <?php if ($message): ?>
        <div class="message <?= htmlspecialchars($message_type) ?>">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>

    <form action="addNew.php" method="POST" id="add-product-form"><input type="hidden" name="country" value="<?= htmlspecialchars($current_marketplace_code) ?>">

        <label for="asin-input" class="required">ASIN eingeben:</label>
        <input type="text" id="asin-input" name="asin" placeholder="ASIN (z.B. B08XYZ1234)" required autocomplete="off" pattern="^[A-Z0-9]{10}$" title="Gültige 10-stellige ASIN." value="<?= htmlspecialchars($asin_submitted) ?>">
        <div id="loading-indicator" style="display: none; margin-top: 5px; color: #007bff;">Suche Produkte...</div>
        <div id="product-results">
            <?php if ($_SERVER['REQUEST_METHOD'] !== 'POST' || $message_type === 'success'): ?>
                Bitte ASIN eingeben. Bestehende Artikeldetails (Name, SKU) werden geladen. Preisgrenzen sind spezifisch für Land <?= htmlspecialchars(strtoupper($current_marketplace_code)) ?>.
            <?php endif; ?>
        </div>

        <input type="hidden" id="produktid" name="produktid" value="<?= htmlspecialchars($produktid_submitted ?? '') ?>">
        <input type="hidden" id="action_type" name="action_type" value="<?= htmlspecialchars($action_type_submitted) ?>">
        <input type="hidden" id="current_marketplace_code" name="current_marketplace_code" value="<?= htmlspecialchars($current_marketplace_code) ?>">


        <div id="new-product-inputs" style="<?= ($action_type_submitted === 'create' && $message_type !== 'success') ? 'display: block;' : 'display: none;' ?> margin-top: 15px; border-top: 1px solid #ccc; padding-top: 15px;">
        <div id="sku-loading-indicator" style="display: none; color: #007bff;">Lade Amazon Details...</div>
             <label for="new_artikelname">Produktname:</label>
             <input type="text" id="new_artikelname" name="new_artikelname" placeholder="Vollständiger Produktname" value="<?= htmlspecialchars($new_artikelname_submitted) ?>">

             <label for="new_ean">EAN:</label>
             <input type="text" id="new_ean" name="new_ean" placeholder="EAN" value="<?= htmlspecialchars($new_ean_submitted) ?>">

             <label for="new_sku">Seller SKU (von Amazon):</label>
             <select id="new_sku" name="new_sku">
                 <option value="">-- SKU wählen (falls autom. gefunden) --</option>
             </select>
             <div id="sku-error-message" style="color: red; font-size: 0.9em; margin-top: 5px;"></div>
        </div>


        <div id="price-inputs" style="display: none; /* JS wird dies steuern */ margin-top: 15px;">
             <h3>Produktdetails für ASIN <span id="preisgrenzen-asin-display"></span> in <?= htmlspecialchars($current_marketplace_name) ?></h3>
             <div id="current-price-display" style="margin-bottom: 10px; padding: 8px; background-color: #e9e9e9; border-radius: 4px; min-height: 1.5em;"></div>

             <label for="min_preis" class="required">Minimalpreis (<?= $currency_symbol ?>):</label>
             <input type="number" step="0.01" id="min_preis" name="min_preis" placeholder="z.B. 9.99" required value="<?= htmlspecialchars($min_preis_str) ?>">

             <label for="max_preis" class="required">Maximalpreis (<?= $currency_symbol ?>):</label>
             <input type="number" step="0.01" id="max_preis" name="max_preis" placeholder="z.B. 19.99" required value="<?= htmlspecialchars($max_preis_str) ?>">

             <label for="stepsize_small" class="required">Schrittgröße &lt; 10 <?= $currency_symbol ?>:</label>
             <input type="number" step="0.01" id="stepsize_small" name="stepsize_small" placeholder="z.B. 0.01" required value="<?= htmlspecialchars($stepsize_small_str) ?>">

             <label for="stepsize_big" class="required">Schrittgröße &gt;= 10 <?= $currency_symbol ?>:</label>
             <input type="number" step="0.01" id="stepsize_big" name="stepsize_big" placeholder="z.B. 0.10" required value="<?= htmlspecialchars($stepsize_big_str) ?>">
        </div>

        <button type="submit" id="submit-button" style="margin-top: 10px;" disabled>
            Daten prüfen / Produkt auswählen
        </button>
    </form>

    <script>
    const currencySymbolJS = '<?= $currency_symbol ?>';
    document.addEventListener('DOMContentLoaded', () => {
        const asinInput = document.getElementById('asin-input');
        const productResultsDiv = document.getElementById('product-results');
        const produktIdInput = document.getElementById('produktid'); // Hidden input for Artikel.ID
        const actionTypeInput = document.getElementById('action_type'); // Hidden input for form action context
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

        // Function to clear form state
        function resetProductAndPriceFields() {
            produktIdInput.value = '';
            actionTypeInput.value = 'update'; // Default, might change to 'create' if ASIN is totally new

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
            stepsizeSmallInput.value = '0.01'; // Default
            stepsizeBigInput.value = '0.10';   // Default

            productResultsDiv.innerHTML = 'Bitte ASIN eingeben.';
            submitButton.textContent = 'Daten prüfen / Produkt auswählen';
            submitButton.disabled = true;
        }

        // Fetch existing Artikel details (if any) and Preisgrenzen for current country
        async function fetchProductData(asin, marketplaceCode) {
            if (!asin || asin.length !== 10 || !/^[A-Z0-9]{10}$/.test(asin)) {
                resetProductAndPriceFields();
                productResultsDiv.innerHTML = 'Ungültige ASIN.';
                return;
            }

            loadingIndicator.style.display = 'block';
            productResultsDiv.innerHTML = '';
            preisgrenzenAsinDisplay.textContent = asin; // Show ASIN in price section header

            try {
                // Step 1: Fetch Artikel details (name, sku, existing ID) from Workspace_products.php
                // This tells us if the ASIN is known in the Artikel table at all.
                const artikelResponse = await fetch(`Workspace_products.php?asin=${encodeURIComponent(asin)}`);
                if (!artikelResponse.ok) throw new Error(`Artikeldaten HTTP Fehler: ${artikelResponse.status}`);
                const artikelDataArray = await artikelResponse.json();

                let artikelId = null;
                let artikelName = '';
                let artikelSku = ''; // Default to first SKU if multiple for an ASIN in Artikel

                if (artikelDataArray && artikelDataArray.length > 0) {
                    // ASIN exists in Artikel table
                    const artikel = artikelDataArray[0]; // Assuming one primary entry per ASIN or taking the first
                    artikelId = artikel.ID;
                    artikelName = artikel.artikelname;
                    artikelSku = artikel.sku; // This would be the SKU from Artikel table

                    produktIdInput.value = artikelId;
                    actionTypeInput.value = 'update'; // Means Artikel exists, we are updating/creating Preisgrenzen for it
                    productResultsDiv.innerHTML = `Artikel gefunden: <strong>${htmlspecialchars(artikelName)}</strong> (SKU: ${htmlspecialchars(artikelSku || 'N/A')}).<br>Preisgrenzen für Land ${htmlspecialchars(marketplaceCode)} werden geladen/erstellt.`;
                    newProductInputsDiv.style.display = 'none'; // Hide new Artikel fields

                    // Pre-fill new_artikelname, new_ean, new_sku display only if needed,
                    // but these won't be for "creating" a new Artikel entry.
                    newArtikelnameInput.value = artikelName; // For display / reference
                    // EAN is not in Workspace_products.php by default, fetch if needed or rely on Amazon API call below.

                } else {
                    // ASIN does not exist in Artikel table - this is a completely new product
                    actionTypeInput.value = 'create'; // Need to create Artikel entry AND Preisgrenzen
                    productResultsDiv.innerHTML = 'ASIN nicht in lokaler Datenbank gefunden. Amazon-Daten werden abgerufen. Bitte ergänzen Sie ggf. Name/EAN/SKU.';
                    newProductInputsDiv.style.display = 'block'; // Show fields to create new Artikel
                    produktIdInput.value = '';
                }

                // Step 2: Fetch SKUs, EAN, Name from Amazon for this ASIN (for new_sku dropdown and prefill)
                // This is useful for both new Artikel and existing Artikel (to confirm/select SKU)
                await fetchAmazonProductDetails(asin); // Populates newSkuSelect, newEanInput, newArtikelnameInput

                // Step 3: Fetch existing Preisgrenzen for this ASIN and current_marketplace_code
                // We create a new endpoint for this or extend an existing one.
                // For simplicity, let's assume a new endpoint get_preisgrenzen.php
                const preisgrenzenResponse = await fetch(`get_preisgrenzen.php?asin=${encodeURIComponent(asin)}&land=${encodeURIComponent(marketplaceCode)}`);
                if (preisgrenzenResponse.ok) {
                    const preisgrenzenData = await preisgrenzenResponse.json();
                    if (preisgrenzenData && preisgrenzenData.min_preis !== undefined) {
                        minPreisInput.value = parseFloat(preisgrenzenData.min_preis).toFixed(2);
                        maxPreisInput.value = parseFloat(preisgrenzenData.max_preis).toFixed(2);
                        stepsizeSmallInput.value = parseFloat(preisgrenzenData.stepsize_small).toFixed(2);
                        stepsizeBigInput.value = parseFloat(preisgrenzenData.stepsize_big).toFixed(2);
                        if (productResultsDiv.innerHTML === '') productResultsDiv.innerHTML += '<br>';
                        productResultsDiv.innerHTML += `<em>Bestehende Preisgrenzen für ${htmlspecialchars(marketplaceCode)} geladen.</em>`;
                    } else {
                        // No existing preisgrenzen for this ASIN/Land, user will define new ones
                         if (productResultsDiv.innerHTML === '') productResultsDiv.innerHTML += '<br>';
                        productResultsDiv.innerHTML += `<em>Keine Preisgrenzen für ${htmlspecialchars(marketplaceCode)} gefunden. Bitte neu definieren.</em>`;
                        // Defaults are already set for price inputs
                    }
                } else {
                    console.warn('Fehler beim Laden der Preisgrenzen.');
                     if (productResultsDiv.innerHTML === '') productResultsDiv.innerHTML += '<br>';
                    productResultsDiv.innerHTML += `<em>Konnte Preisgrenzen für ${htmlspecialchars(marketplaceCode)} nicht laden. Bitte neu definieren.</em>`;
                }

                // Step 4: Fetch current market price (e.g., BuyBox) for display
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
                resetProductAndPriceFields(); // Go back to a clean state
            } finally {
                loadingIndicator.style.display = 'none';
            }
        }

        async function fetchAmazonProductDetails(asin) {
            // This function is similar to the original one, fetches SKU, EAN, Name from Amazon
            // It should populate newSkuSelect, newEanInput, newArtikelnameInput
            // If actionType is 'create', these are primary inputs.
            // If actionType is 'update', these are for reference or if SKU needs to be re-associated.
            skuLoadingIndicator.textContent = 'Lade Amazon Produktdetails...';
            skuLoadingIndicator.style.display = 'block';
            skuErrorMessageDiv.textContent = '';
            newSkuSelect.innerHTML = '<option value="">-- Lade SKUs... --</option>';
            newEanInput.value = '';
            newArtikelnameInput.value = ''; // Clear previous values
            newSkuSelect.disabled = true;

            try {
                // Assuming get_amazon_product_details.php also takes marketplaceId if necessary,
                // or it's globally applicable.
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
                        // If an artikelSku was found from Artikel table, try to pre-select it
                        const currentArtikelSku = (actionTypeInput.value === 'update' && produktIdInput.value) ? newArtikelnameInput.dataset.skuFromArtikel : null;
                        if (currentArtikelSku && newSkuSelect.querySelector(`option[value="${currentArtikelSku}"]`)) {
                            newSkuSelect.value = currentArtikelSku;
                        } else if (result.skus.length === 1) { // Auto-select if only one SKU
                            newSkuSelect.value = result.skus[0];
                        }

                    } else {
                        newSkuSelect.innerHTML = '<option value="">-- Keine SKUs via API gefunden --</option>';
                        skuErrorMessageDiv.textContent = 'Keine SKUs für diese ASIN via Amazon API gefunden.';
                    }

                    // Pre-fill Name and EAN if creating a new Artikel entry or if existing are empty
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
                // Adjust get_current_price.php if it needs marketplaceCode
                const priceResponse = await fetch(`get_current_price.php?asin=${encodeURIComponent(asin)}&marketplace=${encodeURIComponent(marketplaceCode)}`);
                if (!priceResponse.ok) throw new Error(`Marktpreis HTTP Fehler: ${priceResponse.status}`);
                const priceResult = await priceResponse.json();

                if (priceResult.error) {
                    currentPriceDisplay.textContent = `Marktpreis nicht ermittelbar: ${priceResult.error}`;
                } else if (priceResult.currentPrice !== null) {
                    const currentPrice = parseFloat(priceResult.currentPrice);
                    const priceTypeInfo = priceResult.priceType ? ` (${priceResult.priceType})` : '';
                    currentPriceDisplay.textContent = `Aktueller Marktpreis (${htmlspecialchars(marketplaceCode)})${priceTypeInfo}: ${currencySymbolJS}${currentPrice.toFixed(2)}`;
                    // Suggest prices only if fields are empty (not filled by existing Preisgrenzen)
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

            // If creating new Artikel, name, EAN, SKU are needed
            if (actionTypeInput.value === 'create') {
                if (!newArtikelnameInput.value.trim()) isValid = false;
                if (!newEanInput.value.trim()) isValid = false; // Assuming EAN is mandatory for new Artikel
                if (!newSkuSelect.value) isValid = false;
            }
            // If newProductInputsDiv is visible (i.e. action_type could be 'create'), SKU must be selected if not disabled
            if (newProductInputsDiv.style.display === 'block' && !newSkuSelect.disabled && !newSkuSelect.value){
                 // isValid = false; // SKU selection might be optional if not creating a new Artikel
            }


            // Price validations
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

            // All price fields must be filled if priceInputsDiv is visible
            if (priceInputsDiv.style.display === 'block') {
                 if (minPreisInput.value === '' || maxPreisInput.value === '' || stepsizeSmallInput.value === '' || stepsizeBigInput.value === '') {
                     isValid = false;
                 }
            } else { // If price inputs not visible, form is not ready
                isValid = false;
            }


            submitButton.disabled = !isValid;
        }

        // Event Listeners
        asinInput.addEventListener('input', () => {
            const asinValue = asinInput.value.trim().toUpperCase();
            asinInput.value = asinValue; // Standardize to uppercase
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
            input.addEventListener('change', checkFormValidity); // For select
        });

        // Initial state if PHP pre-filled ASIN (e.g. after POST error)
        const initialAsin = asinInput.value.trim();
        if (initialAsin && initialAsin.length === 10 && /^[A-Z0-9]{10}$/.test(initialAsin)) {
            fetchProductData(initialAsin, currentMarketplaceCodeInput.value).then(() => {
                 // If PHP set specific price values due to validation error, they are already in the fields.
                 // The checkFormValidity called at the end of fetchProductData should correctly enable/disable button.
            });
        } else {
             resetProductAndPriceFields(); // Ensure clean start
        }

    });

    // Basic HTML escaping function for JS
    function htmlspecialchars(str) {
        if (typeof str !== 'string') return String(str); // Ensure it's a string
        const map = {
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
        };
        return str.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
    </script>

</body>
</html>
