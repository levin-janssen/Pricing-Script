<?php
require_once __DIR__ . '/src/Http/../config/db_connection.php';
try {
    $stmt = $dbConnectionTric->query("SHOW TABLES LIKE '%produkt%'");
    $res = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($res);
} catch (Exception $e) {
    echo $e->getMessage();
}
