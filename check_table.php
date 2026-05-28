<?php
require_once __DIR__ . '/src/Http/../config/db_connection.php';
try {
    $stmt = $dbConnectionTric4Calc->query("SHOW CREATE TABLE Buybox_DE");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo $row['Create Table'] . "\n";
    
    // test performance of latest record query
    $start = microtime(true);
    $stmt = $dbConnectionTric4Calc->query("
        SELECT bb.produktid, bb.isWinner 
        FROM Buybox_DE bb 
        INNER JOIN (
            SELECT produktid, MAX(id) as max_id 
            FROM Buybox_DE 
            GROUP BY produktid
        ) latest ON bb.id = latest.max_id
    ");
    $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $end = microtime(true);
    echo "Query took " . ($end - $start) . " seconds\n";
    echo "Found " . count($res) . " latest records.\n";
} catch (Exception $e) {
    echo $e->getMessage();
}
