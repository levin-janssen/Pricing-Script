<?php
$scriptStartTime = microtime(true);

ini_set('default_charset',  'UTF-8');
ini_set('error_log', dirname(__DIR__, 2) . '/error.log'); 
$logFile = dirname(__DIR__, 2) . '/error.log';
$maxLines = 100000; // Maximale Zeilenanzahl, die behalten werden soll

if (file_exists($logFile)) {
    if (filesize($logFile) > 5 * 1024 * 1024) { 
        $tempFile = $logFile . '.tmp';
        exec(sprintf('tail -n %d %s > %s', $maxLines, escapeshellarg($logFile), escapeshellarg($tempFile)));
        rename($tempFile, $logFile);
        
        chmod($logFile, 0666); 
    }
}

require_once __DIR__ . '/../Support/Logger.php';
require_once __DIR__ . '/../Services/sp_api_functions.php';
require_once __DIR__ . '/../Services/AmazonFeedBuilder.php';
require_once __DIR__ . '/../Services/ManoManoFeedBuilder.php';
require_once dirname(__DIR__, 2) . '/config/marketplaces.php';
require_once dirname(__DIR__, 2) . '/config/db_connection.php'; // <-- NEU

$currentRunId = substr(md5(microtime()), 0, 6);
Logger::setRunId($currentRunId);

Logger::info("--- STARTING NEW SCRIPT RUN ---");

$logRetentionDays = 30;
$logDir = dirname(__DIR__, 2) . '/logs';
$cleanupMarker = $logDir . '/.cleanup.last';
$cleanupIntervalSeconds = 6 * 60 * 60; // Avoid re-scanning logs every run.

if (is_dir($logDir)) {
    $runCleanup = true;
    if (is_file($cleanupMarker)) {
        $lastRun = filemtime($cleanupMarker);
        if ($lastRun !== false && (time() - $lastRun) < $cleanupIntervalSeconds) {
            $runCleanup = false;
        }
    }

    if ($runCleanup) {
        $cutoff = time() - ($logRetentionDays * 86400);
        foreach (glob($logDir . '/app_*.log') as $file) {
            if (filemtime($file) < $cutoff) @unlink($file);
        }
        foreach (glob($logDir . '/performance_*.log') as $file) {
            if (filemtime($file) < $cutoff) @unlink($file);
        }
        @file_put_contents($cleanupMarker, (string)time());
    }
}

$dbStartTime = microtime(true);
// Nutze die global definierte Verbindung aus der db_connection.php
$dbConnection = $dbConnectionTric4Calc;

Logger::performance("Database Connections Established", microtime(true) - $dbStartTime);


$AmazonBuilder = new AmazonFeedBuilder("A6F5BRV91OMPP", "2.0", "de_DE");
$ManoManobuilderDE = new ManoManoFeedBuilder("cxkiqBhdGZUBLpWPOyyPDMPs67iZvMJp", 7877481);
$ManoManobuilderFR = new ManoManoFeedBuilder("Hj8MyH9mXKy2Xv7ITTqeqrNkRbxro2Nm", 7877481);

