<?php
header('Content-Type: application/json');
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db_connection.php';
require_once 'marketplaces.php';


$asin = filter_input(INPUT_GET, 'asin', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$timespan = filter_input(INPUT_GET, 'timespan', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$sku = filter_input(INPUT_GET, 'sku', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

if (empty($asin) || empty($timespan) || empty($sku)) {
    echo json_encode(['error' => 'Invalid parameters.']);
    exit;
}

$interval_unit = 'DAY';
if (in_array($timespan, ['1', '12', '24'])) {
    $interval_unit = 'HOUR';
} elseif ($timespan === 'all') {
    // No specific time interval needed for 'all', so we can skip the DATE_SUB
}

$avg_sales_price = 'N/A';

try {
    $stmt_product_id = $dbConnectionTric->prepare("
        SELECT produktid FROM produkte_felder_werte
        WHERE feldid = '44' AND wert1 = :sku LIMIT 1
    ");
    $stmt_product_id->execute([':sku' => $sku]);
    $product_id = $stmt_product_id->fetchColumn();

    if ($product_id) {
        $sql = "
            SELECT SUM(T1.einzelpreis * T1.anzahl) AS total_revenue_pre_vat,
                   SUM(T1.anzahl) AS total_quantity
            FROM bestellungen_positionen AS T1
            JOIN bestellungen AS T2 ON T2.id = T1.bestellungsid
            WHERE T1.produktid = :product_id
            AND T2.werbekennzeichen IN (2,8) -- 2 is assumed to be Amazon
        ";

        if ($timespan !== 'all') {
            $sql .= " AND T1.datum > DATE_SUB(NOW(), INTERVAL :timespan {$interval_unit})";
        }

        $stmtAvgPrice = $dbConnectionTric->prepare($sql);

        // Bind parameters conditionally
        if ($timespan !== 'all') {
            $stmtAvgPrice->bindParam(':timespan', $timespan, PDO::PARAM_INT);
        }
        $stmtAvgPrice->bindParam(':product_id', $product_id, PDO::PARAM_INT);
        $stmtAvgPrice->execute();
        $salesSummary = $stmtAvgPrice->fetch(PDO::FETCH_ASSOC);

        if ($salesSummary && !empty($salesSummary['total_quantity']) && $salesSummary['total_quantity'] > 0) {
            $revenue_with_vat = (float) $salesSummary['total_revenue_pre_vat'] * 1.19;
            $avg_sales_price = $revenue_with_vat / (int) $salesSummary['total_quantity'];
        }
    }
} catch (\PDOException $e) {
    error_log("Error fetching average price for SKU {$sku}: " . $e->getMessage());
    echo json_encode(['error' => 'Database error.']);
    exit;
}

echo json_encode(['avg_price' => $avg_sales_price]);
?>
