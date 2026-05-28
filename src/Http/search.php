<?php
ini_set('default_charset',  'UTF-8');
ini_set('display_errors', 1); // For debugging
error_reporting(E_ALL);

require_once APP_ROOT . '/config/marketplaces.php';
require_once APP_ROOT . '/config/db_connection.php';
$dbConnection = $dbConnectionTric4Calc;

$asins_for_country = [];
$db_error = '';

// --- Determine current country from directory path ---
// $currentDir removed
$current_marketplace_code = isset($_GET['country']) ? strtoupper(filter_input(INPUT_GET, 'country', FILTER_SANITIZE_STRING)) : ''; if(!$current_marketplace_code) die("Missing country");

if (!isset($marketplaces[$current_marketplace_code])) {
    $db_error = "Fehler: Unbekannter Marketplace-Code '" . htmlspecialchars($current_marketplace_code) . "' aus Verzeichnispfad.";
} else {
    // Fetch ASINs that are configured in Preisgrenzen for the current country
    try {
        $stmt = $dbConnection->prepare(
            "SELECT pg.ASIN, a.artikelname, a.sku, pg.min_preis, pg.max_preis, pg.stepsize_small, pg.stepsize_big, a.ID as produktid
             FROM Preisgrenzen pg
             JOIN Artikel a ON pg.ASIN = a.asin
             WHERE pg.Land = :land
             ORDER BY a.artikelname ASC"
        );
        $stmt->bindParam(':land', $current_marketplace_code, PDO::PARAM_STR);
        $stmt->execute();
        $asins_for_country = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $bbData = [];
        $stockData = [];
        
        if (count($asins_for_country) > 0) {
            $produktIds = array_column($asins_for_country, 'produktid');
            $inQuery = implode(',', array_fill(0, count($produktIds), '?'));
            
            // --- Fetch Buybox Data ---
            $buybox_table = $marketplaces[$current_marketplace_code]['dbName'] ?? '';
            if (!empty($buybox_table)) {
                // Subquery joining by max datum for each produktid
                $queryBB = "
                    SELECT bb.produktid, bb.isWinner, bb.eigenerpreis, bb.niedrigsterPreis, bb.buyboxPreis
                    FROM $buybox_table bb
                    INNER JOIN (
                        SELECT produktid, MAX(datum) as max_datum
                        FROM $buybox_table
                        WHERE produktid IN ($inQuery)
                        GROUP BY produktid
                    ) latest ON bb.produktid = latest.produktid AND bb.datum = latest.max_datum
                ";
                
                $stmtBB = $dbConnection->prepare($queryBB);
                $stmtBB->execute($produktIds);
                
                while ($row = $stmtBB->fetch(PDO::FETCH_ASSOC)) {
                    $bbData[$row['produktid']] = $row;
                }
            }

            // --- Fetch Tricoma Stock Data ---
            $asinList = array_unique(array_column($asins_for_country, 'ASIN'));
            if (!empty($asinList)) {
                $asinPlaceholders = implode(',', array_fill(0, count($asinList), '?'));
                
                foreach ($asinList as $a) {
                    $stockData[$a] = ['pure' => 0, 'real' => 0];
                }
                
                // Pure (raw) stock
                $stmtPure = $dbConnectionTric->prepare("
                    SELECT pfw.wert1 AS asin, SUM(l.menge) AS total_quantity
                    FROM produkte_felder_werte pfw
                    INNER JOIN lager l ON pfw.produktid = l.vk_ID
                    WHERE pfw.feldid = 57 AND pfw.wert1 IN ($asinPlaceholders)
                    GROUP BY pfw.wert1
                ");
                $stmtPure->execute(array_values($asinList));
                while ($row = $stmtPure->fetch(PDO::FETCH_ASSOC)) {
                    $stockData[$row['asin']]['pure'] = (int)$row['total_quantity'];
                    $stockData[$row['asin']]['real'] = (int)$row['total_quantity'];
                }
                
                // Open orders -> real stock
                $stmtOpen = $dbConnectionTric->prepare("
                    SELECT pfw.wert1 AS asin, SUM(lp.anzahl) AS open_quantity
                    FROM lieferungen_positionen lp
                    INNER JOIN produkte_felder_werte pfw ON pfw.produktid = lp.produktid
                    INNER JOIN lieferungen lief ON lp.lieferungsid = lief.ID
                    WHERE pfw.feldid = 57 
                      AND pfw.wert1 IN ($asinPlaceholders)
                      AND lief.versandart = ''
                    GROUP BY pfw.wert1
                ");
                $stmtOpen->execute(array_values($asinList));
                while ($row = $stmtOpen->fetch(PDO::FETCH_ASSOC)) {
                    if (isset($stockData[$row['asin']])) {
                        $realStock = $stockData[$row['asin']]['pure'] - (int)$row['open_quantity'];
                        $stockData[$row['asin']]['real'] = $realStock > 0 ? $realStock : 0;
                    }
                }
            }

            // Assign to main array
            foreach ($asins_for_country as &$item) {
                $item['buybox_data'] = $bbData[$item['produktid']] ?? null;
                $item['stock_data'] = $stockData[$item['ASIN']] ?? null;
            }
            unset($item);
        }

    } catch (\PDOException $e) {
        error_log("DB Fehler in index.php für Land $current_marketplace_code: " . $e->getMessage());
        $db_error = "Fehler beim Abrufen der ASIN-Liste für Land " . htmlspecialchars($current_marketplace_code) . ".";
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ASIN Produktsuche - <?= htmlspecialchars($current_marketplace_code) ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="landingpage.css">
    <link rel="icon" type="image/x-icon" href="img/tag.ico" sizes="32x32">
    <!-- Google Fonts for better typography -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #f4f6f8;
            color: #333;
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .marketplace-select-wrapper {
            position: absolute; top: 20px; left: 20px; display: flex; align-items: center; background: #fff; padding: 5px 10px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        .marketplace-select-wrapper img {
            height: 1.2em; margin-right: 8px; vertical-align: middle; box-shadow: -0.75px 0.75px 3px rgba(0, 0, 0, 0.2); border-radius: 2px;
        }
        #marketplaceSelect {
            padding: 5px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9rem; background-color: #f9f9f9; cursor: pointer; outline: none; border: none;
        }
        
        .dashboard-container {
            max-width: 1400px;
            margin: 0 auto;
            margin-top: 30px;
            background: #fff;
            padding: 30px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.05);
        }

        .dashboard-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .search-box {
            display: flex;
            align-items: center;
            flex: 1;
            min-width: 250px;
            max-width: 400px;
        }

        .search-box input {
            width: 100%;
            padding: 12px 18px;
            border: 1px solid #e0e4e8;
            border-radius: 25px;
            font-size: 0.95rem;
            outline: none;
            transition: all 0.3s ease;
            margin-bottom: 0;
            background-color: #f8f9fa;
        }
        .search-box input:focus {
            border-color: #007bff;
            box-shadow: 0 0 0 3px rgba(0,123,255,0.15);
            background-color: #fff;
        }

        .dashboard-actions {
            display: flex;
            gap: 10px;
        }

        .dashboard-actions a button, .dashboard-actions button {
            background-color: #007bff;
            color: white;
            padding: 10px 18px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.95rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .dashboard-actions button:hover {
            background-color: #0056b3;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,123,255,0.2);
        }

        .dashboard-actions .btn-secondary {
            background-color: #fff;
            color: #495057;
            border: 1px solid #ced4da;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .dashboard-actions .btn-secondary:hover {
            background-color: #f8f9fa;
            color: #212529;
            box-shadow: 0 4px 8px rgba(0,0,0,0.05);
        }

        .products-table-wrapper {
            overflow-x: auto;
            border-radius: 8px;
            border: 1px solid #eaedf0;
        }

        table.products-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
        }

        table.products-table th, table.products-table td {
            padding: 14px 18px;
            text-align: left;
            border-bottom: 1px solid #eaedf0;
            vertical-align: middle;
        }

        table.products-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #495057;
            position: sticky;
            top: 0;
            cursor: pointer;
            user-select: none;
            transition: background-color 0.2s;
            white-space: nowrap;
        }
        
        table.products-table th:hover {
            background-color: #e9ecef;
        }

        table.products-table th.sort-asc::after {
            content: " \25B2"; /* Up arrow */
            font-size: 0.8em;
            color: #007bff;
        }

        table.products-table th.sort-desc::after {
            content: " \25BC"; /* Down arrow */
            font-size: 0.8em;
            color: #007bff;
        }

        table.products-table tbody tr {
            transition: background-color 0.15s ease;
        }

        table.products-table tbody tr:hover {
            background-color: #f8f9fa;
        }

        .action-link {
            text-decoration: none;
            color: #007bff;
            font-weight: 500;
        }
        
        .action-link:hover {
            text-decoration: underline;
        }

        .price-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 0.9em;
            font-weight: 500;
            background-color: #f1f3f5;
            color: #495057;
            border: 1px solid #e9ecef;
        }

        .stock-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: bold;
            font-size: 0.85em;
            color: white;
            min-width: 35px;
            text-align: center;
        }
        .stock-good { background-color: #28a745; }
        .stock-low { background-color: #ffc107; color: #212529; }
        .stock-out { background-color: #dc3545; }

        .bb-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85em;
            font-weight: 600;
            color: white;
            min-width: 80px;
        }
        .bb-yes {
            background-color: #28a745;
            box-shadow: 0 2px 4px rgba(40,167,69,0.3);
        }
        .bb-no {
            background-color: #dc3545;
            box-shadow: 0 2px 4px rgba(220,53,69,0.3);
        }
        .bb-unknown {
            background-color: #6c757d;
            box-shadow: 0 2px 4px rgba(108,117,125,0.3);
        }

        .no-data {
            text-align: center;
            padding: 40px;
            color: #6c757d;
            font-style: italic;
        }
        
        /* Adjust body max-width for new layout */
        body {
            max-width: 1400px;
        }
    </style>
</head>
<body>

    <div class="marketplace-select-wrapper">
         <img id="currentMarketplaceFlag" src="<?= isset($marketplaces[$current_marketplace_code]['img']) ? htmlspecialchars($marketplaces[$current_marketplace_code]['img']) : 'img/default.png' ?>" alt="<?= htmlspecialchars($current_marketplace_code) ?> Flag">
        <select id="marketplaceSelect">
            <?php foreach ($marketplaces as $code => $details): ?>
                <option value="<?= htmlspecialchars($details['url']) ?>" <?= ($code === $current_marketplace_code) ? 'selected' : '' ?>>
                     <?= htmlspecialchars($details['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <a href="index.php" style="text-decoration: none !important;">
    <h1>
        <img src="<?= isset($marketplaces[$current_marketplace_code]['img']) ? htmlspecialchars($marketplaces[$current_marketplace_code]['img']) : 'img/default.png' ?>" alt="Flag <?= htmlspecialchars($current_marketplace_code) ?>" style="height:1.2em; vertical-align:middle;">
        <span>Pricing Dashboard - <?= htmlspecialchars($current_marketplace_code) ?></span>
    </h1>
    </a>
    <a href="addNew.php?country=<?= urlencode($current_marketplace_code) ?>" style="position: absolute; top: 20px; right: 20px;">
        <button id="addproductBtn" style="background-color: #007bff; color: white; border: none; padding: 10px 15px; border-radius: 6px; cursor: pointer; font-weight: bold; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            + Produkt hinzufügen 
        </button>
    </a>
    

    <?php if ($db_error): ?>
        <p class="message error" style="text-align:center;"><?= htmlspecialchars($db_error) ?></p>
    <?php else: ?>
        <div class="dashboard-container">
            <div class="dashboard-header">
                <!-- Search & Filters Container -->
                <div style="display: flex; gap: 15px; flex-wrap: wrap; flex: 1; align-items: center;">
                    <div class="search-box" style="flex: unset; width: 300px;">
                        <input type="text" id="tableSearchInput" placeholder="Suchen nach Titel, ASIN oder SKU..." autocomplete="off">
                    </div>
                    
                    <select id="filterBuybox" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; outline: none; background: #fff; font-size: 0.95rem; cursor: pointer; color: #495057;">
                        <option value="all">Buybox: Alle</option>
                        <option value="ja">In Buybox</option>
                        <option value="nein">Nicht in Buybox</option>
                        <option value="potential">Potential (Nicht in BB, Option möglich)</option>
                    </select>

                    <select id="filterStock" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 6px; outline: none; background: #fff; font-size: 0.95rem; cursor: pointer; color: #495057;">
                        <option value="all">Bestand: Alle</option>
                        <option value="instock">Auf Lager (>0)</option>
                        <option value="lowstock">Wenig Bestand (1-10)</option>
                        <option value="outstock">Ausverkauft (0)</option>
                    </select>
                </div>
                
                <div class="dashboard-actions">
                    <a href="bestandsabweichungen.php" style="text-decoration: none;">
                        <button type="button" class="btn-secondary">Bestandsanalyse</button>
                    </a>
                </div>
            </div>

            <div class="products-table-wrapper">
                <table class="products-table" id="productsTable">
                    <thead>
                        <tr>
                            <th data-sort="string">ASIN</th>
                            <th data-sort="string">SKU</th>
                            <th data-sort="string">Produktname</th>
                            <th data-sort="number" style="text-align: center;">Bestand</th>
                            <th data-sort="string" style="text-align: center;">Buybox</th>
                            <th data-sort="currency">Min Preis</th>
                            <th data-sort="currency">Max Preis</th>
                            <th data-sort="currency">Akt. Preis</th>
                            <th style="text-align: right; cursor: default;">Aktion</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($asins_for_country)): ?>
                            <tr>
                                <td colspan="9" class="no-data">
                                    Aktuell sind keine Produkte für <?= htmlspecialchars($current_marketplace_code) ?> konfiguriert. 
                                    <br><br>
                                    <a href="addNew.php?country=<?= urlencode($current_marketplace_code) ?>">Fügen Sie welche über "+ Produkt hinzufügen" hinzu.</a>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($asins_for_country as $item): ?>
                                <?php 
                                    $currency = isset($marketplaces[$current_marketplace_code]['currencyCode']) ? ($marketplaces[$current_marketplace_code]['currencyCode'] === 'GBP' ? '£' : ($marketplaces[$current_marketplace_code]['currencyCode'] === 'SEK' ? 'kr' : '€')) : '€'; 
                                    
                                    $bb_status = 'Unbekannt';
                                    $bb_class = 'bb-unknown';
                                    $eigener_preis_str = '-';
                                    $stock_val = 0;
                                    $stock_class = 'stock-out';
                                    
                                    if (isset($item['stock_data'])) {
                                        $stock_val = $item['stock_data']['real'];
                                        if ($stock_val > 10) $stock_class = 'stock-good';
                                        elseif ($stock_val > 0) $stock_class = 'stock-low';
                                    }
                                    
                                    if (isset($item['buybox_data'])) {
                                        $isWinner = strtolower(trim((string)$item['buybox_data']['isWinner']));
                                        if ($isWinner === 'ja' || $isWinner === '1' || $isWinner === 'true') {
                                            $bb_status = 'Ja';
                                            $bb_class = 'bb-yes';
                                        } else if ($isWinner === 'nein' || $isWinner === '0' || $isWinner === 'false') {
                                            $bb_status = 'Nein';
                                            $bb_class = 'bb-no';
                                        }
                                        
                                        $eigener_preis_str = number_format((float)$item['buybox_data']['eigenerpreis'], 2, ',', '.') . ' ' . $currency;
                                    }
                                    
                                    $bb_price = isset($item['buybox_data']) && is_numeric($item['buybox_data']['buyboxPreis']) ? $item['buybox_data']['buyboxPreis'] : '';
                                ?>
                                <tr class="product-row"
                                    data-bb="<?= strtolower($bb_status) ?>"
                                    data-stock="<?= $stock_val ?>"
                                    data-min="<?= $item['min_preis'] ?>"
                                    data-max="<?= $item['max_preis'] ?>"
                                    data-bbprice="<?= $bb_price ?>"
                                >
                                    <td><strong style="color: #495057; font-family: monospace; font-size: 1.05em;"><?= htmlspecialchars($item['ASIN']) ?></strong></td>
                                    <td><span style="color: #6c757d; font-size: 0.9em;"><?= htmlspecialchars($item['sku']) ?></span></td>
                                    <td style="font-weight: 500; color: #333; max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;" title="<?= htmlspecialchars($item['artikelname']) ?>">
                                        <?= htmlspecialchars($item['artikelname']) ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <a href="bestandsabweichungen_historie.php?asin=<?= urlencode($item['ASIN']) ?>" style="text-decoration: none;">
                                            <span class="stock-badge <?= $stock_class ?>" title="Roher Lagerbestand: <?= isset($item['stock_data']) ? $item['stock_data']['pure'] : 0 ?>. Klick für Bestandsverlauf."><?= $stock_val ?></span>
                                        </a>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="bb-badge <?= $bb_class ?>"><?= $bb_status ?></span>
                                    </td>
                                    <td><span class="price-badge"><?= htmlspecialchars(number_format((float)$item['min_preis'], 2, ',', '.')) ?> <?= $currency ?></span></td>
                                    <td><span class="price-badge"><?= htmlspecialchars(number_format((float)$item['max_preis'], 2, ',', '.')) ?> <?= $currency ?></span></td>
                                    <td><span class="price-badge" style="background-color:#e3f2fd; border-color:#b6effb;"><?= $eigener_preis_str ?></span></td>
                                    <td style="text-align: right;">
                                        <a href="results.php?country=<?= urlencode($current_marketplace_code) ?>&asin=<?= urlencode($item['ASIN']) ?>" class="action-link">Bearbeiten / Details &rarr;</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="hinweis" style="margin-top: 20px;">
                <strong>Hinweis:</strong> Es werden nur Artikel angezeigt, die für das Land <strong><?= htmlspecialchars($current_marketplace_code) ?></strong> Preisgrenzen hinterlegt haben.
            </div>
        </div>
    <?php endif; ?>

    <script>
        // Table filtering script
        const searchInput = document.getElementById('tableSearchInput');
        const filterBuybox = document.getElementById('filterBuybox');
        const filterStock = document.getElementById('filterStock');
        const rows = document.querySelectorAll('.product-row');

        function applyFilters() {
            const searchText = searchInput ? searchInput.value.toLowerCase() : '';
            const bbFilter = filterBuybox ? filterBuybox.value : 'all';
            const stockFilter = filterStock ? filterStock.value : 'all';

            rows.forEach(row => {
                let show = true;

                // 1. Search text filter
                if (searchText) {
                    const text = row.textContent.toLowerCase();
                    if (!text.includes(searchText)) {
                        show = false;
                    }
                }

                // 2. Buybox filter
                if (show && bbFilter !== 'all') {
                    const bbStatus = row.getAttribute('data-bb');
                    
                    if (bbFilter === 'ja' && bbStatus !== 'ja') show = false;
                    if (bbFilter === 'nein' && bbStatus !== 'nein') show = false;
                    
                    if (bbFilter === 'potential') {
                        if (bbStatus === 'ja') {
                            show = false;
                        } else {
                            const minPreis = parseFloat(row.getAttribute('data-min'));
                            const maxPreis = parseFloat(row.getAttribute('data-max'));
                            const bbPriceRaw = row.getAttribute('data-bbprice');
                            
                            if (bbPriceRaw === '') {
                                show = false; // missing data
                            } else {
                                const bp = parseFloat(bbPriceRaw);
                                if (isNaN(bp) || isNaN(minPreis) || isNaN(maxPreis)) {
                                    show = false;
                                } else {
                                    if (bp < minPreis || bp > maxPreis) {
                                        show = false;
                                    }
                                }
                            }
                        }
                    }
                }

                // 3. Stock filter
                if (show && stockFilter !== 'all') {
                    const stock = parseInt(row.getAttribute('data-stock'), 10) || 0;
                    if (stockFilter === 'instock' && stock <= 0) show = false;
                    if (stockFilter === 'lowstock' && (stock < 1 || stock > 10)) show = false;
                    if (stockFilter === 'outstock' && stock > 0) show = false;
                }

                row.style.display = show ? '' : 'none';
            });
        }

        if (searchInput) searchInput.addEventListener('keyup', applyFilters);
        if (filterBuybox) filterBuybox.addEventListener('change', applyFilters);
        if (filterStock) filterStock.addEventListener('change', applyFilters);

        // Sorting Logic
        const table = document.getElementById('productsTable');
        if (table) {
            const headers = table.querySelectorAll('th[data-sort]');
            const tbody = table.querySelector('tbody');

            headers.forEach((header, index) => {
                header.addEventListener('click', () => {
                    const type = header.getAttribute('data-sort');
                    const isAscending = header.classList.contains('sort-asc');
                    const direction = isAscending ? -1 : 1;
                    
                    // Clear all arrows
                    headers.forEach(h => {
                        h.classList.remove('sort-asc');
                        h.classList.remove('sort-desc');
                    });
                    
                    // Set new arrow
                    header.classList.add(isAscending ? 'sort-desc' : 'sort-asc');

                    const rowsArray = Array.from(tbody.querySelectorAll('tr.product-row'));

                    rowsArray.sort((rowA, rowB) => {
                        const maxColIndex = rowA.children.length - 1;
                        if(index > maxColIndex) return 0;
                        
                        let cellA = rowA.children[index].textContent.trim();
                        let cellB = rowB.children[index].textContent.trim();

                        if (type === 'number') {
                            const numA = parseFloat(cellA.replace(/[^0-9.-]+/g, '')) || 0;
                            const numB = parseFloat(cellB.replace(/[^0-9.-]+/g, '')) || 0;
                            return (numA - numB) * direction;
                        } else if (type === 'currency') {
                            const valA = parseFloat(cellA.replace(/[^0-9,.-]+/g, '').replace(',', '.')) || 0;
                            const valB = parseFloat(cellB.replace(/[^0-9,.-]+/g, '').replace(',', '.')) || 0;
                            return (valA - valB) * direction;
                        } else {
                            return cellA.localeCompare(cellB, 'de', { numeric: true }) * direction;
                        }
                    });

                    // Re-append sorted rows
                    rowsArray.forEach(row => tbody.appendChild(row));
                });
            });
        }

        const marketplaceSelect = document.getElementById('marketplaceSelect');
        const currentMarketplaceFlag = document.getElementById('currentMarketplaceFlag');
        const marketplacesData = <?php echo json_encode($marketplaces); ?>;

        marketplaceSelect.addEventListener('change', function() {
            const selectedUrl = this.value;
            // Find the marketplace code from the URL to update the flag before navigating
            let selectedCode = '';
            for (const code in marketplacesData) {
                 if (marketplacesData[code].url === selectedUrl) {
                    selectedCode = code;
                    break;
                 }
            }
            if (selectedCode && marketplacesData[selectedCode] && marketplacesData[selectedCode].img) {
                currentMarketplaceFlag.src = marketplacesData[selectedCode].img;
                currentMarketplaceFlag.alt = selectedCode + " Flag";
            }
            // Navigate after a very short delay to allow flag update (optional)
            // setTimeout(() => { location.href = selectedUrl; }, 50);
            location.href = selectedUrl; // Direct navigation
        });
    </script>
</body>
</html>