foreach ($marketplaces as $key => $value) {
    // START MARKETPLACE-TIMER
    $mpStartTime = microtime(true);
    
    $marketplaceId = $value['marketplaceId'];
    $dbName = $value['dbName'];
    $currencyCode = $value['currencyCode'];
    $countrycode = $key;

    echo "<h3>---  Marketplace: $key ---</h3>";
    Logger::info("Start processing Marketplace", ['marketplace' => $key, 'currency' => $currencyCode]);

    $statement = $dbConnection->prepare("SELECT DISTINCT ASIN FROM tric4calc.Preisgrenzen WHERE min_preis IS NOT NULL AND min_preis != '' AND Land = '$countrycode'");
    $statement->execute();
    $asins = $statement->fetchAll(PDO::FETCH_ASSOC);
    Logger::info("Found ASINs to process", ['marketplace' => $key, 'count' => count($asins)]);

    // --- NEU: Bulk-Preload für Tricoma Bestände ---
    $preloadStartTime = microtime(true);
    $asinArray = array_column($asins, 'ASIN'); 
    $tricomaBulkStock = preloadTricomaStocks($asinArray);
    Logger::performance("DB: Tricoma Bulk Stock Preload", microtime(true) - $preloadStartTime, ['count' => count($asinArray)]);
    // ----------------------------------------------

    foreach ($asins as $keyArray => $asinData) {
        $asinStartTime = microtime(true);
        $asin = $asinData["ASIN"];
        
        $statement = $dbConnection->prepare("SELECT sku FROM tric4calc.Artikel WHERE ASIN = '$asin'");
        $statement->execute();
        $result = $statement->fetch(PDO::FETCH_ASSOC);
        $sku = $result["sku"];
        
        $isFBA = (substr($sku, -strlen('_FBA')) === '_FBA'); // Prüft, ob die SKU mit "_FBA" endet

        // --- NEW: FETCH ALL API DATA CONCURRENTLY ---
        $apiStartTime = microtime(true);
        $concurrentData = fetchAllAsinDataConcurrent($asin, $sku, $marketplaceId);
        Logger::performance("API: Concurrent Data Fetch", microtime(true) - $apiStartTime, ['asin' => $asin, 'sku' => $sku]);

        // 1. Dynamische FBA-Prüfung
        // Prüft zuerst den Suffix, als Fallback prüfen wir, was Amazon uns als Channel mitteilt
        $isFBA_by_name = (substr($sku, -strlen('_FBA')) === '_FBA');
        
        $channelCode = $concurrentData['quantity']['data']['fulfillmentAvailability'][0]['fulfillmentChannelCode'] ?? 'DEFAULT';
        $isFBA_by_api = (strpos($channelCode, 'AMAZON') !== false); // z.B. 'AMAZON_EU'

        $isFBA = ($isFBA_by_name || $isFBA_by_api);

        if ($isFBA) {
            Logger::info("FBA-Artikel erkannt: Bestand wird nicht übermittelt, nur Preisupdate.", [
                'asin' => $asin, 
                'sku' => $sku, 
                'channel' => $channelCode
            ]);
            echo "FBA-Artikel: Überspringe Bestandsupdate für ASIN $asin.<br>\r\n";
        } else {
            // --- FBM-ARTIKEL: BESTANDSAKTUALISIERUNG ---
            
            // Extract Quantity from the concurrent response
            $amazonQuantity = null;
            $quantityResponse = $concurrentData['quantity']['data'] ?? null;
            $quantityHttpCode = $concurrentData['quantity']['http_code'] ?? 0;
            
            // NEW: Pull the raw cURL error string we captured in the concurrent function
            $quantityCurlError = $concurrentData['quantity']['error'] ?? '';

            if (isset($quantityResponse['fulfillmentAvailability'][0]['quantity'])) {
                $amazonQuantity = $quantityResponse['fulfillmentAvailability'][0]['quantity'];
            }
            
            if ($amazonQuantity === null) {
                // Determine the exact cause for better debugging
                if ($quantityHttpCode === 0) {
                     Logger::warning("Verbindungsabbruch/Timeout zu Amazon (HTTP 0). Überspringe ASIN.", [
                         'asin' => $asin, 
                         'sku' => $sku, 
                         'curl_error' => $quantityCurlError // This will tell you exactly what failed (e.g., "SSL timeout")
                     ]);
                     continue; // Safely skip to the next ASIN instead of crashing
                } elseif ($quantityHttpCode === 404) {
                     Logger::warning("SKU auf Amazon nicht gefunden (HTTP 404).", ['asin' => $asin, 'sku' => $sku]);
                     continue;
                } elseif (isset($quantityResponse['errors'])) {
                     $errMsg = $quantityResponse['errors'][0]['message'] ?? 'Unbekannter API Fehler';
                     Logger::error("API Fehler beim Abrufen des Bestands: " . $errMsg, ['asin' => $asin, 'sku' => $sku]);
                     continue;
                } else {
                     Logger::error("Fehler beim Abrufen des Amazon-Bestands (Concurrent Quantity). Unerwartete Antwort.", [
                         'asin' => $asin, 
                         'sku' => $sku, 
                         'http_code' => $quantityHttpCode,
                         'response' => $quantityResponse
                     ]);
                     continue;
                }
            }
            
            // Echten und Rohen Bestand aus dem vorab geladenen Cache (Array) abfragen
            $tricomaQuantity = $tricomaBulkStock[$asin]['real'] ?? 0;
            $tricomaPure = $tricomaBulkStock[$asin]['pure'] ?? 0;

            // Bestände abgleichen und ggf. warnen
            if ($amazonQuantity !== $tricomaQuantity) {
                Logger::warning("Bestandsabweichung festgestellt! Amazon-Bestand unterscheidet sich vom Tricoma-Lager.", [
                    'sku' => $sku, 
                    'asin' => $asin, 
                    'amazon_bisher' => $amazonQuantity, 
                    'tricoma_neu' => $tricomaQuantity,
                    'tricoma_pure' => $tricomaPure
                ]);
                echo "Achtung: Bestandsabweichung für SKU $sku (Amazon: $amazonQuantity | Tricoma Netto: $tricomaQuantity | Tricoma Roh: $tricomaPure)<br>\r\n";
            } else {
                Logger::info("Bestand ist synchron", ['sku' => $sku, 'asin' => $asin, 'quantity' => $tricomaQuantity, 'tricoma_pure' => $tricomaPure]);
            }

            // Tricoma-Menge in den Amazon-Feed schreiben
            $AmazonBuilder->addHandlingTime($sku, "0", $tricomaQuantity);
            Logger::info("Tricoma-Lagerbestand an Amazon übermittelt", ['sku' => $sku, 'quantity' => $tricomaQuantity]);
            
            if ($tricomaQuantity === 0) {
                Logger::warning("Achtung: Tricoma-Lagerbestand ist 0!", ['sku' => $sku, 'asin' => $asin]);
            }
        }

        // 2. Preisberechnung (Passing concurrent data into the function)
        $preis = processAsin($asin, $concurrentData);

        // 3. Preis-Aktualisierung (wird für FBA und FBM ausgeführt)
        if($preis == null){
            echo "Kein neuer Preis für ASIN $asin gesetzt <br>\r\n";
            Logger::warning("Kein neuer Preis gesetzt", ['asin' => $asin, 'sku' => $sku]);
            Logger::performance("ASIN Processed (No Price Update)", microtime(true) - $asinStartTime, ['asin' => $asin, 'sku' => $sku]);
            continue; // Bricht den restlichen Durchlauf ab, da kein Preis da ist
        } 
        
        // Preis-Update API-Call mit Zeitmessung
        $updatePriceStartTime = microtime(true);
        $updateSuccess = updateAmazonProductPrice($sku, $preis, "PRODUCT", $marketplaceId, $currencyCode);
        Logger::performance("API: updateAmazonProductPrice", microtime(true) - $updatePriceStartTime, ['asin' => $asin, 'sku' => $sku, 'preis' => $preis]);

        if($updateSuccess){
            echo "Preis für SKU $sku wurde erfolgreich auf $preis gesetzt.<br>\r\n";
            Logger::info("Preis erfolgreich gesetzt", ['sku' => $sku, 'asin' => $asin, 'preis' => $preis, 'marketplaceId' => $marketplaceId]);
            $AmazonBuilder->addBusinessPrice($sku,  "EUR", $marketplaceId, ((float)($preis-0.01)));
            
            if($marketplaceId == "A1PA6795UKMFR9"){
                $ManoManobuilderDE->addOffer($sku, ($preis));
            } elseif($marketplaceId == "A13V1IB3VIYZZH"){
                $ManoManobuilderFR->addOffer($sku, ($preis));
            }
        } else{
            echo "Fehler beim Setzen des Preises für SKU $sku.<br>\r\n";
            Logger::error("Fehler beim Setzen des Preises (updateAmazonProductPrice)", ['sku' => $sku, 'preis' => $preis, 'marketplaceId' => $marketplaceId]);
        }        
        // END ASIN-TIMER
        Logger::performance("ASIN Processed (Complete)", microtime(true) - $asinStartTime, ['asin' => $asin, 'sku' => $sku]);
        
        // --- NEW: RATE LIMIT THROTTLE ---
        // Pause the script for 2 seconds to respect Amazon's leaky bucket rate limits
        sleep(2);
    } // <-- This is the closing bracket of the foreach loop

    // END MARKETPLACE-TIMER
    Logger::performance("Marketplace Processed ($key)", microtime(true) - $mpStartTime, ['marketplace' => $key]);
}

