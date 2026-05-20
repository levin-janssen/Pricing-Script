<?php
require_once APP_ROOT . '/config/db_connection.php';
require_once APP_ROOT . '/src/Services/AmazonFeedBuilder.php';
require_once APP_ROOT . '/src/Services/sp_api_functions.php';

$preis = 198.99;
$marketplaceId = 'A1PA6795UKMFR9'; // Germany
$sku = "8-21-41-1";


$stmt_bestand = $dbConnectionTric->prepare("
    SELECT SUM(menge) AS menge FROM `lager`
    WHERE vk_ID = (
    SELECT produktid
        FROM `produkte_felder_werte`
        WHERE feldid = 44
        AND wert1 = :sku
        LIMIT 1
    )
    GROUP BY vk_ID
    ORDER BY menge DESC
");
$stmt_bestand->execute([':sku' => $sku]);
$result_bestand = $stmt_bestand->fetch(PDO::FETCH_COLUMN); 

//In Schleife
$builder = new AmazonFeedBuilder("A6F5BRV91OMPP", "2.0","de_DE");
$builder->addBusinessPrice($sku, "EUR", $marketplaceId, $preis);
$builder->addHandlingTime($sku, "0", $result_bestand);
$feedContent = $builder->build();


//Nach Schleife
$doc = createFeedDocument();
$docId = $doc["feedDocumentId"];
$uploadUrl = $doc["url"];
uploadFeedDocument($uploadUrl, $feedContent);
$feed = createFeed($docId, $marketplaceId);


$feedId = $feed["feedId"];
echo json_encode(["feedId" => $feedId]);
