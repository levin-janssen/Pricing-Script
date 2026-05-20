<?php
header('Content-Type: application/json; charset=utf-8');

require_once APP_ROOT . '/src/Services/sp_api_functions.php';

if (!isset($_GET['feedId'])) {
    echo json_encode(["error" => "feedId missing"]);
    exit;
}

$feedId = $_GET['feedId'];

$status = getFeed($feedId);


echo json_encode($status);