// START FEED-TIMER
$feedStartTime = microtime(true);

$feedContent = $AmazonBuilder->build();
Logger::info("Amazon Feed built", ['length' => strlen($feedContent)]);
echo "<br>\r\nFeed Content:<br>\r\n<pre>" . htmlspecialchars($feedContent) . "</pre><br>\r\n";
$doc = createFeedDocument();
if (isset($doc["feedDocumentId"])) {
    $docId = $doc["feedDocumentId"];
    $uploadUrl = $doc["url"];
    uploadFeedDocument($uploadUrl, $feedContent);
    $allMarketplaceIds = array_column($marketplaces, 'marketplaceId');
    $feed = createFeed($docId, $allMarketplaceIds);
    $feedId = $feed["feedId"];
    echo json_encode(["feedId" => $feedId]);
    Logger::info("Amazon Feed submitted", ['feedId' => $feedId, 'docId' => $docId]);
} else {
    Logger::error("Failed to create Amazon feed document", ['doc_response' => $doc]);
}

$response = $ManoManobuilderDE->send();
print_r($response);
Logger::info("ManoMano DE Feed sent", ['response' => $response]);

$response = $ManoManobuilderFR->send();
print_r($response);
Logger::info("ManoMano FR Feed sent", ['response' => $response]);

