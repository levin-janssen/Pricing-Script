<?php
// get_amazon_product_details.php
ini_set('display_errors', 0); // Keine Fehler direkt ausgeben
error_reporting(E_ALL);
ini_set('default_charset', 'UTF-8');

// --- Pfad anpassen ---
require_once 'sp_api_functions.php'; // Enthält alle get*ByASIN Funktionen und getAccessToken

header('Content-Type: application/json'); // JSON Antwort

$response = [
    'skus' => null,
    'ean' => null,
    'name' => null,
    'error' => null,
    'warnings' => [] // Für nicht-kritische Fehler wie "EAN nicht gefunden"
];

$asin = filter_input(INPUT_GET, 'asin', FILTER_SANITIZE_STRING);

if (empty($asin) || !preg_match('/^[A-Z0-9]{10}$/', $asin)) {
    $response['error'] = 'Ungültige ASIN übergeben.';
    echo json_encode($response);
    exit;
}

try {
    // 1. SKUs abrufen (potenziell kritisch, wenn keine gefunden werden)
    $skus = getSkusByASIN($asin); // Annahme: Nutzt Pricing API oder wurde angepasst
    if ($skus === null) {
        // Fehler beim API-Aufruf selbst
        $response['error'] = 'Fehler beim Abrufen der SKUs von Amazon. API nicht erreichbar oder interner Fehler.';
        // Log ist bereits in getSkusByASIN erfolgt
    } elseif (empty($skus)) {
        // Keine SKUs gefunden - das ist oft ein Blocker
        $response['error'] = 'Keine aktiven SKUs für diese ASIN auf Ihrem Amazon-Konto gefunden. Produkt kann nicht angelegt werden.';
    } else {
        $response['skus'] = $skus;
    }

    // Nur fortfahren, wenn keine kritischen Fehler aufgetreten sind
    if ($response['error'] === null) {
        // 2. EAN abrufen (weniger kritisch)
        $ean = getEANByASIN($asin); // Nutzt Catalog API
        if ($ean === null) {
            $response['warnings'][] = 'EAN konnte nicht automatisch von Amazon abgerufen werden.';
            // Log ist bereits in getEANByASIN erfolgt
        } else {
            $response['ean'] = $ean;
        }

        // 3. Namen abrufen (weniger kritisch)
        $name = getNameByASIN($asin); // Nutzt Catalog API
        if ($name === null) {
            $response['warnings'][] = 'Produktname konnte nicht automatisch von Amazon abgerufen werden.';
            // Log ist bereits in getNameByASIN erfolgt
        } else {
            $response['name'] = $name;
        }
    }

} catch (Exception $e) {
    error_log("Allgemeiner Fehler in get_amazon_product_details.php für ASIN $asin: " . $e->getMessage());
    // Setze einen allgemeinen Fehler, falls noch keiner gesetzt wurde
    if ($response['error'] === null) {
         $response['error'] = 'Ein interner Serverfehler ist beim Abrufen der Produktdetails aufgetreten.';
    }
}

echo json_encode($response);
?>