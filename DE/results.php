<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('default_charset', 'UTF-8');
ini_set('error_log', '../error.log'); // Ensure error log path is correct relative to this file's location

require_once '../marketplaces.php';
require_once '../db_connection.php';

$dbConnection = $dbConnectionTric4Calc;


// --- Determine current country from directory path ---
$currentDir = basename(__DIR__);
$current_marketplace_code = strtoupper($currentDir);
$currency_symbol = '€'; // Default currency symbol


$country_specific_buybox_table = '';
$db_error = ''; // Initialize db_error

if (!isset($marketplaces[$current_marketplace_code])) {
    $db_error = 'Fehler: Unbekannter Marketplace-Code aus Verzeichnispfad: ' . htmlspecialchars($current_marketplace_code);
} else {
    $currency_symbol = $marketplaces[$current_marketplace_code]['currencyCode'] === 'GBP' ? '£' : ($marketplaces[$current_marketplace_code]['currencyCode'] === 'SEK' ? 'kr' : '€');
    if (isset($marketplaces[$current_marketplace_code]['dbName'])) {
        $country_specific_buybox_table = $marketplaces[$current_marketplace_code]['dbName'];
    } else {
        $db_error = 'Fehler: Konnte den spezifischen Buybox-Tabellennamen für ' . htmlspecialchars($current_marketplace_code) . ' nicht finden.';
    }
}
if (isset($marketplaces[$current_marketplace_code]['name'])) {
    $current_marketplace_name = $marketplaces[$current_marketplace_code]['name'];
}

// --- Initialize Variables ---
$productArtikelDetails = null; // Details from Artikel table
$productPreisgrenzen = null;   // Details from Preisgrenzen table
$priceHistory = [];
$selectedAsin = '';
$update_message = '';
$update_message_type = '';

// Default form values
$form_min_preis = '';
$form_max_preis = '';
$form_step_small = '0.01';
$form_step_big = '0.10';
$artikel_id_for_buybox = null;