Logger::info("Done processing Marketplace", ['marketplace' => $key, 'currency' => $currencyCode]);

// END FEED-TIMER
Logger::performance("Feed Generation & Submission", microtime(true) - $feedStartTime);

// END GESAMT-TIMER
Logger::performance("Total Script Execution Time", microtime(true) - $scriptStartTime);


// --- FUNCTIONS REMAIN THE SAME BELOW THIS LINE ---

function processAsin($asin, $concurrentData) {
    global $dbConnection;
    global $marketplaceId;
    global $dbName;
    global $currencyCode;
    global $countrycode;

    $abstand_unten = 0.05; 
    $step_size = 0.05;
    $action = "update";

    $produktDataStmt = $dbConnection->prepare("SELECT ID, SKU FROM tric4calc.Artikel WHERE asin = :ASIN LIMIT 1");
    $produktDataStmt->execute([':ASIN' => $asin]);
    $produktData = $produktDataStmt->fetch(PDO::FETCH_ASSOC);

    $produktDataStmtGrenzen = $dbConnection->prepare("SELECT min_preis, max_preis, stepsize_small, stepsize_big FROM tric4calc.Preisgrenzen WHERE ASIN = :ASIN AND Land = '$countrycode' LIMIT 1");
    $produktDataStmtGrenzen->execute([':ASIN' => $asin]);
    $produktDataGrenzen = $produktDataStmtGrenzen->fetch(PDO::FETCH_ASSOC);

    if (!$produktData) {
        Logger::error("Produktdaten nicht gefunden", ['asin' => $asin]);
        return null;
    }
    
    $produktid = $produktData['ID'];
    $sku = $produktData['SKU'];
    $min_preis = filter_var($produktDataGrenzen['min_preis'], FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE) ?? 0.01;
    $max_preis = filter_var($produktDataGrenzen['max_preis'], FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE) ?? 1000000.00;
    $stepsize_small = filter_var($produktDataGrenzen['stepsize_small'], FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE) ?? 0.01;
    $stepsize_big = filter_var($produktDataGrenzen['stepsize_big'], FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE) ?? 0.1;
    if($max_preis == 0){
        $max_preis = 1000000.00;
    }

    // LOG: Preisgrenzen aus der Datenbank
    Logger::info("Preisgrenzen geladen", [
        'asin' => $asin, 
        'sku' => $sku, 
        'min_preis' => $min_preis, 
        'max_preis' => $max_preis,
        'step_small' => $stepsize_small,
        'step_big' => $stepsize_big
    ]);

    $prevStateStmt = $dbConnection->prepare("SELECT eigenerPreis, niedrigsterPreis, buyboxpreis, action, counter FROM tric4calc.$dbName WHERE produktid = :produktid ORDER BY datum DESC LIMIT 1");
    $prevStateStmt->execute([':produktid' => $produktid]);
    $previousState = $prevStateStmt->fetch(PDO::FETCH_ASSOC);
    $counter = $previousState['counter'] ?? 0;

    // --- REPLACED SEQUENTIAL API CALLS WITH CONCURRENT DATA ---
    $data = $concurrentData['items_api']['data'] ?? null;
    
    // NEW: Check if data is completely missing OR if Amazon returned an error (like QuotaExceeded)
    if (!$data || isset($data['errors'])) {
        $errorMessage = $data['errors'][0]['message'] ?? 'Unbekannter API Fehler (z.B. Rate Limit)';
        Logger::warning("API Request übersprungen: " . $errorMessage, ['asin' => $asin, 'sku' => $sku]);
        return null; // Skip this ASIN to avoid writing NULL to the database
    }

    $buyboxpreis_raw = getInfoByASIN($data, "buyboxpreis");
    $niedrigsterPreis_raw = getLowestPrice($data);
    
    $eigenerPreis_raw = null;
    if (!empty($concurrentData['own_price']['data']["payload"][0]["Product"]["Offers"][0]["BuyingPrice"]["ListingPrice"]["Amount"])) {
        $eigenerPreis_raw = $concurrentData['own_price']['data']["payload"][0]["Product"]["Offers"][0]["BuyingPrice"]["ListingPrice"]["Amount"];
    }
    
    $isWinner = IsBuyBoxWinner(getInfoByASIN($data, "offers"));

    $buyboxpreis = filter_var($buyboxpreis_raw, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    $niedrigsterPreis = filter_var($niedrigsterPreis_raw, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    $eigenerPreis = filter_var($eigenerPreis_raw, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);

    if ($eigenerPreis === null) {
        Logger::warning("Eigener Preis konnte nicht festgestellt werden. Berechnung abgebrochen.", ['asin' => $asin, 'sku' => $sku]);
        return null;
    }

    // NEW: Prevent DB crash and price-tanking when you are the only seller
    if ($niedrigsterPreis === null) {
        Logger::info("Kein Mitbewerber (Niedrigster Preis = null). Nutze eigenen Preis für Berechnung.", ['asin' => $asin, 'sku' => $sku]);
        $niedrigsterPreis = $eigenerPreis;
    }

    // LOG: Aktuelle Marktsituation von Amazon
    Logger::info("Marktdaten empfangen", [
        'asin' => $asin, 
        'sku' => $sku, 
        'eigener_preis_aktuell' => $eigenerPreis, 
        'niedrigster_preis' => $niedrigsterPreis, 
        'buybox_preis' => $buyboxpreis, 
        'buybox_winner' => $isWinner ? 'Ja' : 'Nein',
        'counter_alt' => $counter
    ]);

    $initStmt = $dbConnection->prepare("SELECT * FROM tric4calc.$dbName WHERE produktid = :produktid ORDER BY datum DESC LIMIT 1");
    $initStmt->execute([':produktid' => $produktid]);
    $initResult = $initStmt->fetch(PDO::FETCH_ASSOC);
    if($initResult == null){
        Logger::info("Inititaler Datenbankeintrag erstellt (noch keine Preisberechnung)", ['asin' => $asin, 'sku' => $sku]);
        $stmtBuybox = $dbConnection->prepare(
            "INSERT INTO tric4calc.$dbName (produktid, eigenerPreis, niedrigsterPreis, buyboxPreis, datum, action, isWinner)
             VALUES (:produktid, :eigenerPreis, :niedrigsterPreis, :buyboxPreis, NOW(), 'init', :isWinner)"
        );
        $stmtBuybox->bindParam(':produktid', $produktid, PDO::PARAM_INT);
        $stmtBuybox->bindValue(':eigenerPreis', $eigenerPreis, $eigenerPreis !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtBuybox->bindValue(':niedrigsterPreis', $niedrigsterPreis, $niedrigsterPreis !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtBuybox->bindValue(':buyboxPreis', $buyboxpreis, $buyboxpreis !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtBuybox->bindValue(':isWinner', $isWinner ? "Ja" : "Nein", $isWinner !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtBuybox->execute();
        return null;
    }

    if ($eigenerPreis === null) {
        Logger::warning("Eigener Preis konnte nicht festgestellt werden. Berechnung abgebrochen.", ['asin' => $asin, 'sku' => $sku]);
        return null;
    }

    logCurrentState($dbConnection, $produktid, $eigenerPreis, $niedrigsterPreis, $buyboxpreis, $counter, $isWinner);
    
    $neuerPreis = null;

    if($eigenerPreis > 10){
        $step_size = $stepsize_big;
    } else {
        $step_size = $stepsize_small;
    }

    // --- PREISBERECHNUNG ---
    if ($isWinner){
        if($counter != 0){
            if($counter == 3 && ($niedrigsterPreis - ($step_size * 2)) > $eigenerPreis ){
                $counter = 4;
                $neuerPreis = $eigenerPreis + $step_size;
            }
            elseif($counter == 4){
                $neuerPreis = $eigenerPreis + $step_size;
            }else {
                $neuerPreis = $eigenerPreis;
            }
        } else {
            $neuerPreis = $niedrigsterPreis  - $step_size;
        }
    } else{
        if($buyboxpreis == null){
            if($counter == 4){
                $neuerPreis = $eigenerPreis - $step_size;
                $counter = 5;
            } else {
                $neuerPreis = $eigenerPreis;
                try{
                    // Verwende den Concurrent Fetch für den Featured Price
                    if (isset($concurrentData['featured_price']['data']['responses'][0]['body']['featuredOfferExpectedPriceResults'][0]['featuredOfferExpectedPrice']['listingPrice']['amount'])) {
                        $neuerPreis = (float) $concurrentData['featured_price']['data']['responses'][0]['body']['featuredOfferExpectedPriceResults'][0]['featuredOfferExpectedPrice']['listingPrice']['amount'];
                    }
                } catch (Exception $e) {
                    Logger::error("Fehler bei concurrent featured price extraction", ['asin' => $asin, 'sku' => $sku, 'error' => $e->getMessage()]);
                }
                if($neuerPreis != $eigenerPreis){
                    $counter = 4;
                }
            }
        }elseif($counter == 0){
            $neuerPreis = $buyboxpreis;
            $counter = 1;
        }elseif ($counter == 1){
            $counter = 2;
            $neuerPreis = $niedrigsterPreis  + $step_size;
        } elseif($counter == 2){
            $counter = 3;
            $neuerPreis = $niedrigsterPreis  - $step_size;
        } elseif($counter == 3) {
            if($eigenerPreis < $niedrigsterPreis){
                $neuerPreis = $eigenerPreis - $step_size;
            } else{
                $counter = 0;
                $neuerPreis = $niedrigsterPreis  - $step_size;
            }
        } elseif($counter == 4){
            $neuerPreis = $eigenerPreis - $step_size;
            $counter = 6;
        } elseif($counter == 5){
            $neuerPreis = $eigenerPreis - $step_size;
            $counter = 4;
        }elseif($counter == 6){
            $neuerPreis = $niedrigsterPreis - $step_size;
            $counter = 3;
        }else{
            Logger::error("Ungueltiger Counter-Wert", ['asin' => $asin, 'sku' => $sku, 'counter' => $counter]);
            return null;
        }
    }

    if ($neuerPreis !== null) {
        $kalkulierterPreis = $neuerPreis; 
        
        $neuerPreis = min($neuerPreis, $max_preis);
        $neuerPreis = max($min_preis, $neuerPreis);
        $neuerPreis = round($neuerPreis, 2);

        $limitierung = "Keine";
        if ($neuerPreis > $kalkulierterPreis) $limitierung = "Durch Min-Preis angehoben";
        if ($neuerPreis < $kalkulierterPreis) $limitierung = "Durch Max-Preis gedeckelt";

        // LOG: Die finale Preis-Entscheidung
        Logger::info("Preisberechnung abgeschlossen", [
            'asin' => $asin,
            'sku' => $sku,
            'kalkulierter_preis' => $kalkulierterPreis,
            'finaler_neuer_preis' => $neuerPreis,
            'counter_neu' => $counter,
            'limitierung' => $limitierung
        ]);
       
        logPlannedAction($dbConnection, $produktid, $neuerPreis, $niedrigsterPreis, $buyboxpreis, $action, $counter, $isWinner);
        
    } else {
        Logger::error("Berechnungsfehler: Neuer Preis ist null.", ['asin' => $asin, 'sku' => $sku]);
        return $eigenerPreis ?? null; 
    }

    return $neuerPreis;
}

function logCurrentState($dbConnection, $produktid, $eigenerPreis, $niedrigsterPreis, $buyboxPreis, $counter, $isWinner) {
    global $dbName;
    try {
        $isWinnerString = $isWinner ? "Ja" : "Nein";
        
        $stmt = $dbConnection->prepare(
            "INSERT INTO `$dbName` (`produktid`, `eigenerPreis`, `niedrigsterPreis`, `buyboxPreis`, `datum`, `action`, `counter` , `isWinner` )
             VALUES (:produktid, :eigenerPreis, :niedrigsterPreis, :buyboxPreis, current_timestamp(), :action, :counter, :isWinner )"
        );
        $stmt->execute([
            ':produktid' => $produktid,
            ':eigenerPreis' => $eigenerPreis ?? 0.00,
            ':niedrigsterPreis' => $niedrigsterPreis ?? 0.00, 
            ':buyboxPreis' => $buyboxPreis ?? 0.00, 
            ':action' => "document", 
            ":counter" => $counter,
            ":isWinner" => $isWinnerString
        ]);
    } catch (PDOException $e) {
        echo "DB Log Error (document): " . $e->getMessage() . "<br>\r\n";
    }
}
function logPlannedAction($dbConnection, $produktid, $neuerPreis, $niedrigsterPreisContext, $buyboxPreisContext, $action, $counter, $isWinner) {
    global $dbName;
    try {
        date_default_timezone_set('Europe/Berlin');
        $futureTimestamp = date('Y-m-d H:i:s', time() + 10);
        $isWinnerString = $isWinner ? "Ja" : "Nein";

        $stmt = $dbConnection->prepare(
            "INSERT INTO `$dbName` (`produktid`, `eigenerPreis`, `niedrigsterPreis`, `buyboxPreis`, `datum`, `action`, `counter` , `isWinner` )
             VALUES (:produktid, :eigenerPreis, :niedrigsterPreis, :buyboxPreis, :datum, :action, :counter, :isWinner )"
        );
        $stmt->execute([
            ':produktid' => $produktid,
            ':eigenerPreis' => $neuerPreis ?? 0.00,
            ':niedrigsterPreis' => $niedrigsterPreisContext ?? 0.00, 
            ':buyboxPreis' => $buyboxPreisContext ?? 0.00, 
            ':datum' => $futureTimestamp, 
            ':action' => $action ,
            ":counter" => $counter,
            ":isWinner" => $isWinnerString
        ]);
        echo "<script>console.log('Counter: $counter');</script>";
    } catch (PDOException $e) {
        echo "DB Log Error (action): " . $e->getMessage() . "<br>\r\n";
    }
}

function preloadTricomaStocks(array $asinList): array {
    global $dbConnectionTric; // <-- NEU: Globale Verbindung nutzen
    
    $result = [];
    
    if (empty($asinList)) {
        return $result;
    }
    
    foreach ($asinList as $asin) {
        $result[$asin] = ['pure' => 0, 'real' => 0];
    }
    
    $placeholders = implode(',', array_fill(0, count($asinList), '?'));
    
    $stmtPure = $dbConnectionTric->prepare("
        SELECT pfw.wert1 AS asin, SUM(l.menge) AS total_quantity
        FROM produkte_felder_werte pfw
        INNER JOIN lager l ON pfw.produktid = l.vk_ID
        WHERE pfw.feldid = 57 AND pfw.wert1 IN ($placeholders)
        GROUP BY pfw.wert1
    ");
    $stmtPure->execute($asinList);
    while ($row = $stmtPure->fetch(PDO::FETCH_ASSOC)) {
        $asin = $row['asin'];
        $menge = (int)$row['total_quantity'];
        $result[$asin]['pure'] = $menge;
        $result[$asin]['real'] = $menge;
    }
    
    $stmtOpen = $dbConnectionTric->prepare("
        SELECT pfw.wert1 AS asin, SUM(lp.anzahl) AS open_quantity
        FROM lieferungen_positionen lp
        INNER JOIN produkte_felder_werte pfw ON pfw.produktid = lp.produktid
        INNER JOIN lieferungen lief ON lp.lieferungsid = lief.ID
        WHERE pfw.feldid = 57 
          AND pfw.wert1 IN ($placeholders)
          AND lief.versandart = ''
        GROUP BY pfw.wert1
    ");
    $stmtOpen->execute($asinList);
    while ($row = $stmtOpen->fetch(PDO::FETCH_ASSOC)) {
        $asin = $row['asin'];
        $openOrders = (int)$row['open_quantity'];
        
        if (isset($result[$asin])) {
            $realStock = $result[$asin]['pure'] - $openOrders;
            $result[$asin]['real'] = $realStock > 0 ? $realStock : 0;
        }
    }
    
    return $result;
}
?>