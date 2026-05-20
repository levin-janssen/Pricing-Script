<?php
header('Content-Type: application/json; charset=utf-8');

require 'sp_api_functions.php';

header('Content-Type: application/json');


if (!isset($_GET['feedId'])) {
    echo json_encode(["error" => "feedId missing"]);
    exit;
}

$feedId = $_GET['feedId'];

$status = getFeed($feedId);


echo json_encode($status);