// --- Handle Settings Update (POST Request) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings']) && empty($db_error)) {
    $posted_asin = filter_input(INPUT_POST, 'asin', FILTER_SANITIZE_FULL_SPECIAL_CHARS); // ASIN is the key for Preisgrenzen
    // produktid from Artikel is not directly used to update Preisgrenzen but good to have for context.
    // $posted_produktid_artikel = filter_input(INPUT_POST, 'produktid_artikel', FILTER_VALIDATE_INT);


    $min_preis_str = filter_input(INPUT_POST, 'min_preis');
    $max_preis_str = filter_input(INPUT_POST, 'max_preis');
    $stepsize_small_str = filter_input(INPUT_POST, 'stepsize_small');
    $stepsize_big_str = filter_input(INPUT_POST, 'stepsize_big');

    $selectedAsin = $posted_asin ?: filter_input(INPUT_GET, 'asin', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    $min_preis = filter_var(str_replace(',', '.', $min_preis_str), FILTER_VALIDATE_FLOAT);
    $max_preis = filter_var(str_replace(',', '.', $max_preis_str), FILTER_VALIDATE_FLOAT);
    $stepsize_small = filter_var(str_replace(',', '.', $stepsize_small_str), FILTER_VALIDATE_FLOAT);
    $stepsize_big = filter_var(str_replace(',', '.', $stepsize_big_str), FILTER_VALIDATE_FLOAT);

    $validation_passed = true;
    if (empty($selectedAsin) || !preg_match('/^[A-Z0-9]{10}$/', $selectedAsin)) {
        $update_message = 'Fehler: Ungültige ASIN für Update.';
        $validation_passed = false;
    }
    // Further validations (prices, steps)
    elseif ($min_preis === false || $min_preis < 0) {
        $update_message = 'Fehler: Ungültiger Minimalpreis.';
        $validation_passed = false;
    } elseif ($max_preis === false || $max_preis < 0) {
        $update_message = 'Fehler: Ungültiger Maximalpreis.';
        $validation_passed = false;
    } elseif ($min_preis > $max_preis) {
        $update_message = 'Fehler: Minimalpreis > Maximalpreis.';
        $validation_passed = false;
    } elseif ($stepsize_small === false || $stepsize_small <= 0) {
        $update_message = 'Fehler: Kleine Schrittgröße <= 0.';
        $validation_passed = false;
    } elseif ($stepsize_big === false || $stepsize_big <= 0) {
        $update_message = 'Fehler: Große Schrittgröße <= 0.';
        $validation_passed = false;
    } elseif ($stepsize_small > $stepsize_big) {
        $update_message = 'Fehler: Kleine Schrittgröße > Große Schrittgröße.';
        $validation_passed = false;
    }


    if ($validation_passed) {
        try {
            // Update Preisgrenzen table
            $stmtUpdate = $dbConnection->prepare(
                "UPDATE Preisgrenzen SET
                    min_preis = :min_preis, max_preis = :max_preis,
                    stepsize_small = :stepsize_small, stepsize_big = :stepsize_big
                 WHERE ASIN = :asin AND Land = :land"
            );
            $stmtUpdate->bindParam(':min_preis', $min_preis);
            $stmtUpdate->bindParam(':max_preis', $max_preis);
            $stmtUpdate->bindParam(':stepsize_small', $stepsize_small);
            $stmtUpdate->bindParam(':stepsize_big', $stepsize_big);
            $stmtUpdate->bindParam(':asin', $selectedAsin);
            $stmtUpdate->bindParam(':land', $current_marketplace_code);

            if ($stmtUpdate->execute()) {
                if ($stmtUpdate->rowCount() > 0) {
                    $update_message = 'Einstellungen für ASIN ' . htmlspecialchars($selectedAsin) . ' in Land ' . htmlspecialchars($current_marketplace_code) . ' erfolgreich aktualisiert!';
                    $update_message_type = 'success';
                } else {
                    // Entry might not exist, or values were the same.
                    // Attempt an INSERT if no rows were updated, assuming user wants to create if missing.
                    $stmtCheck = $dbConnection->prepare("SELECT 1 FROM Preisgrenzen WHERE ASIN = :asin AND Land = :land");
                    $stmtCheck->bindParam(':asin', $selectedAsin);
                    $stmtCheck->bindParam(':land', $current_marketplace_code);
                    $stmtCheck->execute();
                    if (!$stmtCheck->fetch()) {
                        $stmtInsert = $dbConnection->prepare(
                            "INSERT INTO Preisgrenzen (ASIN, Land, min_preis, max_preis, stepsize_small, stepsize_big)
                             VALUES (:asin, :land, :min_preis, :max_preis, :stepsize_small, :stepsize_big)"
                        );
                        // Bind all params again for $stmtInsert
                        $stmtInsert->bindParam(':asin', $selectedAsin);
                        $stmtInsert->bindParam(':land', $current_marketplace_code);
                        $stmtInsert->bindParam(':min_preis', $min_preis);
                        $stmtInsert->bindParam(':max_preis', $max_preis);
                        $stmtInsert->bindParam(':stepsize_small', $stepsize_small);
                        $stmtInsert->bindParam(':stepsize_big', $stepsize_big);
                        $stmtInsert->execute();
                        $update_message = 'Einstellungen für ASIN ' . htmlspecialchars($selectedAsin) . ' in Land ' . htmlspecialchars($current_marketplace_code) . ' neu angelegt!';
                        $update_message_type = 'success';
                    } else {
                        $update_message = 'Keine Änderungen an den Einstellungen vorgenommen (Werte waren gleich).';
                        $update_message_type = 'info';
                    }
                }
            } else {
                throw new PDOException("Fehler beim Ausführen des Update/Insert Statements für Preisgrenzen.");
            }
        } catch (\PDOException $e) {
            error_log("Update Fehler für Preisgrenzen ASIN $selectedAsin / Land $current_marketplace_code: " . $e->getMessage());
            $update_message = "Datenbankfehler beim Aktualisieren der Preisgrenzen.";
            $update_message_type = 'error';
        }
    } else {
        $update_message_type = 'error';
        $form_min_preis = htmlspecialchars($min_preis_str);
        $form_max_preis = htmlspecialchars($max_preis_str);
        $form_step_small = htmlspecialchars($stepsize_small_str);
        $form_step_big = htmlspecialchars($stepsize_big_str);
    }
} else {
    if (isset($_GET['asin']) && !empty($_GET['asin'])) {
        $selectedAsin = filter_input(INPUT_GET, 'asin', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (!preg_match('/^[A-Z0-9]{10}$/', $selectedAsin) && empty($db_error)) {
            $db_error = "Ungültiges ASIN Format übergeben.";
        }
    } elseif (empty($db_error)) { // Don't overwrite initial marketplace config errors
        $db_error = "Es wurde keine ASIN zum Anzeigen der Details übergeben.";
    }
}

// --- Fetch Product Data (if no initial error and ASIN is valid) ---
if (empty($db_error) && !empty($selectedAsin)) {
    try {
        // 1. Get general product information from Artikel table
        $stmtArtikel = $dbConnection->prepare("
            SELECT ID, artikelname, sku
            FROM Artikel
            WHERE asin = :asin
            LIMIT 1
        ");
        $stmtArtikel->bindParam(':asin', $selectedAsin);
        $stmtArtikel->execute();
        $productArtikelDetails = $stmtArtikel->fetch(PDO::FETCH_ASSOC);

        if (!$productArtikelDetails) {
            $db_error = "Keine Artikeldetails für ASIN \"" . htmlspecialchars($selectedAsin) . "\" in der Tabelle 'Artikel' gefunden.";
        } else {
            $artikel_id_for_buybox = $productArtikelDetails['ID']; // Needed for Buybox queries

            $avg_sales_price_7d = 'N/A';
            // 4. Durchschnittlichen Amazon-Verkaufspreis der letzten 7 Tage abrufen
            if (isset($productArtikelDetails['sku'])) {
                try {
                   $stmt_product_id = $dbConnectionTric->prepare("
                    SELECT produktid FROM produkte_felder_werte
                    WHERE feldid = '44' AND wert1 = :sku LIMIT 1
                    ");
                    $stmt_product_id->execute([':sku' => $productArtikelDetails['sku']]);
                    $product_id = $stmt_product_id->fetchColumn();
                    $stmtAvgPrice = $dbConnectionTric->prepare("
                        SELECT SUM(T1.einzelpreis * T1.anzahl) AS total_revenue_pre_vat,
                               SUM(T1.anzahl) AS total_quantity
                        FROM bestellungen_positionen AS T1
                        JOIN bestellungen AS T2 ON T2.id = T1.bestellungsid
                        WHERE T1.datum > DATE_SUB(NOW(), INTERVAL 7 DAY)
                          AND T1.produktid = :product_id
                          AND T2.werbekennzeichen IN (2,8) -- 2 wird als Amazon angenommen
                    ");
                    $stmtAvgPrice->bindParam(':product_id', $product_id, PDO::PARAM_INT);
                    $stmtAvgPrice->execute();
                    $salesSummary = $stmtAvgPrice->fetch(PDO::FETCH_ASSOC);
                    if ($salesSummary && !empty($salesSummary['total_quantity'])) {
                        $revenue_with_vat = (float) $salesSummary['total_revenue_pre_vat'] * 1.19;
                        $avg_sales_price_7d = $revenue_with_vat / (int) $salesSummary['total_quantity'];
                    }
                } catch (\PDOException $e) {
                    error_log("Fehler beim Abrufen des durchschnittlichen Verkaufspreises für ProduktID $artikel_id_for_buybox: " . $e->getMessage());
                    // $avg_sales_price_7d bleibt 'N/A'
                }
            }

            // 2. Get price settings from Preisgrenzen table for the current ASIN and Land
            $stmtPreisgrenzen = $dbConnection->prepare("
                SELECT min_preis, max_preis, stepsize_small, stepsize_big
                FROM Preisgrenzen
                WHERE ASIN = :asin AND Land = :land
                LIMIT 1
            ");
            $stmtPreisgrenzen->bindParam(':asin', $selectedAsin);
            $stmtPreisgrenzen->bindParam(':land', $current_marketplace_code);
            $stmtPreisgrenzen->execute();
            $productPreisgrenzen = $stmtPreisgrenzen->fetch(PDO::FETCH_ASSOC);

            if (!$productPreisgrenzen) {
                // No specific price limits for this country, but Artikel exists.
                // User can still see history, but form will be for creating new limits.
                // $db_error might be too strong, perhaps an info message.
                // For now, form fields will use defaults.
                if ($update_message_type !== 'error') { // Don't overwrite POST errors
                    $update_message = 'Hinweis: Für diese ASIN sind in Land ' . htmlspecialchars($current_marketplace_code) . ' noch keine Preisgrenzen definiert. Sie können diese unten anlegen.';
                    $update_message_type = 'info';
                }
            }

            // Populate form values if not already set by a POST error
            if ($update_message_type !== 'error') {
                $form_min_preis = isset($productPreisgrenzen['min_preis']) ? number_format((float) $productPreisgrenzen['min_preis'], 2, '.', '') : '';
                $form_max_preis = isset($productPreisgrenzen['max_preis']) ? number_format((float) $productPreisgrenzen['max_preis'], 2, '.', '') : '';
                $form_step_small = isset($productPreisgrenzen['stepsize_small']) ? number_format((float) $productPreisgrenzen['stepsize_small'], 2, '.', '') : '0.01';
                $form_step_big = isset($productPreisgrenzen['stepsize_big']) ? number_format((float) $productPreisgrenzen['stepsize_big'], 2, '.', '') : '0.10';
            }
            if (empty($form_min_preis))
                $form_step_small = '0.01'; // Ensure defaults if db returns null
            if (empty($form_max_preis))
                $form_step_big = '0.10';


            // 3. Fetch price history and latest prices from country-specific Buybox table
            if (!empty($country_specific_buybox_table) && $artikel_id_for_buybox) {
                // Get latest 'eigener_preis'
                $stmtOwn = $dbConnection->prepare("SELECT bb.eigenerpreis AS eigener_preis FROM $country_specific_buybox_table bb WHERE bb.produktid = :produktid ORDER BY bb.datum DESC LIMIT 1");
                $stmtOwn->bindParam(':produktid', $artikel_id_for_buybox, PDO::PARAM_INT);
                $stmtOwn->execute();
                $ownPriceResult = $stmtOwn->fetch(PDO::FETCH_ASSOC);
                // Attach to $productArtikelDetails for display convenience, though it's dynamic
                $productArtikelDetails['eigener_preis'] = $ownPriceResult ? $ownPriceResult['eigener_preis'] : 'N/A';

                // Get latest 'niedrigster_preis'
                $stmtLow = $dbConnection->prepare("SELECT bb.niedrigsterPreis AS niedrigster_preis FROM $country_specific_buybox_table bb WHERE bb.produktid = :produktid ORDER BY bb.datum DESC LIMIT 1");
                $stmtLow->bindParam(':produktid', $artikel_id_for_buybox, PDO::PARAM_INT);
                $stmtLow->execute();
                $lowestPriceResult = $stmtLow->fetch(PDO::FETCH_ASSOC);
                $productArtikelDetails['niedrigster_preis'] = $lowestPriceResult ? $lowestPriceResult['niedrigster_preis'] : 'N/A';

                // Get latest 'bbox_preis'
                $stmtBBox = $dbConnection->prepare("SELECT bb.buyboxPreis AS bbox_preis FROM $country_specific_buybox_table bb WHERE bb.produktid = :produktid ORDER BY bb.datum DESC LIMIT 1");
                $stmtBBox->bindParam(':produktid', $artikel_id_for_buybox, PDO::PARAM_INT);
                $stmtBBox->execute();
                $bboxPriceResult = $stmtBBox->fetch(PDO::FETCH_ASSOC);
                $productArtikelDetails['bbox_preis'] = $bboxPriceResult ? $bboxPriceResult['bbox_preis'] : 'N/A';

                // Get price history for the graph
                $stmtHist = $dbConnection->prepare("
                    SELECT bb.datum, bb.eigenerpreis AS eigener_preis, bb.niedrigsterPreis AS niedrigster_preis, bb.buyboxPreis AS bbox_preis, bb.action, bb.counter, bb.isWinner
                    FROM $country_specific_buybox_table bb
                    WHERE bb.produktid = :produktid
                    ORDER BY bb.datum ASC
                ");
                $stmtHist->bindParam(':produktid', $artikel_id_for_buybox, PDO::PARAM_INT);
                $stmtHist->execute();
                $priceHistory = $stmtHist->fetchAll(PDO::FETCH_ASSOC);
            } else {
                if (empty($db_error) && $artikel_id_for_buybox) { // Only set this error if no other critical one exists
                    $db_error = "Buybox Tabellenname für " . htmlspecialchars($current_marketplace_code) . " nicht korrekt konfiguriert oder Artikel ID fehlt.";
                }
            }
        }

    } catch (\PDOException $e) {
        error_log("Fehler beim Abrufen der Produktdaten für ASIN $selectedAsin / Land $current_marketplace_code: " . $e->getMessage());
        $db_error = "Datenbankfehler beim Abrufen der Produktdaten.";
        $productArtikelDetails = null;
        $productPreisgrenzen = null;
        $priceHistory = [];
    }
}
?>
<!DOCTYPE html>
<html lang="de">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Produktdetails <?= htmlspecialchars($current_marketplace_code) ?> -
        <?= htmlspecialchars($selectedAsin ?: 'N/A') ?></title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../results.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/moment"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-moment"></script>
    <script
        src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1/dist/chartjs-plugin-annotation.min.js"></script>
    <link rel="icon" type="image/x-icon" href="../img/tag.ico" sizes="32x32">
</head>

<body>
    <script> const currencySymbolJS = '<?= $currency_symbol ?>'; </script>
    <div class="asin-header">
        <div class="left-container">
            <a href="index.php"><button>&laquo; Zurück</button></a>
            <h1 style="
    display: flex;
    align-items: center;
    gap: .25em;
"> <img src="../img/<?= htmlspecialchars($current_marketplace_code) ?>.png"
                    alt="<?= htmlspecialchars($current_marketplace_code) ?>"
                    style="height: 1em; vertical-align: middle;"><a target="_blank"
                    href="https://www.amazon.de/dp/<?= htmlspecialchars($selectedAsin ?: 'N/A') ?>"><?= htmlspecialchars($selectedAsin ?: 'Keine ASIN') ?></a>
            </h1>
        </div>
        <a href="addNew.php"><button id="addproductBtn">+ Produkt hinzufügen</button></a>
    </div>

    <?php if (!empty($db_error)): ?>
        <p class="message error"><?= htmlspecialchars($db_error) ?></p>
    <?php elseif ($productArtikelDetails): // We need at least Artikel details to show something ?>

        <div class="product-info">
            <h3><?= htmlspecialchars($productArtikelDetails['artikelname']) ?> (SKU:
                <?= htmlspecialchars($productArtikelDetails['sku'] ?? 'N/A') ?>)</h3>
            <div class="price-fields">
                <div class="price-field" title="Eigener Preis"><img src="../img/person.png" alt="Eigener Preis"
                        class="price-icon"><span
                        class="price-value"><?= ($productArtikelDetails['eigener_preis'] !== 'N/A' && $productArtikelDetails['eigener_preis'] !== null) ? number_format((float) $productArtikelDetails['eigener_preis'], 2, '.', '') . $currency_symbol : 'N/A' ?></span>
                </div>
                <div class="price-field" title="Niedrigster Preis"><img src="../img/arrow_down.png" alt="Niedrigster Preis"
                        class="price-icon"><span
                        class="price-value"><?= ($productArtikelDetails['niedrigster_preis'] !== 'N/A' && $productArtikelDetails['niedrigster_preis'] !== null) ? number_format((float) $productArtikelDetails['niedrigster_preis'], 2, '.', '') . $currency_symbol : 'N/A' ?></span>
                </div>
                <div class="price-field" title="BuyBox Preis"><img src="../img/star.png" alt="BuyBox Preis"
                        class="price-icon"><span
                        class="price-value"><?= ($productArtikelDetails['bbox_preis'] !== 'N/A' && $productArtikelDetails['bbox_preis'] !== null) ? number_format((float) $productArtikelDetails['bbox_preis'], 2, '.', '') . $currency_symbol : 'N/A' ?></span>
                </div>
                <div class="price-field" title="BuyBox Anteil">
                    <img src="../img/trophy.png" alt="BuyBox Anteil" class="price-icon">
                    <span class="price-value" id="buyboxanteil">lädt...</span>
                </div>
                <a id="avgPriceReportLink" class="price-field"
                    href="../report.php?sku=<?= htmlspecialchars($productArtikelDetails['sku'] ?? '') ?>&time_period=7&source=amazon"
                    target="_blank" title="Durchschnittl. VK Preis - Klicken, um Bericht zu öffnen.">
                    <img src="../img/avg.png" alt="Durchschnittlicher Verkaufspreis" class="price-icon">
                    <span class="price-value">
                        <?= ($avg_sales_price_7d !== 'N/A') ? number_format($avg_sales_price_7d, 2, '.', '') . $currency_symbol : 'N/A' ?>
                    </span>
                </a>



            </div>

        </div>

        <?php if ($update_message): // Display update messages prominently before the chart/settings ?>
            <div class="update-message-area" style="margin-bottom: 15px; text-align:center;">
                <div class="message <?= htmlspecialchars($update_message_type) ?>" style="display: inline-block;">
                    <?= htmlspecialchars($update_message) ?>
                </div>
            </div>
        <?php endif; ?>


        <div class="price-history-graph">
            <h3>Preisentwicklung <?= htmlspecialchars($current_marketplace_name) ?>:</h3>

            <div class="chart-and-settings-wrapper">
                <div class="chart-container">
                    <?php if (!empty($priceHistory)): ?>
                        <canvas id="priceChart"></canvas>
                    <?php else: ?>
                        <p>Keine Preisdaten für Diagramm in Land <?= htmlspecialchars($current_marketplace_code) ?> verfügbar.
                        </p>
                    <?php endif; ?>
                </div>

                <form class="compact-settings-form"
                    action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>?asin=<?= urlencode($selectedAsin) ?>"
                    method="POST">
                    <h4>Preisgrenzen für <?= htmlspecialchars($current_marketplace_code) ?></h4>
                    <input type="hidden" name="asin" value="<?= htmlspecialchars($selectedAsin) ?>">
                    <div class="input-group">
                        <label for="min_preis">Min Preis (<?= $currency_symbol ?>):</label>
                        <input type="number" step="0.01" id="min_preis" name="min_preis" required
                            value="<?= htmlspecialchars($form_min_preis) ?>" title="Minimalpreis">
                    </div>
                    <div class="input-group">
                        <label for="max_preis">Max Preis (<?= $currency_symbol ?>):</label>
                        <input type="number" step="0.01" id="max_preis" name="max_preis" required
                            value="<?= htmlspecialchars($form_max_preis) ?>" title="Maximalpreis">
                    </div>
                    <div class="input-group">
                        <label for="stepsize_small">Step Klein (<?= $currency_symbol ?>):</label>
                        <input type="number" step="0.01" id="stepsize_small" name="stepsize_small" required
                            value="<?= htmlspecialchars($form_step_small) ?>"
                            title="Kleine Schrittgröße (<10 <?= $currency_symbol ?>)">
                    </div>
                    <div class="input-group">
                        <label for="stepsize_big">Step Groß (<?= $currency_symbol ?>):</label>
                        <input type="number" step="0.01" id="stepsize_big" name="stepsize_big" required
                            value="<?= htmlspecialchars($form_step_big) ?>"
                            title="Große Schrittgröße (>=10 <?= $currency_symbol ?>)">
                    </div>
                    <button type="submit" name="update_settings" title="Preisgrenzen speichern">Speichern</button>
                </form>
            </div>

            <div class="chart-controls-container">
                <div class="timespan-controls controls">
                    <label for="timespan">Zeitraum:</label>
                    <select id="timespan" name="timespan" onchange="updateChart()">
                        <option value="1">Letzte Stunde</option>
                        <option value="12">Letzte 12 Stunden</option>
                        <option value="24">Letzte 24 Stunden</option>
                        <option value="7" selected>Letzte 7 Tage</option>
                        <option value="30">Letzte 30 Tage</option>
                        <option value="90">Letzte 90 Tage</option>
                        <option value="365">Letztes Jahr</option>
                        <option value="all">Gesamter Zeitraum</option>
                    </select>
                </div>
                <div class="action-filter-controls controls">
                    <label for="actionFilter">Actions</label>
                    <select id="actionFilter" name="actionFilter" onchange="updateChart()">
                        <option value="all">Alle</option>
                        <option value="update">Nur 'update'</option>
                        <option value="init_document">Nur 'init'/'document'</option>
                    </select>
                </div>
            </div>
        </div>

        <script>


            const rawPriceData = <?php echo json_encode($priceHistory); ?>;
    let currentChart;
    const chartMinPreisLine = <?= isset($productPreisgrenzen['min_preis']) && $productPreisgrenzen['min_preis'] !== null ? json_encode((float) $productPreisgrenzen['min_preis']) : 'null'; ?>;
    const chartMaxPreisLine = <?= isset($productPreisgrenzen['max_preis']) && $productPreisgrenzen['max_preis'] !== null ? json_encode((float) $productPreisgrenzen['max_preis']) : 'null'; ?>;
    const sku = '<?= htmlspecialchars($productArtikelDetails['sku'] ?? '') ?>';
    const asin = '<?= htmlspecialchars($selectedAsin) ?>';

    Chart.register(window['chartjs-plugin-annotation']);

    function fetchAndUpdateAvgPrice(timespan, sku) {
        const avgPriceElement = document.querySelector('#avgPriceReportLink .price-value');
        const url = `get_avg_price.php?timespan=${timespan}&sku=${sku}&asin=${asin}`;

        fetch(url)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
            .then(data => {
                if (data.error) {
                    avgPriceElement.textContent = 'Fehler';
                    console.error('API Error:', data.error);
                    return;
                }
                if (data.avg_price === 'N/A') {
                    avgPriceElement.textContent = 'N/A';
                } else {
                    const formattedPrice = parseFloat(data.avg_price).toFixed(2);
                    avgPriceElement.textContent = formattedPrice + currencySymbolJS;
                }
            })
            .catch(error => {
                avgPriceElement.textContent = 'N/A';
                console.error('Fetch error:', error);
            });
    }

            function createChart(dataToDisplay) {
                let buyboxyes = 0;
                let buyboxno = 0;

                const ctx = document.getElementById('priceChart')?.getContext('2d');
                if (!ctx) return;

                if (currentChart) {
                    currentChart.destroy();
                }
                ctx.clearRect(0, 0, ctx.canvas.width, ctx.canvas.height);

                if (!dataToDisplay || dataToDisplay.length === 0) {
                    // console.log("Keine Daten zum Anzeigen im Diagramm nach Filterung.");
                    // Optionally display a message on the canvas
                    // ctx.font = "16px Arial";
                    // ctx.fillStyle = "grey";
                    // ctx.textAlign = "center";
                    // ctx.fillText("Keine Daten für ausgewählten Zeitraum/Filter.", ctx.canvas.width / 2, ctx.canvas.height / 2);
                    document.querySelector('.chart-container').innerHTML = '<p style="text-align:center; padding: 20px;">Keine Daten für ausgewählten Zeitraum/Filter im Diagramm verfügbar.</p>';
                    return;
                } else {
                    // If data becomes available again, ensure canvas is there
                    if (!document.getElementById('priceChart')) {
                        document.querySelector('.chart-container').innerHTML = '<canvas id="priceChart"></canvas>';
                        // Re-get context if canvas was recreated
                        // const newCtx = document.getElementById('priceChart').getContext('2d');
                        // if (!newCtx) return; // Should not happen
                        // ctx = newCtx; // This reassignment won't work due to const. Better to re-call createChart.
                        updateChart(); // Re-trigger with fresh canvas
                        return;
                    }
                }


                const backgroundAnnotations = [];

                if (dataToDisplay && dataToDisplay.length > 1) {
                    let segmentStartIndex = 0;
                    let currentStatus = dataToDisplay[0].isWinner;
                    for (let i = 1; i < dataToDisplay.length; i++) {
                        if (dataToDisplay[i].isWinner === 'Ja') buyboxyes = buyboxyes + 1;
                        else if (dataToDisplay[i].isWinner === 'Nein') buyboxno = buyboxno + 1;
                        if (dataToDisplay[i].isWinner !== currentStatus || i === dataToDisplay.length - 1) {
                            const segmentEndIndex = i;
                            let bgColor;
                            const lowerCaseStatus = typeof currentStatus === 'string' ? currentStatus.toLowerCase() : String(currentStatus);

                            if (lowerCaseStatus === 'ja') {
                                bgColor = 'rgb(144, 238, 144,0.3)'; // LightGreen
                            }
                            else if
                                (lowerCaseStatus === 'nein') { bgColor = 'rgba(255, 182, 193, 0.3)'; }// LightPink


                            else { bgColor = 'rgba(211, 211, 211, 0.1)'; buyboxyes = buyboxyes + 1 }// LightGrey for NULL or other


                            backgroundAnnotations.push({
                                type: 'box',
                                xScaleID: 'x', yScaleID: 'y',
                                xMin: dataToDisplay[segmentStartIndex].datum,
                                xMax: dataToDisplay[segmentEndIndex].datum,
                                backgroundColor: bgColor,
                                borderColor: 'transparent', borderWidth: 0,
                            });
                            segmentStartIndex = i;
                            currentStatus = dataToDisplay[i].isWinner;
                        }
                    }
                }


                const dates = dataToDisplay.map(item => item.datum);
                const eigenerPreisData = dataToDisplay.map(item => item.eigener_preis !== null ? parseFloat(item.eigener_preis) : null);
                const niedrigsterPreisData = dataToDisplay.map(item => item.niedrigster_preis !== null ? parseFloat(item.niedrigster_preis) : null);
                const bboxPreisData = dataToDisplay.map(item => item.bbox_preis !== null ? parseFloat(item.bbox_preis) : null);

                const datasets = [
                    { label: 'Eigener Preis', data: eigenerPreisData, borderColor: 'rgba(54, 162, 235, 1)', backgroundColor: 'rgba(54, 162, 235, 0.5)', fill: false, tension: 0.1, pointRadius: 2, pointHoverRadius: 5, spanGaps: true },
                    { label: 'Buy Box Preis', data: bboxPreisData, borderColor: 'rgba(255, 159, 64, 1)', backgroundColor: 'rgba(255, 159, 64, 0.5)', fill: false, tension: 0.1, pointRadius: 2, pointHoverRadius: 5, spanGaps: true },
                    { label: 'Niedrigster Preis', data: niedrigsterPreisData, borderColor: 'rgb(192, 75, 104)', backgroundColor: 'rgba(192, 75, 104, 0.5)', fill: false, tension: 0.1, pointRadius: 2, pointHoverRadius: 5, spanGaps: true }
                ];

                if (chartMinPreisLine !== null) {
                    datasets.push({ label: 'Min. Preis (Grenze)', data: dates.map(() => chartMinPreisLine), borderColor: 'rgba(102, 102, 102, 0.7)', borderWidth: 2, borderDash: [5, 5], fill: false, pointRadius: 0, hidden: true, spanGaps: true });
                }
                if (chartMaxPreisLine !== null) {
                    datasets.push({ label: 'Max. Preis (Grenze)', data: dates.map(() => chartMaxPreisLine), borderColor: 'rgba(102, 102, 102, 0.7)', borderWidth: 2, borderDash: [5, 5], fill: false, pointRadius: 0, hidden: true, spanGaps: true });
                }

                currentChart = new Chart(ctx, {
                    type: 'line',
                    data: { labels: dates, datasets: datasets },
                    options: {
                        responsive: true, aspectRatio: 4, maintainAspectRatio: false,
                        plugins: {
                            tooltip: {
                                mode: 'index', intersect: false, backgroundColor: 'rgba(0, 0, 0, 0.8)',
                                callbacks: {
                                    label: function (context) {
                                        let label = context.dataset.label || '';
                                        if (label) { label += ': '; }
                                        if (context.parsed.y !== null) {
                                            // Use Intl.NumberFormat for currency formatting, but symbol comes from JS
                                            label += new Intl.NumberFormat('de-DE', { style: 'decimal', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(context.parsed.y) + currencySymbolJS;
                                        }
                                        return label;
                                    },
                                    footer: function (tooltipItems) {
                                        const firstItem = tooltipItems[0];
                                        if (!firstItem || typeof firstItem.dataIndex === 'undefined' || !dataToDisplay[firstItem.dataIndex]) return '';
                                        const dataPoint = dataToDisplay[firstItem.dataIndex];
                                        const footerLines = [];
                                        let winnerStatus = 'Unbekannt';
                                        if (typeof dataPoint.isWinner === 'string') {
                                            if (dataPoint.isWinner.toLowerCase() === 'ja') winnerStatus = 'Ja';
                                            else if (dataPoint.isWinner.toLowerCase() === 'nein') winnerStatus = 'Nein';
                                            else if (dataPoint.isWinner) winnerStatus = dataPoint.isWinner;
                                        } else if (dataPoint.isWinner === null) winnerStatus = 'NULL';
                                        footerLines.push(`Gewinner: ${winnerStatus}`);
                                        if (dataPoint.action) footerLines.push(`Action: ${dataPoint.action}`);
                                        if (dataPoint.counter !== null && typeof dataPoint.counter !== 'undefined') footerLines.push(`Counter: ${dataPoint.counter}`);
                                        return footerLines;
                                    }
                                }
                            },
                            legend: { labels: { usePointStyle: true, pointStyle: 'circle', font: { size: 12 } } },
                            annotation: { annotations: backgroundAnnotations }
                        },
                        scales: {
                            x: { id: 'x', type: 'time', time: { unit: 'day', tooltipFormat: 'DD.MM.YYYY HH:mm:ss' }, grid: { display: true }, title: { display: true, text: 'Datum', font: { size: 14 } } },
                            y: { id: 'y', beginAtZero: false, grid: { borderDash: [8, 4] }, title: { display: true, text: 'Preis (' + currencySymbolJS + ')', font: { size: 14 } } }
                        }
                    }
                });
                setBuyBoxAnteil((((buyboxyes / (buyboxyes + buyboxno)) * 100).toFixed(2)));

            }

            function setBuyBoxAnteil(value) {
                const element = document.getElementById('buyboxanteil');

                // Ensure value is numeric and between 0–100
                value = Math.max(0, Math.min(100, parseFloat(value) || 0));

                // Update the displayed number
                element.innerHTML = value.toFixed(2) + '%';

                // Update background color (red → green)
                const red = Math.round(255 * (100 - value) / 100);
                const green = Math.round(255 * value / 100);
                element.style.color = `rgb(${red},${green},0)`;
            }


            function filterDataByTimespan(dataToFilter, timespan) {
                if (!Array.isArray(dataToFilter)) return [];
                if (timespan === 'all') return dataToFilter;
                const now = moment();
                let cutoffDate;
                switch (timespan) {
                    case '1': cutoffDate = moment(now).subtract(1, 'hours'); break;
                    case '12': cutoffDate = moment(now).subtract(12, 'hours'); break;
                    case '24': cutoffDate = moment(now).subtract(24, 'hours'); break;
                    case '7': cutoffDate = moment(now).subtract(7, 'days'); break;
                    case '30': cutoffDate = moment(now).subtract(30, 'days'); break;
                    case '90': cutoffDate = moment(now).subtract(90, 'days'); break;
                    case '365': cutoffDate = moment(now).subtract(365, 'days'); break;
                    default: return dataToFilter;
                }
                return dataToFilter.filter(item => moment(item.datum).isSameOrAfter(cutoffDate));
            }

            function filterDataByAction(dataToFilter, actionFilterValue) {
                if (!Array.isArray(dataToFilter)) return [];
                switch (actionFilterValue) {
                    case 'update': return dataToFilter.filter(item => item.action === 'update');
                    case 'init_document': return dataToFilter.filter(item => item.action === 'init' || item.action === 'document');
                    case 'all': default: return dataToFilter;
                }
            }

            function updateChart() {
                const timespanValue = document.getElementById('timespan')?.value;
                const actionFilterValue = document.getElementById('actionFilter')?.value;
                const reportLink = document.getElementById('avgPriceReportLink');
                fetchAndUpdateAvgPrice(timespanValue, sku);
                // Der Link wird aktualisiert, um den im Diagramm ausgewählten Zeitraum widerzuspiegeln
                if (reportLink) {
                    let reportDays = '7'; // Standardwert
                    switch (timespanValue) {
                        case '1':   // 1 Stunde
                        case '12':  // 12 Stunden
                        case '24':  // 24 Stunden
                            reportDays = '1'; // Zeiträume in Stunden auf einen 1-Tages-Bericht abbilden
                            break;
                        case '7':
                        case '30':
                        case '90':
                        case '365':
                            reportDays = timespanValue;
                            break;
                        case 'all':
                            reportDays = '3650'; // 'Gesamter Zeitraum' auf 10 Jahre abbilden
                            break;
                    }
                    // Die URL API wird verwendet, um den Parameter sicher zu aktualisieren
                    try {
                        const currentUrl = new URL(reportLink.href);
                        currentUrl.searchParams.set('time_period', reportDays);
                        reportLink.href = currentUrl.toString();
                    } catch (e) {
                        console.error("Konnte die URL des Berichtslinks nicht aktualisieren:", e);
                    }
                }
                if (!timespanValue || !actionFilterValue || !rawPriceData) { // Ensure controls and data exist
                    // console.log("Bedingungen für Chart-Update nicht erfüllt.");
                    if (document.getElementById('priceChart')) { // If canvas exists but no data, clear it or show message
                        if (currentChart) currentChart.destroy();
                        const ctx = document.getElementById('priceChart').getContext('2d');
                        ctx.clearRect(0, 0, ctx.canvas.width, ctx.canvas.height);
                        document.querySelector('.chart-container').innerHTML = '<p style="text-align:center; padding: 20px;">Keine Daten für Diagramm verfügbar oder Filter nicht gesetzt.</p>';
                    }
                    return;
                }
                const timeFilteredData = filterDataByTimespan(rawPriceData, timespanValue);
                const finalFilteredData = filterDataByAction(timeFilteredData, actionFilterValue);
                createChart(finalFilteredData);
            }

            document.addEventListener('DOMContentLoaded', () => {
                if (document.getElementById('priceChart') && rawPriceData && rawPriceData.length > 0) {
                    updateChart();
                } else if (document.querySelector('.chart-container') && (!rawPriceData || rawPriceData.length === 0)) {
                    document.querySelector('.chart-container').innerHTML = '<p style="text-align:center; padding: 20px;">Keine Preisdaten für Diagramm in Land <?= htmlspecialchars($current_marketplace_code) ?> verfügbar.</p>';
                }
                // Attach event listeners even if no initial data, for when filters change
                const timespanSelect = document.getElementById('timespan');
                const actionFilterSelect = document.getElementById('actionFilter');
                if (timespanSelect) timespanSelect.onchange = updateChart;
                if (actionFilterSelect) actionFilterSelect.onchange = updateChart;
            });
        </script>

    <?php elseif (!empty($_GET['asin']) && !$db_error): ?>
        <p class="message error">Keine Produktdetails für die ASIN "<?= htmlspecialchars($selectedAsin) ?>" in Land
            <?= htmlspecialchars($current_marketplace_code) ?> gefunden oder konfiguriert.</p>
    <?php endif; ?>

</body>

</html>