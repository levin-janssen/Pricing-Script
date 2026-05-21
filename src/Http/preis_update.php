<?php
ini_set('default_charset', 'UTF-8');
ini_set('error_log', APP_ROOT . '/error.log');

require_once APP_ROOT . '/src/Support/Logger.php';
require_once APP_ROOT . '/config/db_connection.php';
require_once APP_ROOT . '/src/Services/sp_api_functions.php';

Logger::info("Starting preis_update script");

// Configuration
$hoursToCheck = 2;
$chunkSize = 20;
$marketplaceId = "A1PA6795UKMFR9";

$twoHoursAgo = date('Y-m-d - H:i:s', strtotime("-{$hoursToCheck} hours"));

echo "<!DOCTYPE html>
<html lang='de'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Preis Update</title>
</head>
<body>";

echo "Es wird nach Produktänderungen <b>seit " . substr($twoHoursAgo, 13) . "</b> gesucht<br>";

$produktIds = [];

// Fetch IDs from 'lager_umbuchungen'
try {
    $statement = $dbConnectionTric->prepare("
        SELECT produktId 
        FROM lager_umbuchungen 
        WHERE STR_TO_DATE(datum_gebucht, '%Y-%m-%d - %H:%i:%s') >= STR_TO_DATE(:twoHoursAgo, '%Y-%m-%d - %H:%i:%s')
    ");
    $statement->execute([':twoHoursAgo' => $twoHoursAgo]);
    $result = $statement->fetchAll(PDO::FETCH_COLUMN);
    $produktIds = array_merge($produktIds, $result);
    Logger::info("Fetched IDs from lager_umbuchungen", ['count' => count($result)]);
} catch (PDOException $e) {
    Logger::error("Error fetching from lager_umbuchungen", ['error' => $e->getMessage()]);
}

// Fetch IDs from 'lager_ausbuchungen'
try {
    $statement = $dbConnectionTric->prepare("
        SELECT produktId 
        FROM lager_ausbuchungen 
        WHERE STR_TO_DATE(datum_ausgebucht, '%Y-%m-%d - %H:%i:%s') >= STR_TO_DATE(:twoHoursAgo, '%Y-%m-%d - %H:%i:%s')
    ");
    $statement->execute([':twoHoursAgo' => $twoHoursAgo]);
    $result = $statement->fetchAll(PDO::FETCH_COLUMN);
    $produktIds = array_merge($produktIds, $result);
    Logger::info("Fetched IDs from lager_ausbuchungen", ['count' => count($result)]);
} catch (PDOException $e) {
    Logger::error("Error fetching from lager_ausbuchungen", ['error' => $e->getMessage()]);
}

// Fetch IDs from 'lager_einbuchungen'
try {
    $statement = $dbConnectionTric->prepare("
        SELECT produktId 
        FROM lager_einbuchungen 
        WHERE STR_TO_DATE(datum_eingebucht, '%Y-%m-%d - %H:%i:%s') >= STR_TO_DATE(:twoHoursAgo, '%Y-%m-%d - %H:%i:%s')
    ");
    $statement->execute([':twoHoursAgo' => $twoHoursAgo]);
    $result = $statement->fetchAll(PDO::FETCH_COLUMN);
    $produktIds = array_merge($produktIds, $result);
    Logger::info("Fetched IDs from lager_einbuchungen", ['count' => count($result)]);
} catch (PDOException $e) {
    Logger::error("Error fetching from lager_einbuchungen", ['error' => $e->getMessage()]);
}

// If no product IDs found, exit early
if (empty($produktIds)) {
    Logger::info("No product changes found");
    echo "Keine Produktänderungen gefunden.";
    echo "</body></html>";
    exit;
}

echo "<b>" . count($produktIds) . "</b> Produkte gefunden ";

// Remove duplicate IDs
$produktIds = array_unique($produktIds);
$produktIds = array_map('strval', $produktIds);

echo "davon <b>" . count($produktIds) . "</b> einzigartig<br>";

// Convert product IDs into a string for SQL IN clause
$list = "'" . implode("','", $produktIds) . "'";

// Fetch ID - ASIN pairs from 'produkte_felder_werte'
try {
    $statement = $dbConnectionTric->prepare("
        SELECT pfw1.produktid, pfw1.wert1 AS 'asin', pfw2.wert1 AS 'sku'
        FROM produkte_felder_werte AS pfw1
        INNER JOIN produkte_felder_werte AS pfw2 ON pfw1.produktid = pfw2.produktid
        WHERE pfw1.feldid = '57' 
        AND pfw2.feldid = '44'
        AND pfw1.produktid IN ($list)
    ");
    $statement->execute();
    $result = $statement->fetchAll(PDO::FETCH_ASSOC);
    Logger::info("Fetched ASIN-SKU pairs", ['count' => count($result)]);
} catch (PDOException $e) {
    Logger::error("Error fetching ASIN-SKU pairs", ['error' => $e->getMessage()]);
    echo "Fehler beim Abrufen der ASIN-SKU Paare.";
    echo "</body></html>";
    exit;
}

$asins_with_skus = array();
foreach ($result as $value) {
    $asins_with_skus[] = array($value["asin"], $value["sku"]);
}

$asins = array_column($asins_with_skus, 0);

if (empty($asins)) {
    Logger::info("No Amazon articles found");
    echo "Keine Produkte auf Amazon gefunden.";
    echo "</body></html>";
    exit;
}

$data = [];
foreach (array_chunk($asins, $chunkSize) as $chunk) {
    $prices = getOwnPricesByASIN($chunk, $marketplaceId);
    if (isset($prices["payload"])) {
        $data = array_merge($data, $prices["payload"]);
        Logger::info("Fetched prices for chunk", ['chunk_size' => count($chunk)]);
        sleep(20);
    } else {
        Logger::warning("Error in Amazon API query", ['prices' => $prices]);
    }
}

$final = [];
$items_changed = 0;

echo "
    <br>
    <table style='border-collapse: collapse;'>
    <tr>
        <th style='text-align: left; padding: 5px;'>ProduktID</th>
        <th style='text-align: left; padding: 5px;'>ASIN</th>
        <th style='text-align: left; padding: 5px;'>SKU</th>
        <th style='text-align: left; padding: 5px;'>neuer Preis (netto)</th>
        <th style='text-align: left; padding: 5px;'>alter Preis (netto)</th>
        <th style='text-align: left; padding: 5px;'>Änderung</th>
        <th style='text-align: left; padding: 5px;'>Titel</th>
    </tr>
";

foreach ($data as $value) {
    if (isset($value["ASIN"])) {
        if (isset($value["Product"]["Offers"][0]["BuyingPrice"]["ListingPrice"]["Amount"]) && isset($value["Product"]["Offers"][0]["SellerSKU"])) {
            $asin = $value["ASIN"];
            $price = $value["Product"]["Offers"][0]["BuyingPrice"]["ListingPrice"]["Amount"];
            $price = round((floatval($price) / (1 + 19 / 100)), 2);
            
            foreach ($result as $item) {
                if ($item['asin'] === $asin) {
                    $produktid = $item['produktid'];
                    break;
                }
            }
            
            $sku = null;
            foreach ($asins_with_skus as $item) {
                if ($item[0] === $asin) {
                    $sku = $item[1];
                    break;
                }
            }

            try {
                $statement = $dbConnectionTric->prepare("
                    SELECT einzelpreis 
                    FROM produkte 
                    WHERE ID = :produktid
                ");
                $statement->execute(array(":produktid" => $produktid));
                $query_result = $statement->fetchAll(PDO::FETCH_COLUMN);
                $old_price = round($query_result[0], 2);
                $difference = round($price - $old_price, 2);
                $name = " ";
                
                if ($difference > 0) {
                    $difference = "+" . $difference;
                }

                if ($old_price != $price) {
                    if ($sku == $value["Product"]["Offers"][0]["SellerSKU"]) {
                        echo "
                            <tr>
                                <td style='border: 1px solid #ddd; padding: 5px;'>$produktid</td>
                                <td style='border: 1px solid #ddd; padding: 5px;'>$asin</td>
                                <td style='border: 1px solid #ddd; padding: 5px;'>$sku</td>
                                <td style='border: 1px solid #ddd; padding: 5px;'>$price" . "€</td>
                                <td style='border: 1px solid #ddd; padding: 5px;'>$old_price" . "€</td>";

                        // Calculate the percentage difference
                        $percent_diff = 0;
                        if ($old_price != 0) {
                            $percent_diff = abs(($price - $old_price) / $old_price * 100);
                        }

                        // Determine background color based on percentage difference
                        $bg_color = ($percent_diff > 30) ? "background-color: #ffff00;" : "";

                        // Determine text color based on whether difference is positive or negative
                        if ($difference > 0) {
                            echo "<td style='color: green; border: 1px solid #ddd; padding: 5px; $bg_color'>$difference" . "€</td>";
                        } else {
                            echo "<td style='color: red; border: 1px solid #ddd; padding: 5px; $bg_color'>$difference" . "€</td>";
                        }

                        $final[] = [
                            'ASIN' => $asin,
                            'produktid' => $produktid,
                            'price' => $price
                        ];

                        $stmt = $dbConnectionTric->prepare("
                            SELECT wert1
                            FROM produkte_felder_werte 
                            WHERE feldid = '40' 
                            AND produktid = '$produktid'
                        ");
                        $stmt->execute();
                        $name_result = $stmt->fetchAll(PDO::FETCH_COLUMN);
                        $name = $name_result[0];
                        $name = mb_convert_encoding($name, 'UTF-8', mb_detect_encoding($name, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true));

                        if ($percent_diff > 30) {
                            Logger::warning("Important price change detected", [
                                'asin' => $asin,
                                'old_price' => $old_price,
                                'new_price' => $price,
                                'difference' => $difference,
                                'percent_diff' => round($percent_diff, 2),
                                'name' => $name
                            ]);
                        } else {
                            Logger::info("Price change detected", [
                                'asin' => $asin,
                                'old_price' => $old_price,
                                'new_price' => $price,
                                'difference' => $difference,
                                'name' => $name
                            ]);
                        }

                        echo "
                        <td style='border: 1px solid #ddd; padding: 5px;'>$name</td>
                        </tr>
                        ";

                        $items_changed++;
                    } else {
                        Logger::info("Price not changed due to SKU mismatch", [
                            'asin' => $asin,
                            'local_sku' => $sku,
                            'amazon_sku' => $value["Product"]["Offers"][0]["SellerSKU"],
                            'name' => $name
                        ]);
                    }
                }
            } catch (PDOException $e) {
                Logger::error("Error fetching current price", ['produktid' => $produktid, 'error' => $e->getMessage()]);
            }
        }
    }
}

echo "</table> <br>";

// Update prices in database
foreach ($final as $value) {
    try {
        $stmt = $dbConnectionTric->prepare(
            "UPDATE produkte
            SET einzelpreis=:price
            WHERE ID=:id
        ");
        $stmt->execute(array(":price" => $value["price"], ":id" => $value["produktid"]));
        Logger::info("Price updated in database", ['produktid' => $value["produktid"], 'price' => $value["price"]]);
    } catch (PDOException $e) {
        Logger::error("Error updating price in database", [
            'produktid' => $value["produktid"],
            'price' => $value["price"],
            'error' => $e->getMessage()
        ]);
    }
}

echo "Updates Erfolgreich abgeschlossen <br>";
echo "Es wurden <b>$items_changed</b> Produkte im Preis angepasst <br>";
$items_not_changed = count($produktIds) - $items_changed;
if ($items_not_changed > 0) {
    echo "(Bei den anderen <b>" . $items_not_changed . "</b> war der Preis identisch oder es lag kein Angebot mehr auf Amazon vor)";
}

// --- Neuer Teil: Bestandsabweichungen der letzten Stunden aus den Logs lesen ---
echo "<br><hr><br>";
echo "<h3>Bestandsanpassungen (letzte {$hoursToCheck} Stunden)</h3>";

$cutoffTime = time() - ($hoursToCheck * 3600);
$logFilesToCheck = [];

// Falls das Zeitfenster über Mitternacht ragt, gestriges Log zuerst prüfen
if (date('Y-m-d', $cutoffTime) !== date('Y-m-d')) {
    $logFilesToCheck[] = APP_ROOT . '/logs/app_' . date('Y-m-d', $cutoffTime) . '.log';
}
$logFilesToCheck[] = APP_ROOT . '/logs/app_' . date('Y-m-d') . '.log';

$echteAbweichungen = [];
$nullAbweichungen = [];
$lastStatePerSku = [];

foreach (array_unique($logFilesToCheck) as $logFile) {
    if (file_exists($logFile)) {
        $handle = fopen($logFile, 'rb');
        if ($handle) {
            while (($line = fgets($handle)) !== false) {
                if (strpos($line, 'Bestandsabweichung festgestellt!') !== false) {
                    if (preg_match('/^\[(.*?)\]/', $line, $dateMatches)) {
                        $logTime = strtotime($dateMatches[1]);
                        
                        // ZEITPRÜFUNG ZUERST: Wir betrachten nur Einträge innerhalb des Fensters
                        if ($logTime >= $cutoffTime) {
                            if (preg_match('/Context:\s*(\{.*?\})$/', trim($line), $contextMatches)) {
                                $context = json_decode($contextMatches[1], true);
                                if ($context) {
                                    $sku = (string)($context['sku'] ?? '-');
                                    $asin = (string)($context['asin'] ?? '-');
                                    $amazonBisher = array_key_exists('amazon_bisher', $context) ? $context['amazon_bisher'] : null;
                                    $tricomaNeu = $context['tricoma_neu'] ?? 0;
                                    
                                    $amazonHash = $amazonBisher === null ? 'null' : (string)$amazonBisher;
                                    $currentHash = $asin . '|' . $amazonHash . '|' . (string)$tricomaNeu;
                                    
                                    // Filtert Duplikate jetzt nur noch innerhalb des aktuellen Fensters
                                    if (isset($lastStatePerSku[$sku]) && $lastStatePerSku[$sku] === $currentHash) {
                                        continue;
                                    }
                                    
                                    $lastStatePerSku[$sku] = $currentHash;
                                    
                                    $amazonCalc = $amazonBisher === null ? 0 : (int)$amazonBisher;
                                    $diff = $tricomaNeu - $amazonCalc;
                                    $diffColor = $diff > 0 ? 'green' : ($diff < 0 ? 'red' : 'black');
                                    
                                    $entry = [
                                        'time' => $dateMatches[1],
                                        'sku' => $sku,
                                        'asin' => $asin,
                                        'amazon_bisher' => $amazonBisher,
                                        'tricoma_neu' => $tricomaNeu,
                                        'diff' => $diff,
                                        'diffColor' => $diffColor
                                    ];

                                    if ($amazonBisher === null) {
                                        $nullAbweichungen[] = $entry;
                                    } else {
                                        $echteAbweichungen[] = $entry;
                                    }
                                }
                            }
                        }
                    }
                }
            }
            fclose($handle);
        }
    }
}

$echteAbweichungen = array_reverse($echteAbweichungen);
$nullAbweichungen = array_reverse($nullAbweichungen);

// --- TABELLE 1: ECHTE ABWEICHUNGEN ---
echo "<h4 style='margin-bottom: 5px; font-family: Arial, sans-serif;'>Wichtige Anpassungen (Echte Differenzen)</h4>";
if (!empty($echteAbweichungen)) {
    echo "<table border='1' cellpadding='6' style='border-collapse: collapse; text-align: left; font-family: Arial, sans-serif; font-size: 14px; width: 100%; max-width: 800px; border-color: #bbbbbb;'>";
    echo "<tr style='background-color: #f2f2f2; color: #333;'>
            <th>Zeitpunkt</th>
            <th>SKU</th>
            <th>ASIN</th>
            <th>Amazon (bisher)</th>
            <th>Tricoma (neu)</th>
            <th>Differenz</th>
          </tr>";
          
    foreach ($echteAbweichungen as $change) {
        $sign = $change['diff'] > 0 ? '+' : '';
        echo "<tr>";
        echo "<td>" . htmlspecialchars($change['time']) . "</td>";
        echo "<td>" . htmlspecialchars((string)$change['sku']) . "</td>";
        echo "<td>" . htmlspecialchars((string)$change['asin']) . "</td>";
        echo "<td>" . htmlspecialchars((string)$change['amazon_bisher']) . "</td>";
        echo "<td>" . htmlspecialchars((string)$change['tricoma_neu']) . "</td>";
        echo "<td style='color: {$change['diffColor']}; font-weight: bold;'>{$sign}{$change['diff']}</td>";
        echo "</tr>";
    }
    echo "</table><br>";
} else {
    echo "<p style='font-family: Arial, sans-serif; color: #555;'><i>Keine echten Bestandsabweichungen in den letzten {$hoursToCheck} Stunden.</i></p>";
}

// --- TABELLE 2: NULL-WERTE ---
if (!empty($nullAbweichungen)) {
    $nullCount = count($nullAbweichungen);
    
    echo "<hr style='border: 0; border-top: 1px dashed #cccccc; margin: 25px 0 15px 0;'>";
    echo "<h4 style='margin-bottom: 5px; color: #666666; font-family: Arial, sans-serif;'>Artikel ohne vorherigen Amazon-Bestand ({$nullCount}x null)</h4>";
    
    echo "<table border='1' cellpadding='4' style='border-collapse: collapse; text-align: left; font-family: Arial, sans-serif; font-size: 12px; color: #666666; width: 100%; max-width: 800px; border-color: #dddddd;'>";
    echo "<tr style='background-color: #f9f9f9;'>
            <th>Zeitpunkt</th>
            <th>SKU</th>
            <th>ASIN</th>
            <th>Tricoma (neu)</th>
          </tr>";
          
    foreach ($nullAbweichungen as $change) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($change['time']) . "</td>";
        echo "<td>" . htmlspecialchars((string)$change['sku']) . "</td>";
        echo "<td>" . htmlspecialchars((string)$change['asin']) . "</td>";
        echo "<td>" . htmlspecialchars((string)$change['tricoma_neu']) . "</td>";
        echo "</tr>";
    }
    echo "</table><br>";
}
// --- Ende des neuen Teils ---

if ($items_changed == 0) {
    Logger::info("No price changes found among products");
}

Logger::info("preis_update script completed", [
    'items_changed' => $items_changed,
    'items_not_changed' => $items_not_changed
]);

echo "</body></html>";
