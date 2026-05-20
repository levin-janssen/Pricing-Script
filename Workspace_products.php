<?php
ini_set('display_errors', 1); // For debugging, disable in production
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
ini_set('default_charset', 'UTF-8');

require_once 'db_connection.php'; 
$dbConnection = $dbConnectionTric4Calc;

// --- Get ASIN from query parameter ---
$asin = filter_input(INPUT_GET, 'asin', FILTER_SANITIZE_STRING);
$products = []; // Initialize empty array

if ($asin && preg_match('/^[A-Z0-9]{10}$/', $asin)) { // Validate ASIN format
    try {
        // Select relevant fields from Artikel table based on ASIN
        // IMPORTANT: Adjust schema/table names if they differ! Assuming 'ID', 'artikelname', 'sku', 'asin' exist in 'Artikel' table.
        $stmt = $dbConnection->prepare(
            "SELECT ID, artikelname, sku FROM Artikel WHERE asin = :asin"
        );
        $stmt->bindParam(':asin', $asin, PDO::PARAM_STR);
        $stmt->execute();
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (\PDOException $e) {
        // Don't expose detailed DB errors to the client in production
        error_log("Database error in fetch_products.php: " . $e->getMessage()); // Log error server-side
        http_response_code(500);
        echo json_encode(['error' => 'Error querying database']);
        exit;
    }
} else {
    // If ASIN is missing or invalid, return an empty array (or an error message if preferred)
    // Returning empty array is handled gracefully by the JS
}

// --- Output results as JSON ---
header('Content-Type: application/json');
echo json_encode($products);

?>