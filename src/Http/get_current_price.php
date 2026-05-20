<?php
ini_set('display_errors', 0); // Don't display errors in AJAX response
ini_set('log_errors', 1);
error_reporting(E_ALL);
ini_set('default_charset', 'UTF-8');

header('Content-Type: application/json; charset=UTF-8');

// Include the SP-API functions
include_once APP_ROOT . '/src/Services/sp_api_functions.php'; // Make sure the path is correct
require_once APP_ROOT . '/config/db_connection.php'; 
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
    }
}

echo json_encode($output, JSON_UNESCAPED_UNICODE);
exit;

?>
