<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('default_charset', 'UTF-8');
ini_set('error_log', APP_ROOT . '/error.log');

require_once APP_ROOT . '/config/marketplaces.php';
require_once APP_ROOT . '/config/db_connection.php';

$dbConnection = $dbConnectionTric4Calc;

// --- Determine current country from directory path ---
$current_marketplace_code = isset($_GET['country']) ? strtoupper(filter_input(INPUT_GET, 'country', FILTER_SANITIZE_STRING)) : (isset($_POST['country']) ? strtoupper(filter_input(INPUT_POST, 'country', FILTER_SANITIZE_STRING)) : ''); if(empty($current_marketplace_code)) die("Missing country in results.php");
$currency_symbol = '€'; // Default currency symbol

$country_specific_buybox_table = '';
$db_error = ''; 

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
$productArtikelDetails = null; 
$productPreisgrenzen = null;   
$priceHistory = [];
$selectedAsin = '';
$update_message = '';
$update_message_type = '';
$delete_message = '';
$delete_message_type = '';

$form_min_preis = '';
$form_max_preis = '';
$form_step_small = '0.01';
$form_step_big = '0.10';
$artikel_id_for_buybox = null;

$is_delete_request = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_item']);
$is_update_request = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_settings']);

// --- Handle Delete Request (POST Request) ---
if ($is_delete_request && empty($db_error)) {
    $posted_asin = filter_input(INPUT_POST, 'asin', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $selectedAsin = $posted_asin ?: filter_input(INPUT_GET, 'asin', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    if (empty($selectedAsin) || !preg_match('/^[A-Z0-9]{10}$/', $selectedAsin)) {
        $delete_message = 'Fehler: Ungueltige ASIN fuer die Loeschung.';
        $delete_message_type = 'error';
    } else {
        try {
            $dbConnection->beginTransaction();

            $stmtArtikelId = $dbConnection->prepare("SELECT ID FROM Artikel WHERE asin = :asin LIMIT 1");
            $stmtArtikelId->bindParam(':asin', $selectedAsin);
            $stmtArtikelId->execute();
            $artikel_id_for_buybox = $stmtArtikelId->fetchColumn();

            if (!$artikel_id_for_buybox) {
                throw new Exception('Artikel wurde nicht gefunden.');
            }

            $stmtDeletePreisgrenzen = $dbConnection->prepare(
                "DELETE FROM Preisgrenzen WHERE ASIN = :asin AND Land = :land"
            );
            $stmtDeletePreisgrenzen->bindParam(':asin', $selectedAsin);
            $stmtDeletePreisgrenzen->bindParam(':land', $current_marketplace_code);
            $stmtDeletePreisgrenzen->execute();
            $deleted_preisgrenzen = $stmtDeletePreisgrenzen->rowCount();

            if (empty($country_specific_buybox_table)) {
                throw new Exception('Buybox Tabellenname fuer das Land ist nicht konfiguriert.');
            }

            $stmtDeleteHistory = $dbConnection->prepare(
                "DELETE FROM $country_specific_buybox_table WHERE produktid = :produktid"
            );
            $stmtDeleteHistory->bindParam(':produktid', $artikel_id_for_buybox, PDO::PARAM_INT);
            $stmtDeleteHistory->execute();
            $deleted_history = $stmtDeleteHistory->rowCount();

            $dbConnection->commit();

            $delete_message = 'Produkt ' . htmlspecialchars($selectedAsin) . ' fuer Land ' . htmlspecialchars($current_marketplace_code)
                . ' geloescht. Preisgrenzen entfernt: ' . $deleted_preisgrenzen
                . '. Historie geloescht: ' . $deleted_history . '.';
            $delete_message_type = 'success';
        } catch (\PDOException $e) {
            if ($dbConnection->inTransaction()) $dbConnection->rollBack();
            error_log("Loesch-Fehler fuer ASIN $selectedAsin / Land $current_marketplace_code: " . $e->getMessage());
            $delete_message = 'Datenbankfehler beim Loeschen des Produkts.';
            $delete_message_type = 'error';
        } catch (\Exception $e) {
            if ($dbConnection->inTransaction()) $dbConnection->rollBack();
            error_log("Loesch-Fehler fuer ASIN $selectedAsin / Land $current_marketplace_code: " . $e->getMessage());
            $delete_message = 'Fehler beim Loeschen des Produkts.';
            $delete_message_type = 'error';
        }
    }
}

// --- Handle Settings Update (POST Request) ---
if ($is_update_request && empty($db_error)) {
    $posted_asin = filter_input(INPUT_POST, 'asin', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
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
                    $stmtCheck = $dbConnection->prepare("SELECT 1 FROM Preisgrenzen WHERE ASIN = :asin AND Land = :land");
                    $stmtCheck->bindParam(':asin', $selectedAsin);
                    $stmtCheck->bindParam(':land', $current_marketplace_code);
                    $stmtCheck->execute();
                    if (!$stmtCheck->fetch()) {
                        $stmtInsert = $dbConnection->prepare(
                            "INSERT INTO Preisgrenzen (ASIN, Land, min_preis, max_preis, stepsize_small, stepsize_big)
                             VALUES (:asin, :land, :min_preis, :max_preis, :stepsize_small, :stepsize_big)"
                        );
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
} elseif (!$is_delete_request) {
    if (isset($_GET['asin']) && !empty($_GET['asin'])) {
        $selectedAsin = filter_input(INPUT_GET, 'asin', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        if (!preg_match('/^[A-Z0-9]{10}$/', $selectedAsin) && empty($db_error)) {
            $db_error = "Ungültiges ASIN Format übergeben.";
        }
    } elseif (empty($db_error)) { 
        $db_error = "Es wurde keine ASIN zum Anzeigen der Details übergeben.";
    }
}

// --- Fetch Product Data ---
if (empty($db_error) && !empty($selectedAsin)) {
    try {
        $stmtArtikel = $dbConnection->prepare("
            SELECT A.ID, A.artikelname, A.sku,
                   P.min_preis, P.max_preis, P.stepsize_small, P.stepsize_big
            FROM Artikel A
            LEFT JOIN Preisgrenzen P
                ON P.ASIN = A.asin AND P.Land = :land
            WHERE A.asin = :asin
            LIMIT 1
        ");
        $stmtArtikel->bindParam(':asin', $selectedAsin);
        $stmtArtikel->bindParam(':land', $current_marketplace_code);
        $stmtArtikel->execute();
        $productRow = $stmtArtikel->fetch(PDO::FETCH_ASSOC);

        if (!$productRow) {
            $db_error = "Keine Artikeldetails für ASIN \"" . htmlspecialchars($selectedAsin) . "\" in der Tabelle 'Artikel' gefunden.";
        } else {
            $productArtikelDetails = [
                'ID' => $productRow['ID'],
                'artikelname' => $productRow['artikelname'],
                'sku' => $productRow['sku'],
            ];
            $artikel_id_for_buybox = $productArtikelDetails['ID']; 

            $hasPreisgrenzen = $productRow['min_preis'] !== null
                || $productRow['max_preis'] !== null
                || $productRow['stepsize_small'] !== null
                || $productRow['stepsize_big'] !== null;
            $productPreisgrenzen = $hasPreisgrenzen ? [
                'min_preis' => $productRow['min_preis'],
                'max_preis' => $productRow['max_preis'],
                'stepsize_small' => $productRow['stepsize_small'],
                'stepsize_big' => $productRow['stepsize_big'],
            ] : null;

            $avg_sales_price_7d = 'N/A';
            if (isset($productArtikelDetails['sku'])) {
                try {
                   $stmt_product_id = $dbConnectionTric->prepare("
                    SELECT produktid FROM produkte_felder_werte
                    WHERE feldid = '44' AND wert1 = :sku LIMIT 1
                    ");
                    $stmt_product_id->execute([':sku' => $productArtikelDetails['sku']]);
                    $product_id = $stmt_product_id->fetchColumn();
                    if ($product_id) {
                        $stmtAvgPrice = $dbConnectionTric->prepare("
                            SELECT SUM(T1.einzelpreis * T1.anzahl) AS total_revenue_pre_vat,
                                   SUM(T1.anzahl) AS total_quantity
                            FROM bestellungen_positionen AS T1
                            JOIN bestellungen AS T2 ON T2.id = T1.bestellungsid
                            WHERE T1.datum > DATE_SUB(NOW(), INTERVAL 7 DAY)
                              AND T1.produktid = :product_id
                              AND T2.werbekennzeichen IN (2,8) 
                        ");
                        $stmtAvgPrice->bindParam(':product_id', $product_id, PDO::PARAM_INT);
                        $stmtAvgPrice->execute();
                        $salesSummary = $stmtAvgPrice->fetch(PDO::FETCH_ASSOC);
                        if ($salesSummary && !empty($salesSummary['total_quantity'])) {
                            $revenue_with_vat = (float) $salesSummary['total_revenue_pre_vat'] * 1.19;
                            $avg_sales_price_7d = $revenue_with_vat / (int) $salesSummary['total_quantity'];
                        }
                    }
                } catch (\PDOException $e) {
                    error_log("Fehler beim Abrufen des durchschnittlichen Verkaufspreises für ProduktID $artikel_id_for_buybox: " . $e->getMessage());
                }
            }

            if (!$productPreisgrenzen) {
                if ($update_message_type !== 'error') { 
                    $update_message = 'Hinweis: Für diese ASIN sind in Land ' . htmlspecialchars($current_marketplace_code) . ' noch keine Preisgrenzen definiert.';
                    $update_message_type = 'info';
                }
            }

            if ($update_message_type !== 'error') {
                $form_min_preis = isset($productPreisgrenzen['min_preis']) ? number_format((float) $productPreisgrenzen['min_preis'], 2, '.', '') : '';
                $form_max_preis = isset($productPreisgrenzen['max_preis']) ? number_format((float) $productPreisgrenzen['max_preis'], 2, '.', '') : '';
                $form_step_small = isset($productPreisgrenzen['stepsize_small']) ? number_format((float) $productPreisgrenzen['stepsize_small'], 2, '.', '') : '0.01';
                $form_step_big = isset($productPreisgrenzen['stepsize_big']) ? number_format((float) $productPreisgrenzen['stepsize_big'], 2, '.', '') : '0.10';
            }
            if (empty($form_min_preis)) $form_step_small = '0.01'; 
            if (empty($form_max_preis)) $form_step_big = '0.10';

            if (!empty($country_specific_buybox_table) && $artikel_id_for_buybox) {
                $stmtHist = $dbConnection->prepare("
                    SELECT bb.datum, bb.eigenerpreis AS eigener_preis, bb.niedrigsterPreis AS niedrigster_preis, bb.buyboxPreis AS bbox_preis, bb.action, bb.counter, bb.isWinner
                    FROM $country_specific_buybox_table bb
                    WHERE bb.produktid = :produktid
                    ORDER BY bb.datum ASC
                ");
                $stmtHist->bindParam(':produktid', $artikel_id_for_buybox, PDO::PARAM_INT);
                $stmtHist->execute();
                $priceHistory = $stmtHist->fetchAll(PDO::FETCH_ASSOC);

                $productArtikelDetails['eigener_preis'] = 'N/A';
                $productArtikelDetails['niedrigster_preis'] = 'N/A';
                $productArtikelDetails['bbox_preis'] = 'N/A';
                if (!empty($priceHistory)) {
                    $latestRow = $priceHistory[count($priceHistory) - 1];
                    $productArtikelDetails['eigener_preis'] = $latestRow['eigener_preis'] !== null ? $latestRow['eigener_preis'] : 'N/A';
                    $productArtikelDetails['niedrigster_preis'] = $latestRow['niedrigster_preis'] !== null ? $latestRow['niedrigster_preis'] : 'N/A';
                    $productArtikelDetails['bbox_preis'] = $latestRow['bbox_preis'] !== null ? $latestRow['bbox_preis'] : 'N/A';
                }
            } else {
                if (empty($db_error) && $artikel_id_for_buybox) {
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
    <title>Produktdetails <?= htmlspecialchars($current_marketplace_code) ?> - <?= htmlspecialchars($selectedAsin ?: 'N/A') ?></title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/moment"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-moment"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-annotation@3.0.1/dist/chartjs-plugin-annotation.min.js"></script>
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
            max-width: 80vw;
            margin: 0 auto;
        }

        .top-nav {
            margin-bottom: 24px;
        }

        /* --- Hero Section --- */
        .hero {
            display: flex;
            flex-wrap: wrap;
            gap: 32px;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 32px;
        }

        .hero-text .eyebrow {
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 8px;
        }

        .hero-text h1 {
            font-size: 2.25rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .hero-text h1 a {
            color: var(--ink);
            text-decoration: none;
        }
        .hero-text h1 a:hover {
            color: var(--primary);
            text-decoration: underline;
        }

        .flag-icon {
            height: 1.1em;
            border-radius: 4px;
            box-shadow: var(--shadow-sm);
        }

        .hero-text .subtitle {
            color: var(--muted);
            font-size: 1.1rem;
            max-width: 600px;
            margin-bottom: 24px;
            font-weight: 500;
        }

        .hero-stats {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            flex: 1;
            justify-content: flex-end;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--stroke);
            border-radius: var(--radius);
            padding: 16px 20px;
            box-shadow: var(--shadow-sm);
            min-width: 150px;
            flex: 1;
            max-width: 220px;
            text-decoration: none; 
            color: inherit;
        }
        
        a.stat-card:hover {
            border-color: var(--primary);
            box-shadow: var(--shadow);
            transform: translateY(-1px);
            transition: all 0.2s;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            margin-top: 4px;
            font-variant-numeric: tabular-nums;
            color: var(--ink);
        }

        /* --- Buttons --- */
        .actions-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .btn-primary, .btn-ghost, .btn-danger {
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
            border: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            box-shadow: var(--shadow-sm);
        }
        .btn-primary:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }

        .btn-ghost {
            background: var(--surface);
            color: var(--ink);
            border: 1px solid var(--stroke);
        }
        .btn-ghost:hover {
            background: var(--surface-soft);
            border-color: var(--muted-light);
        }

        .btn-danger {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }
        .btn-danger:hover {
            background: #fee2e2;
            border-color: #fca5a5;
        }
        
        form.inline-form {
            margin: 0;
            padding: 0;
            display: inline-block;
        }

        /* --- Panels --- */
        .panel {
            background: var(--surface);
            padding: 24px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--stroke);
            margin-bottom: 24px;
        }

        .panel h2, .panel h3 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--ink);
        }

        /* --- Chart Area (Full Width) --- */
        .chart-container {
            width: 100%;
            height: 400px; /* Made slightly taller for better readability */
            position: relative;
            margin-bottom: 24px;
        }

        .chart-controls {
            display: flex;
            gap: 16px;
            padding-top: 20px;
            border-top: 1px solid var(--stroke);
            flex-wrap: wrap;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
            min-width: 150px;
        }

        .field label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--muted);
        }

        input[type="text"], input[type="number"], select {
            width: 100%;
            height: 42px;
            padding: 0 12px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--stroke);
            font-family: inherit;
            font-size: 0.95rem;
            background: var(--surface);
            color: var(--ink);
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
        }

        input:hover, select:hover { border-color: var(--muted-light); }
        input:focus, select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--ring);
        }

        /* --- Settings Form Grid --- */
        .settings-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: flex-end;
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
        .alert.info { background: #eff6ff; color: #1e40af; border: 1px solid #bfdbfe; }

        @media (max-width: 1024px) {
            .hero { flex-direction: column; }
            .hero-stats { width: 100%; justify-content: flex-start; }
            .stat-card { flex: 1 1 calc(50% - 16px); max-width: none; }
        }
        
        @media (max-width: 600px) {
            .stat-card { flex: 1 1 100%; }
            .actions-group { flex-direction: column; width: 100%; }
            .actions-group > * { width: 100%; }
            .settings-grid { grid-template-columns: 1fr; }
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

        <?php if (!empty($db_error)): ?>
            <div class="alert error"><?= htmlspecialchars($db_error) ?></div>
        <?php elseif ($productArtikelDetails): ?>
            
            <?php if ($delete_message): ?>
                <div class="alert <?= $delete_message_type === 'success' ? 'success' : 'error' ?>">
                    <?= htmlspecialchars($delete_message) ?>
                </div>
            <?php endif; ?>

            <?php if ($update_message): ?>
                <div class="alert <?= $update_message_type === 'success' ? 'success' : ($update_message_type === 'info' ? 'info' : 'error') ?>">
                    <?= htmlspecialchars($update_message) ?>
                </div>
            <?php endif; ?>

            <div class="hero">
                <div class="hero-text">
                    <h1>
                        <img class="flag-icon" src="img/<?= htmlspecialchars($current_marketplace_code) ?>.png" alt="<?= htmlspecialchars($current_marketplace_code) ?>">
                        <a target="_blank" href="https://www.amazon.de/dp/<?= htmlspecialchars($selectedAsin ?: 'N/A') ?>">
                            <?= htmlspecialchars($selectedAsin ?: 'Keine ASIN') ?>
                        </a>
                    </h1>
                    <p class="subtitle"><?= htmlspecialchars($productArtikelDetails['artikelname']) ?> <br><span style="font-size: 0.9em; opacity: 0.8; font-family: monospace;">SKU: <?= htmlspecialchars($productArtikelDetails['sku'] ?? 'N/A') ?></span></p>
                    
                    <div class="actions-group">
                        <a href="addNew.php" class="btn-primary">+ Produkt hinzufügen</a>
                        <?php if (!empty($selectedAsin)): ?>
                            <a href="bestandsabweichungen_historie.php?asin=<?= urlencode($selectedAsin) ?>" class="btn-ghost">Bestandshistorie</a>
                            <form class="inline-form" action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" method="POST" onsubmit="return confirmDeleteItem();">
                                <input type="hidden" name="country" value="<?= htmlspecialchars($current_marketplace_code) ?>">
                                <input type="hidden" name="asin" value="<?= htmlspecialchars($selectedAsin) ?>">
                                <input type="hidden" name="delete_item" value="1">
                                <button type="submit" class="btn-danger">Produkt löschen</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="hero-stats">
                    <div class="stat-card">
                        <div class="stat-label">Eigener Preis</div>
                        <div class="stat-value" style="color: #2563eb;">
                            <?= ($productArtikelDetails['eigener_preis'] !== 'N/A' && $productArtikelDetails['eigener_preis'] !== null) ? number_format((float) $productArtikelDetails['eigener_preis'], 2, '.', '') . $currency_symbol : 'N/A' ?>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Niedrigster</div>
                        <div class="stat-value" style="color: #b91c1c;">
                            <?= ($productArtikelDetails['niedrigster_preis'] !== 'N/A' && $productArtikelDetails['niedrigster_preis'] !== null) ? number_format((float) $productArtikelDetails['niedrigster_preis'], 2, '.', '') . $currency_symbol : 'N/A' ?>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Buybox</div>
                        <div class="stat-value" style="color: #ea580c;">
                            <?= ($productArtikelDetails['bbox_preis'] !== 'N/A' && $productArtikelDetails['bbox_preis'] !== null) ? number_format((float) $productArtikelDetails['bbox_preis'], 2, '.', '') . $currency_symbol : 'N/A' ?>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Buybox Anteil</div>
                        <div class="stat-value" id="buyboxanteil">lädt...</div>
                    </div>
                    <a id="avgPriceReportLink" href="report.php?sku=<?= htmlspecialchars($productArtikelDetails['sku'] ?? '') ?>&time_period=7&source=amazon" target="_blank" class="stat-card">
                        <div class="stat-label">Ø VK Preis (7T) ↗</div>
                        <div class="stat-value">
                            <span class="price-value-inner">
                                <?= ($avg_sales_price_7d !== 'N/A') ? number_format($avg_sales_price_7d, 2, '.', '') . $currency_symbol : 'N/A' ?>
                            </span>
                        </div>
                    </a>
                </div>
            </div>

            <!-- Full Width Chart Panel -->
            <div class="panel">
                <h3>Preisentwicklung</h3>
                
                <div class="chart-container">
                    <?php if (!empty($priceHistory)): ?>
                        <canvas id="priceChart"></canvas>
                    <?php else: ?>
                        <div style="height: 100%; display: flex; align-items: center; justify-content: center; color: var(--muted); font-size: 1.05rem; border: 1px dashed var(--stroke); border-radius: var(--radius-sm);">
                            Keine Preisdaten für dieses Diagramm verfügbar.
                        </div>
                    <?php endif; ?>
                </div>

                <div class="chart-controls">
                    <div class="field">
                        <label for="timespan">Zeitraum</label>
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
                    <div class="field">
                        <label for="actionFilter">Aktionen filtern</label>
                        <select id="actionFilter" name="actionFilter" onchange="updateChart()">
                            <option value="all">Alle Aktionen</option>
                            <option value="update">Nur 'update'</option>
                            <option value="init_document">Nur 'init'/'document'</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Full Width Settings Panel -->
            <div class="panel">
                <h3>Preisgrenzen konfigurieren</h3>
                <form action="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>?asin=<?= urlencode($selectedAsin) ?>" method="POST">
                    <input type="hidden" name="country" value="<?= htmlspecialchars($current_marketplace_code) ?>">
                    <input type="hidden" name="asin" value="<?= htmlspecialchars($selectedAsin) ?>">
                    
                    <div class="settings-grid">
                        <div class="field">
                            <label for="min_preis">Min Preis (<?= $currency_symbol ?>)</label>
                            <input type="number" step="0.01" id="min_preis" name="min_preis" required value="<?= htmlspecialchars($form_min_preis) ?>">
                        </div>
                        
                        <div class="field">
                            <label for="max_preis">Max Preis (<?= $currency_symbol ?>)</label>
                            <input type="number" step="0.01" id="max_preis" name="max_preis" required value="<?= htmlspecialchars($form_max_preis) ?>">
                        </div>
                        
                        <div class="field">
                            <label for="stepsize_small">Step Klein (<10 <?= $currency_symbol ?>)</label>
                            <input type="number" step="0.01" id="stepsize_small" name="stepsize_small" required value="<?= htmlspecialchars($form_step_small) ?>">
                        </div>
                        
                        <div class="field">
                            <label for="stepsize_big">Step Groß (>=10 <?= $currency_symbol ?>)</label>
                            <input type="number" step="0.01" id="stepsize_big" name="stepsize_big" required value="<?= htmlspecialchars($form_step_big) ?>">
                        </div>
                        
                        <button type="submit" class="btn-primary" name="update_settings" style="height: 42px;">Einstellungen speichern</button>
                    </div>
                </form>
            </div>

            <script>
                const rawPriceData = <?php echo json_encode($priceHistory); ?>;
                const priceData = Array.isArray(rawPriceData) ? rawPriceData.map(item => ({
                    ...item,
                    ts: item.datum ? moment(item.datum).valueOf() : null
                })) : [];
                
                let currentChart;
                const chartMinPreisLine = <?= isset($productPreisgrenzen['min_preis']) && $productPreisgrenzen['min_preis'] !== null ? json_encode((float) $productPreisgrenzen['min_preis']) : 'null'; ?>;
                const chartMaxPreisLine = <?= isset($productPreisgrenzen['max_preis']) && $productPreisgrenzen['max_preis'] !== null ? json_encode((float) $productPreisgrenzen['max_preis']) : 'null'; ?>;
                const sku = '<?= htmlspecialchars($productArtikelDetails['sku'] ?? '') ?>';
                const asin = '<?= htmlspecialchars($selectedAsin) ?>';

                Chart.register(window['chartjs-plugin-annotation']);

                function fetchAndUpdateAvgPrice(timespan, sku) {
                    const avgPriceElement = document.querySelector('#avgPriceReportLink .price-value-inner');
                    const url = `get_avg_price.php?timespan=${timespan}&sku=${sku}&asin=${asin}`;

                    fetch(url)
                        .then(response => {
                            if (!response.ok) throw new Error('Network response was not ok');
                            return response.json();
                        })
                        .then(data => {
                            if (data.error) {
                                avgPriceElement.textContent = 'Fehler';
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
                        });
                }

                function createChart(dataToDisplay) {
                    let buyboxyes = 0;
                    let buyboxno = 0;

                    const ctx = document.getElementById('priceChart')?.getContext('2d');
                    if (!ctx) return;

                    if (currentChart) currentChart.destroy();
                    ctx.clearRect(0, 0, ctx.canvas.width, ctx.canvas.height);

                    if (!dataToDisplay || dataToDisplay.length === 0) {
                        document.querySelector('.chart-container').innerHTML = '<div style="height: 100%; display: flex; align-items: center; justify-content: center; color: var(--muted); font-size: 1.05rem; border: 1px dashed var(--stroke); border-radius: var(--radius-sm);">Keine Daten für ausgewählten Zeitraum/Filter verfügbar.</div>';
                        return;
                    } else {
                        if (!document.getElementById('priceChart')) {
                            document.querySelector('.chart-container').innerHTML = '<canvas id="priceChart"></canvas>';
                            updateChart(); 
                            return;
                        }
                    }

                    const backgroundAnnotations = [];

                    
                    if (dataToDisplay && dataToDisplay.length > 1 && dataToDisplay.length < 20000 ) {
                        let segmentStartIndex = 0;
                        let currentStatus = dataToDisplay[0].isWinner;
                        for (let i = 1; i < dataToDisplay.length; i++) {
                            if (dataToDisplay[i].isWinner === 'Ja') buyboxyes++;
                            else if (dataToDisplay[i].isWinner === 'Nein') buyboxno++;
                            
                            if (dataToDisplay[i].isWinner !== currentStatus || i === dataToDisplay.length - 1) {
                                const segmentEndIndex = i;
                                let bgColor;
                                const lowerCaseStatus = typeof currentStatus === 'string' ? currentStatus.toLowerCase() : String(currentStatus);

                                // Deutlich kräftigere Farben für Buybox Ja/Nein
                                if (lowerCaseStatus === 'ja') bgColor = 'rgba(34, 197, 94, 0.3)'; // Vibrant Green
                                else if (lowerCaseStatus === 'nein') bgColor = 'rgba(239, 68, 68, 0.3)'; // Vibrant Red
                                else { bgColor = 'rgba(211, 211, 211, 0.2)'; buyboxyes++; }

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
                        { 
                            label: 'Eigener Preis', data: eigenerPreisData, borderColor: '#2563eb', backgroundColor: 'rgba(37, 99, 235, 0.2)', fill: false, 
                            tension: 0, // <-- 0 = Gerade Linien (viel schneller als Kurven)
                            pointRadius: 1, // <-- 0 = Versteckt die kleinen Punkte (Hover geht trotzdem noch!)
                            pointHoverRadius: 5, spanGaps: true, borderWidth: 2
                        },
                        { 
                            label: 'Buy Box Preis', data: bboxPreisData, borderColor: '#ea580c', backgroundColor: 'rgba(234, 88, 12, 0.2)', fill: false, 
                            tension: 0, 
                            pointRadius: 1, 
                            pointHoverRadius: 5, spanGaps: true, borderWidth: 2
                        },
                        { 
                            label: 'Niedrigster Preis', data: niedrigsterPreisData, borderColor: '#b91c1c', backgroundColor: 'rgba(185, 28, 28, 0.2)', fill: false, 
                            tension: 0, 
                            pointRadius: 1, 
                            pointHoverRadius: 5, spanGaps: true, borderWidth: 2
                        }
                    ];

                    // Die min/max Linien bleiben fast gleich, aber auch hier pointRadius auf 0 prüfen
                    if (chartMinPreisLine !== null) {
                        datasets.push({ label: 'Min. Preis', data: dates.map(() => chartMinPreisLine), borderColor: 'rgba(100, 116, 139, 0.8)', borderWidth: 2, borderDash: [5, 5], fill: false, pointRadius: 0, hidden: true, spanGaps: true });
                    }
                    if (chartMaxPreisLine !== null) {
                        datasets.push({ label: 'Max. Preis', data: dates.map(() => chartMaxPreisLine), borderColor: 'rgba(100, 116, 139, 0.8)', borderWidth: 2, borderDash: [5, 5], fill: false, pointRadius: 0, hidden: true, spanGaps: true });
                    }

                    currentChart = new Chart(ctx, {
                        type: 'line',
                        data: { labels: dates, datasets: datasets },
                        options: {
                            animation: false,    // <-- NEU: Deaktiviert die Lade-Animation, was bei vielen Punkten extrem hilft
                            normalized: true,    // <-- NEU: Sagt Chart.js, dass die Daten chronologisch sortiert sind (Boost beim Rendern!)
                            responsive: true, maintainAspectRatio: false,
                            interaction: { mode: 'index', intersect: false },
                            plugins: {
                                tooltip: {
                                    backgroundColor: 'rgba(15, 23, 42, 0.9)',
                                    titleFont: { family: 'Space Grotesk', size: 13 },
                                    bodyFont: { family: 'Space Grotesk', size: 13 },
                                    padding: 12,
                                    callbacks: {
                                        label: function (context) {
                                            let label = context.dataset.label || '';
                                            if (label) label += ': ';
                                            if (context.parsed.y !== null) {
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
                                            
                                            footerLines.push('');
                                            footerLines.push(`Buybox: ${winnerStatus}`);
                                            if (dataPoint.action) footerLines.push(`Action: ${dataPoint.action}`);
                                            if (dataPoint.counter !== null && typeof dataPoint.counter !== 'undefined') footerLines.push(`Counter: ${dataPoint.counter}`);
                                            return footerLines;
                                        }
                                    }
                                },
                                legend: { labels: { usePointStyle: true, pointStyle: 'circle', font: { family: 'Space Grotesk', size: 12 } } },
                                annotation: { annotations: backgroundAnnotations }
                            },
                            scales: {
                                x: { id: 'x', type: 'time', time: { unit: 'day', tooltipFormat: 'DD.MM.YYYY HH:mm:ss' }, grid: { color: '#f1f5f9' }, ticks: { font: { family: 'Space Grotesk' } } },
                                y: { id: 'y', beginAtZero: false, grid: { color: '#f1f5f9', borderDash: [5, 5] }, ticks: { font: { family: 'Space Grotesk' } } }
                            }
                        }
                    });
                    
                    if(buyboxyes + buyboxno > 0) {
                        setBuyBoxAnteil((((buyboxyes / (buyboxyes + buyboxno)) * 100).toFixed(2)));
                    } else {
                        setBuyBoxAnteil(0);
                    }
                }

                function setBuyBoxAnteil(value) {
                    const element = document.getElementById('buyboxanteil');
                    value = Math.max(0, Math.min(100, parseFloat(value) || 0));
                    element.innerHTML = value.toFixed(2) + '%';
                    const red = Math.round(255 * (100 - value) / 100);
                    const green = Math.round(200 * value / 100);
                    element.style.color = `rgb(${red},${green},0)`;
                }

                function filterDataByTimespan(dataToFilter, timespan) {
                    if (!Array.isArray(dataToFilter)) return [];
                    if (timespan === 'all') return dataToFilter;
                    let cutoffMs;
                    switch (timespan) {
                        case '1': cutoffMs = moment().subtract(1, 'hours').valueOf(); break;
                        case '12': cutoffMs = moment().subtract(12, 'hours').valueOf(); break;
                        case '24': cutoffMs = moment().subtract(24, 'hours').valueOf(); break;
                        case '7': cutoffMs = moment().subtract(7, 'days').valueOf(); break;
                        case '30': cutoffMs = moment().subtract(30, 'days').valueOf(); break;
                        case '90': cutoffMs = moment().subtract(90, 'days').valueOf(); break;
                        case '365': cutoffMs = moment().subtract(365, 'days').valueOf(); break;
                        default: return dataToFilter;
                    }
                    return dataToFilter.filter(item => item.ts !== null && item.ts >= cutoffMs);
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
                    
                    if (reportLink) {
                        let reportDays = '7';
                        switch (timespanValue) {
                            case '1': case '12': case '24': reportDays = '1'; break;
                            case '7': case '30': case '90': case '365': reportDays = timespanValue; break;
                            case 'all': reportDays = '3650'; break;
                        }
                        try {
                            const currentUrl = new URL(reportLink.href);
                            currentUrl.searchParams.set('time_period', reportDays);
                            reportLink.href = currentUrl.toString();
                        } catch (e) {
                            console.error("Konnte URL nicht aktualisieren", e);
                        }
                    }
                    
                    if (!timespanValue || !actionFilterValue || !Array.isArray(priceData)) {
                        if (document.getElementById('priceChart')) {
                            if (currentChart) currentChart.destroy();
                            document.querySelector('.chart-container').innerHTML = '<div style="height: 100%; display: flex; align-items: center; justify-content: center; color: var(--muted); font-size: 1.05rem; border: 1px dashed var(--stroke); border-radius: var(--radius-sm);">Keine Daten für Diagramm verfügbar oder Filter nicht gesetzt.</div>';
                        }
                        return;
                    }
                    const timeFilteredData = filterDataByTimespan(priceData, timespanValue);
                    const finalFilteredData = filterDataByAction(timeFilteredData, actionFilterValue);
                    createChart(finalFilteredData);
                }

                document.addEventListener('DOMContentLoaded', () => {
                    if (document.getElementById('priceChart') && priceData && priceData.length > 0) {
                        updateChart();
                    }
                });
            </script>

        <?php elseif (!empty($_GET['asin']) && !$db_error): ?>
            <div class="alert error">Keine Produktdetails für die ASIN "<?= htmlspecialchars($selectedAsin) ?>" in Land <?= htmlspecialchars($current_marketplace_code) ?> gefunden oder konfiguriert.</div>
        <?php endif; ?>

    </div>

    <script>
        function confirmDeleteItem() {
            if (!confirm('Dieses Produkt wird aus der Preissteuerung entfernt und die Historie wird gelöscht. Fortfahren?')) {
                return false;
            }
            return confirm('Wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden.');
        }
    </script>
</body>
</html>