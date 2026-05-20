<?php
// get_preisgrenzen.php
require_once APP_ROOT . '/config/db_connection.php'; // Adjust path
$dbConnection = $dbConnectionTric4Calc;
header('Content-Type: application/json');

$asin = filter_input(INPUT_GET, 'asin', FILTER_SANITIZE_STRING);
$land = filter_input(INPUT_GET, 'land', FILTER_SANITIZE_STRING);
$output = ['error' => 'Keine Daten'];

if ($asin && $land) {
    try {
        $stmt = $dbConnection->prepare("SELECT min_preis, max_preis, stepsize_small, stepsize_big FROM Preisgrenzen WHERE ASIN = :asin AND Land = :land LIMIT 1");
        $stmt->bindParam(':asin', $asin);
        $stmt->bindParam(':land', $land);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($result) {
            $output = $result;
        } else {
            $output = ['message' => 'Keine spezifischen Preisgrenzen für diese ASIN/Land Kombination gefunden.'];
        }
    } catch (PDOException $e) {
        error_log("Error in get_preisgrenzen.php: " . $e->getMessage());
        $output = ['error' => 'Datenbankfehler.'];
    }
} else {
    $output = ['error' => 'ASIN oder Land fehlt.'];
}
echo json_encode($output);
?>
