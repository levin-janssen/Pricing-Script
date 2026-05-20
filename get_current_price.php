<?php
ini_set('display_errors', 0); // Don't display errors in AJAX response
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('default_charset', 'UTF-8');

header('Content-Type: application/json; charset=UTF-8');

// Include the SP-API functions
include 'sp_api_functions.php'; // Make sure the path is correct
require_once 'db_connection.php'; 
$dbConnection = $dbConnectionTric4Calc;
$asin = filter_input(INPUT_GET, 'asin', FILTER_SANITIZE_STRING);
$output = ['currentPrice' => null, 'error' => null];

if (!$asin || !preg_match('/^[A-Z0-9]{10}$/', $asin)) {
    $output['error'] = 'Invalid or missing ASIN.';
    echo json_encode($output);
    exit;
}

$apiData = callItemsAPI($asin);
$buyBoxPrice = getInfoByASIN($apiData, "buyboxpreis");

if ($buyBoxPrice !== null) {
    $output['currentPrice'] = (float) $buyBoxPrice; // Use BuyBox price
    $output['priceType'] = 'buybox'; // Indicate which price was found
} else {
    // Fallback to get own price if BuyBox price fails
    $currentPrice = getOwnPriceByASIN($asin);
    if ($currentPrice !== null) {
        $output['currentPrice'] = $currentPrice;
        $output['priceType'] = 'eigenerPreis'; // Indicate which price was found
    } else {
        $output['error'] = 'Could not retrieve current price for ASIN.';
        // Optional: Set HTTP status code
        // http_response_code(404); // Not Found or 500 Internal Server Error
    }
}

// // --- Fetch SKU ---
// $sku = null;
// try {
//     $stmt = $dbConnection->prepare("SELECT sku FROM Artikel WHERE ASIN = :asin");
//     $stmt->bindParam(':asin', $asin, PDO::PARAM_STR);
//     $stmt->execute();
//     $result = $stmt->fetch(PDO::FETCH_ASSOC);
//     if ($result && isset($result['sku'])) {
//         $sku = $result['sku'];
//     } else {
//         error_log("SKU nicht gefunden für ASIN: " . $asin);
//     }
// } catch (\PDOException $e) {
//     error_log("Datenbankfehler beim Abrufen der SKU für ASIN $asin: " . $e->getMessage());
// }

// // --- Calculate suggested max price ---
// if ($sku) {
//     // Assuming getFeaturedOfferExpectedPriceBySKU is defined in sp_api_functions.php
//     $suggestedMaxPrice = getFeaturedOfferExpectedPriceBySKU($sku);
//     if ($suggestedMaxPrice !== null) {
//         $output['suggestedMaxPrice'] = (float) $suggestedMaxPrice;
//     } else {
//         error_log("getFeaturedOfferExpectedPriceBySKU returned null for SKU: " . $sku);
//     }
// } else {
//     error_log("Kann vorgeschlagenen Maximalpreis nicht berechnen, da die SKU für ASIN $asin fehlt.");
// }

echo json_encode($output, JSON_UNESCAPED_UNICODE);
exit;

?>