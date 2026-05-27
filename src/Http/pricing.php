<?php
$scriptStartTime = microtime(true);

ini_set('default_charset',  'UTF-8');
ini_set('error_log', dirname(__DIR__, 2) . '/error.log'); 

require_once __DIR__ . '/../Support/Logger.php';
require_once __DIR__ . '/../Services/sp_api_functions.php';
require_once __DIR__ . '/../Services/AmazonFeedBuilder.php';
require_once __DIR__ . '/../Services/ManoManoFeedBuilder.php';
require_once dirname(__DIR__, 2) . '/config/marketplaces.php';

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
$dbConnection = new PDO('mysql:dbname=tric4calc;host=127.0.0.1;', 'root', '***REMOVED***');
$dbConnection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$dbConnection->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_WARNING);

$dbConnectionTric = new PDO('mysql:dbname=***REMOVED***;host=***REMOVED***;', '***REMOVED***', '***REMOVED***');
$dbConnectionTric->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$dbConnectionTric->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);

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

    foreach ($asins as $keyArray => $asinData) {
        // START ASIN-TIMER
        $asinStartTime = microtime(true);
        $asin = $asinData["ASIN"];
        
        $statement = $dbConnection->prepare("SELECT sku FROM tric4calc.Artikel WHERE ASIN = '$asin'");
        $statement->execute();
        $result = $statement->fetch(PDO::FETCH_ASSOC);
        $sku = $result["sku"];
        
        // 1. Preisberechnung (wird für FBA und FBM ausgeführt)
        $preis = processAsin($asin);

        // 2. FBA-Prüfung für das Bestandsupdate
        $isFBA = (substr($sku, -strlen('_FBA')) === '_FBA'); // Prüft, ob die SKU mit "_FBA" endet

        if ($isFBA) {
            Logger::info("FBA-Artikel erkannt: Bestand wird nicht übermittelt, nur Preisupdate.", ['asin' => $asin, 'sku' => $sku]);
            echo "FBA-Artikel: Überspringe Bestandsupdate für ASIN $asin.<br>\r\n";
        } else {
           // --- FBM-ARTIKEL: BESTANDSAKTUALISIERUNG ---
            
            // Alten Amazon-Bestand abfragen (nur für den Abgleich)
            $amazonQuantity = getQuantityBySku($sku, "A6F5BRV91OMPP", $marketplaceId);
            
            // Echten Bestand aus Tricoma abfragen (verfügbar = Bestand - offene)
            $tricomaQuantity = getRealTricomaStockByAsin($asin);
            // Rohen Bestand aus Tricoma abfragen (ohne Abzug offener Lieferungen)
            $tricomaPure = getTricomaStockByAsin($asin);

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

        // 3. Preis-Aktualisierung (wird für FBA und FBM ausgeführt)
        if($preis == null){
            echo "Kein neuer Preis für ASIN $asin gesetzt <br>\r\n";
            Logger::warning("Kein neuer Preis gesetzt", ['asin' => $asin, 'sku' => $sku]);
            Logger::performance("ASIN Processed (No Price Update)", microtime(true) - $asinStartTime, ['asin' => $asin, 'sku' => $sku]);
            continue; // Bricht den restlichen Durchlauf ab, da kein Preis da ist
        } 
        
        if(updateAmazonProductPrice( $sku, $preis,  "PRODUCT", $marketplaceId, $currencyCode)){
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
            Logger::error("Fehler beim Setzen des Preises", ['sku' => $sku, 'preis' => $preis, 'marketplaceId' => $marketplaceId]);
        }
        
        // END ASIN-TIMER
        Logger::performance("ASIN Processed (Complete)", microtime(true) - $asinStartTime, ['asin' => $asin, 'sku' => $sku]);
    }

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
function processAsin($asin) {
    echo "<br>\r\n<br>\r\n---  ASIN: $asin ---<br>\r\n";

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
        echo "Error: Produktdaten nicht gefunden für ASIN $asin.<br>\r\n";
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
    echo "ProduktID: $produktid, SKU: $sku, MinPreis: $min_preis, MaxPreis: $max_preis<br>\r\n";  

    $prevStateStmt = $dbConnection->prepare("SELECT eigenerPreis, niedrigsterPreis, buyboxpreis, action, counter FROM tric4calc.$dbName WHERE produktid = :produktid ORDER BY datum DESC LIMIT 1");
    $prevStateStmt->execute([':produktid' => $produktid]);
    $previousState = $prevStateStmt->fetch(PDO::FETCH_ASSOC);
    $eigenerPreis_alt = filter_var($previousState['eigenerPreis'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    $niedrigsterPreis_alt = filter_var($previousState['niedrigsterPreis'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    $buyboxpreis_alt = filter_var($previousState['buyboxpreis'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    $action_alt = $previousState['action'] ?? null;
    $counter = $previousState['counter'] ?? null;
    echo "Previous State - Own: $eigenerPreis_alt, Lowest: $niedrigsterPreis_alt, BB: $buyboxpreis_alt, Action: $action_alt, Counter: $counter<br>\r\n";

    // Inside processAsin(), right before callItemsAPI:

    $apiStartTime = microtime(true);
    $data = callItemsAPI($asin, $marketplaceId);
    Logger::performance("API: callItemsAPI", microtime(true) - $apiStartTime, ['asin' => $asin]);

    if (!$data) {
        error_log("Error: API call failed for ASIN $asin. Data is null.");
         echo "Error: Fehler beim Abrufen der API Data für ASIN $asin.<br>\r\n";
         return null;
    }
    $buyboxpreis_raw = getInfoByASIN($data, "buyboxpreis");
    $niedrigsterPreis_raw = getLowestPrice($data);

    // And later, wrap the getOwnPriceBySku call:
    $ownPriceStartTime = microtime(true);
    $eigenerPreis_raw = getOwnPriceBySku($sku, $marketplaceId);
    Logger::performance("API: getOwnPriceBySku", microtime(true) - $ownPriceStartTime, ['sku' => $sku]);

    $isWinner = IsBuyBoxWinner(getInfoByASIN($data, "offers"));

    $buyboxpreis = filter_var($buyboxpreis_raw, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    $niedrigsterPreis = filter_var($niedrigsterPreis_raw, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    $eigenerPreis = filter_var($eigenerPreis_raw, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    echo "Eigener Preis: $eigenerPreis, Niedrigster Preis: $niedrigsterPreis, BB Preis: $buyboxpreis, Is Winner: " . ($isWinner ? 'Ja' : 'Nein') . "<br>\r\n";

    $initStmt = $dbConnection->prepare("SELECT * FROM tric4calc.$dbName WHERE produktid = :produktid ORDER BY datum DESC LIMIT 1");
    $initStmt->execute([':produktid' => $produktid]);
    $initResult = $initStmt->fetch(PDO::FETCH_ASSOC);
    if($initResult == null){
        error_log("Info: Initial state not found for ASIN $asin. Setting initial values.");
        $stmtBuybox = $dbConnection->prepare(
            "INSERT INTO tric4calc.$dbName (produktid, eigenerPreis, niedrigsterPreis, buyboxPreis, datum, action, isWinner)
             VALUES (:produktid, :eigenerPreis, :niedrigsterPreis, :buyboxPreis, NOW(), 'init', :isWinner)"
        );
        $stmtBuybox->bindParam(':produktid', $produktid, PDO::PARAM_INT);
        $stmtBuybox->bindValue(':eigenerPreis', $eigenerPreis, $eigenerPreis !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtBuybox->bindValue(':niedrigsterPreis', $niedrigsterPreis, $niedrigsterPreis !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtBuybox->bindValue(':buyboxPreis', $buyboxpreis, $buyboxpreis !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtBuybox->bindValue(':isWinner', $isWinner, $isWinner !== null ? PDO::PARAM_STR : PDO::PARAM_NULL);
        $stmtBuybox->execute();
        return null;
    }

    if ($eigenerPreis === null) {
        echo "Error: Eigener Preis konnte nicht festgestellt werden. Kein neuer Preis wird berechnet.<br>\r\n";
        return null;
    }

    logCurrentState($dbConnection, $produktid, $eigenerPreis, $niedrigsterPreis, $buyboxpreis, $counter, $isWinner);
    
    $neuerPreis = null;
    echo "<script>console.log('Counter pre calc: $counter');</script>";

    if($eigenerPreis > 10){
        $step_size = $stepsize_big;
    } else {
        $step_size = $stepsize_small;
    }

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
                    $neuerPreis = getFeaturedOfferExpectedPriceBySKU($sku, $marketplaceId) ?? $eigenerPreis;
                } catch (Exception $e) {}
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
            echo "Error: Counter hat Wert $counter (Ungültig!)<br>\r\n";
            return null;
        }
    }
    echo "<script>console.log('Counter post calc: $counter');</script>";

    if ($neuerPreis !== null) {
        $neuerPreis = min($neuerPreis, $max_preis);
         echo "Applying Max Price ($max_preis): $neuerPreis<br>\r\n";
        $neuerPreis = max($min_preis, $neuerPreis);
        echo "Applying Min Price ($min_preis): $neuerPreis<br>\r\n";

        $neuerPreis = round($neuerPreis, 2);
        echo "Final Calculated Price (after constraints & rounding): $neuerPreis<br>\r\n";

       
        logPlannedAction($dbConnection, $produktid, $neuerPreis, $niedrigsterPreis, $buyboxpreis, $action, $counter, $isWinner);
        echo "DB Logging: Scheduled '$action' action with new price $neuerPreis and counter $counter.<br>\r\n";
        
    } else {
        echo "Error: In der Berechnung ist ein Fehler aufgetreten. Der Preis wurde nicht aktualisiert.<br>\r\n";
        return $eigenerPreis ?? null; 
    }

    return $neuerPreis;
}

function logCurrentState($dbConnection, $produktid, $eigenerPreis, $niedrigsterPreis, $buyboxPreis, $counter, $isWinner) {
    global $dbName;
    try {
        $isWinnerString = null;
        if($isWinner){
            $isWinnerString = "Ja";
        } else{
            $isWinnerString = "Nein";
        }
        $stmt = $dbConnection->prepare(
            "INSERT INTO `$dbName` (`produktid`, `eigenerPreis`, `niedrigsterPreis`, `buyboxPreis`, `datum`, `action`, `counter` , `isWinner` )
             VALUES (:produktid, :eigenerPreis, :niedrigsterPreis, :buyboxPreis, current_timestamp(), :action, :counter, :isWinner )"
        );
        $stmt->execute([
            ':produktid' => $produktid,
            ':eigenerPreis' => $eigenerPreis,
            ':niedrigsterPreis' => $niedrigsterPreis, 
            ':buyboxPreis' => $buyboxPreis, 
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
        $isWinnerString = null;
        if($isWinner){
            $isWinnerString = "Ja";
        } else{
            $isWinnerString = "Nein";
        }

        $stmt = $dbConnection->prepare(
            "INSERT INTO `$dbName` (`produktid`, `eigenerPreis`, `niedrigsterPreis`, `buyboxPreis`, `datum`, `action`, `counter` , `isWinner` )
             VALUES (:produktid, :eigenerPreis, :niedrigsterPreis, :buyboxPreis, :datum, :action, :counter, :isWinner )"
        );
        $stmt->execute([
            ':produktid' => $produktid,
            ':eigenerPreis' => $neuerPreis,
            ':niedrigsterPreis' => $niedrigsterPreisContext, 
            ':buyboxPreis' => $buyboxPreisContext, 
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

function getTricomaStockByAsin($asin) {
    global $dbConnectionTric;
    
    $stmt = $dbConnectionTric->prepare("
        SELECT SUM(l.menge) AS total_quantity
        FROM produkte_felder_werte pfw
        INNER JOIN lager l ON pfw.produktid = l.vk_ID
        WHERE pfw.feldid = 57 AND pfw.wert1 = :asin
    ");
    $stmt->execute([':asin' => $asin]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    return $result['total_quantity'] !== null ? (int)$result['total_quantity'] : 0;
}

function getRealTricomaStockByAsin(string $asin): int {
    global $dbConnectionTric;

    $tricomaPure = getTricomaStockByAsin($asin);

    $queryOpen = "
        SELECT SUM(lp.anzahl) AS open_quantity
        FROM lieferungen_positionen lp
        INNER JOIN produkte_felder_werte pfw ON pfw.produktid = lp.produktid
        INNER JOIN lieferungen lief ON lp.lieferungsid = lief.ID
        WHERE pfw.feldid = 57 
          AND pfw.wert1 = :asin 
          AND lief.versandart = ''
    ";

    $stmt = $dbConnectionTric->prepare($queryOpen);
    $stmt->execute([':asin' => $asin]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $openOrders = ($result !== false && $result['open_quantity'] !== null) ? (int)$result['open_quantity'] : 0;
    $realStock = $tricomaPure - $openOrders;

    return $realStock > 0 ? $realStock : 0;
}

?>