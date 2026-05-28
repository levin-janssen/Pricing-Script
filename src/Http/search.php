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

// Quick Stats Calculation
$statTotal = count($asins_for_country);
$statInBB = 0;
$statOutStock = 0;

foreach ($asins_for_country as $item) {
    if (isset($item['buybox_data'])) {
        $isW = strtolower(trim((string)$item['buybox_data']['isWinner']));
        if (in_array($isW, ['ja', '1', 'true'], true)) $statInBB++;
    }
    if (isset($item['stock_data']) && $item['stock_data']['real'] <= 0) {
        $statOutStock++;
    }
}

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing Dashboard - <?= htmlspecialchars($current_marketplace_code) ?></title>
    <link rel="icon" type="image/x-icon" href="img/tag.ico" sizes="32x32">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #0f172a;
            --muted: #64748b;
            --muted-light: #94a3b8;
            --accent: #ea580c;
            --primary: #2563eb;
            --surface: #ffffff;
            --surface-soft: #f8fafc;
            --stroke: #e2e8f0;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            --radius: 12px;
            --radius-sm: 8px;
            --ring: rgba(37, 99, 235, 0.25);
        }
        
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: "Space Grotesk", system-ui, -apple-system, sans-serif;
            color: var(--ink);
            background: radial-gradient(1200px circle at top left, #fff1e6 0%, #f2f6ff 42%, #eefbf7 70%, #f4f4f4 100%);
            background-attachment: fixed;
            padding: 40px 20px 80px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .page {
            max-width: 1400px;
            margin: 0 auto;
        }

        /* --- Hero Section --- */
        .hero {
            display: flex;
            flex-wrap: wrap;
            gap: 32px;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 32px;
        }

        .hero-text .eyebrow {
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 8px;
        }

        .hero-text h1 {
            font-size: 2.25rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .flag-icon {
            height: 1.1em;
            border-radius: 4px;
            box-shadow: var(--shadow-sm);
        }

        .hero-text .subtitle {
            color: var(--muted);
            font-size: 1rem;
            max-width: 500px;
            margin-bottom: 20px;
        }

        .hero-stats {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--stroke);
            border-radius: var(--radius);
            padding: 16px 20px;
            box-shadow: var(--shadow-sm);
            min-width: 140px;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }

        .stat-value {
            font-size: 1.75rem;
            font-weight: 700;
            margin-top: 4px;
            font-variant-numeric: tabular-nums;
        }

        /* --- Panel & Filters --- */
        .panel {
            background: var(--surface);
            padding: 24px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--stroke);
            margin-bottom: 24px;
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 20px;
        }

        .field {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .field label {
            font-size: 0.85rem;
            font-weight: 600;
            color: var(--muted);
        }

        input[type="text"], select {
            width: 100%;
            height: 42px;
            padding: 0 12px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--stroke);
            font-family: inherit;
            font-size: 0.95rem;
            background: var(--surface);
            color: var(--ink);
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
        }

        input:hover, select:hover {
            border-color: var(--muted-light);
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--ring);
        }

        /* --- Buttons --- */
        .actions-group {
            display: flex;
            gap: 12px;
        }

        .btn-primary, .btn-ghost {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 42px;
            padding: 0 20px;
            border-radius: var(--radius-sm);
            font-family: inherit;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            border: none;
            box-shadow: var(--shadow-sm);
        }

        .btn-primary:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }

        .btn-ghost {
            background: var(--surface);
            color: var(--ink);
            border: 1px solid var(--stroke);
        }

        .btn-ghost:hover {
            background: var(--surface-soft);
            border-color: var(--muted-light);
        }

        /* --- Table --- */
        .table-wrap {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--stroke);
            overflow-x: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            text-align: left;
            white-space: nowrap;
        }

        th, td {
            padding: 16px 20px;
            font-size: 0.95rem;
            vertical-align: middle;
        }

        th {
            background: var(--surface-soft);
            color: var(--muted);
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            position: sticky;
            top: 0;
            z-index: 1;
            box-shadow: 0 1px 0 var(--stroke);
            cursor: pointer;
            user-select: none;
            transition: background 0.2s;
        }

        th:hover {
            background: #e2e8f0;
        }

        th.sort-asc::after { content: " ↑"; color: var(--primary); }
        th.sort-desc::after { content: " ↓"; color: var(--primary); }

        tr {
            border-bottom: 1px solid var(--stroke);
            transition: background 0.15s ease;
        }

        tr:last-child {
            border-bottom: none;
        }

        tr:hover {
            background: var(--surface-soft);
        }

        td {
            color: var(--ink);
        }

        /* --- Badges --- */
        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 10px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.85rem;
            min-width: 32px;
        }

        .badge-green { background: #dcfce7; color: #166534; }
        .badge-red { background: #fee2e2; color: #991b1b; }
        .badge-yellow { background: #fef08a; color: #854d0e; }
        .badge-gray { background: #f1f5f9; color: #475569; border: 1px solid var(--stroke); }
        .badge-blue { background: #e0f2fe; color: #075985; border: 1px solid #bae6fd; }

        .item-title {
            max-width: 250px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-weight: 500;
        }

        .asin-text {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-weight: 600;
            color: var(--ink);
        }

        .sku-text {
            color: var(--muted);
            font-size: 0.85rem;
        }

        .action-link {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
            transition: color 0.2s;
        }

        .action-link:hover {
            color: #1e40af;
            text-decoration: underline;
        }
        
        .no-data {
            text-align: center;
            padding: 48px 24px;
            color: var(--muted);
            background: var(--surface);
            font-size: 1.05rem;
            border: 1px dashed var(--muted-light);
            border-radius: var(--radius);
        }

        /* --- Alerts --- */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 24px;
            font-size: 0.95rem;
            font-weight: 500;
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        @media (max-width: 768px) {
            body { padding: 24px 16px 40px; }
            .hero { flex-direction: column; align-items: flex-start; gap: 20px; }
            .hero-stats { width: 100%; }
            .stat-card { flex: 1; }
            .actions-group { flex-direction: column; width: 100%; }
            .btn-primary, .btn-ghost { width: 100%; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/global_header.php'; ?>

    <div class="page">
        
        <?php if ($db_error): ?>
            <div class="alert"><?= htmlspecialchars($db_error) ?></div>
        <?php else: ?>

            <div class="hero">
                <div class="hero-text">
                    <p class="eyebrow">Pricing Dashboard</p>
                    <h1>
                        <img id="currentMarketplaceFlag" class="flag-icon" src="<?= isset($marketplaces[$current_marketplace_code]['img']) ? htmlspecialchars($marketplaces[$current_marketplace_code]['img']) : 'img/default.png' ?>" alt="<?= htmlspecialchars($current_marketplace_code) ?> Flag">
                        <?= htmlspecialchars($current_marketplace_code) ?> Marktplatz
                    </h1>
                    <p class="subtitle">Überblick über Buybox-Status, Bestände und definierte Preisgrenzen für diesen Marktplatz.</p>
                    
                    <div class="actions-group">
                        <a href="addNew.php?country=<?= urlencode($current_marketplace_code) ?>" class="btn-primary">+ Produkt hinzufügen</a>
                        <a href="bestandsabweichungen.php" class="btn-ghost">Bestandsanalyse</a>
                    </div>
                </div>

                <div class="hero-stats">
                    <div class="stat-card">
                        <div class="stat-label">Marktplatz wechseln</div>
                        <select id="marketplaceSelect" style="margin-top: 8px; height: 36px; padding: 0 10px; font-size: 0.9rem; font-weight: 500;">
                            <?php foreach ($marketplaces as $code => $details): ?>
                                <option value="<?= htmlspecialchars($details['url']) ?>" <?= ($code === $current_marketplace_code) ? 'selected' : '' ?>>
                                     <?= htmlspecialchars($details['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">Produkte</div>
                        <div class="stat-value"><?= $statTotal ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">In Buybox</div>
                        <div class="stat-value" style="color: #166534;"><?= $statInBB ?></div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-label">OOS (0 Bestand)</div>
                        <div class="stat-value" style="color: #991b1b;"><?= $statOutStock ?></div>
                    </div>
                </div>
            </div>

            <div class="panel">
                <div class="filter-grid">
                    <div class="field">
                        <label for="tableSearchInput">Suche</label>
                        <input type="text" id="tableSearchInput" placeholder="Titel, ASIN oder SKU..." autocomplete="off">
                    </div>
                    
                    <div class="field">
                        <label for="filterBuybox">Buybox Status</label>
                        <select id="filterBuybox">
                            <option value="all">Alle anzeigen</option>
                            <option value="ja">In Buybox</option>
                            <option value="nein">Nicht in Buybox</option>
                            <option value="potential">Potential (Option möglich)</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="filterStock">Lagerbestand</label>
                        <select id="filterStock">
                            <option value="all">Alle anzeigen</option>
                            <option value="instock">Auf Lager (>0)</option>
                            <option value="lowstock">Wenig Bestand (1-10)</option>
                            <option value="outstock">Ausverkauft (0)</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="filterLimit">Preis-Grenzen</label>
                        <select id="filterLimit">
                            <option value="all">Alle anzeigen</option>
                            <option value="min">An Min-Grenze</option>
                            <option value="max">An Max-Grenze</option>
                        </select>
                    </div>
                </div>
            </div>

            <?php if (empty($asins_for_country)): ?>
                <div class="no-data">
                    Aktuell sind keine Produkte für <strong><?= htmlspecialchars($current_marketplace_code) ?></strong> konfiguriert.<br><br>
                    <a href="addNew.php?country=<?= urlencode($current_marketplace_code) ?>" class="action-link">+ Erstes Produkt hinzufügen</a>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table id="productsTable">
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
                            <?php foreach ($asins_for_country as $item): ?>
                                <?php 
                                    $currency = isset($marketplaces[$current_marketplace_code]['currencyCode']) ? ($marketplaces[$current_marketplace_code]['currencyCode'] === 'GBP' ? '£' : ($marketplaces[$current_marketplace_code]['currencyCode'] === 'SEK' ? 'kr' : '€')) : '€'; 
                                    
                                    $bb_status = 'Unbekannt';
                                    $bb_class = 'badge-gray';
                                    $eigener_preis_str = '-';
                                    $stock_val = 0;
                                    $stock_class = 'badge-red';
                                    
                                    if (isset($item['stock_data'])) {
                                        $stock_val = $item['stock_data']['real'];
                                        if ($stock_val > 10) $stock_class = 'badge-green';
                                        elseif ($stock_val > 0) $stock_class = 'badge-yellow';
                                    }
                                    
                                    $eigener_preis_float = null;

                                    if (isset($item['buybox_data'])) {
                                        $isWinner = strtolower(trim((string)$item['buybox_data']['isWinner']));
                                        if ($isWinner === 'ja' || $isWinner === '1' || $isWinner === 'true') {
                                            $bb_status = 'Ja';
                                            $bb_class = 'badge-green';
                                        } else if ($isWinner === 'nein' || $isWinner === '0' || $isWinner === 'false') {
                                            $bb_status = 'Nein';
                                            $bb_class = 'badge-red';
                                        }
                                        
                                        $eigener_preis_float = (float)$item['buybox_data']['eigenerpreis'];
                                        $eigener_preis_str = number_format($eigener_preis_float, 2, ',', '.') . ' ' . $currency;
                                    }
                                    
                                    $bb_price = isset($item['buybox_data']) && is_numeric($item['buybox_data']['buyboxPreis']) ? $item['buybox_data']['buyboxPreis'] : '';
                                    
                                    // Limits
                                    $min_class = 'badge-gray';
                                    $max_class = 'badge-gray';
                                    $at_min = 0;
                                    $at_max = 0;
                                    if ($eigener_preis_float !== null) {
                                        $min_preis = (float)$item['min_preis'];
                                        $max_preis = (float)$item['max_preis'];
                                        $step_small = (float)$item['stepsize_small'];
                                        
                                        if (abs($eigener_preis_float - $min_preis) <= $step_small + 0.005) {
                                            $min_class = 'badge-yellow';
                                            $at_min = 1;
                                        }
                                        if (abs($eigener_preis_float - $max_preis) <= $step_small + 0.005) {
                                            $max_class = 'badge-green';
                                            $at_max = 1;
                                        }
                                    }
                                ?>
                                <tr class="product-row"
                                    data-bb="<?= strtolower($bb_status) ?>"
                                    data-stock="<?= $stock_val ?>"
                                    data-min="<?= $item['min_preis'] ?>"
                                    data-max="<?= $item['max_preis'] ?>"
                                    data-bbprice="<?= $bb_price ?>"
                                    data-at-min="<?= $at_min ?>"
                                    data-at-max="<?= $at_max ?>"
                                >
                                    <td><span class="asin-text"><?= htmlspecialchars($item['ASIN']) ?></span></td>
                                    <td><span class="sku-text"><?= htmlspecialchars($item['sku']) ?></span></td>
                                    <td class="item-title" title="<?= htmlspecialchars($item['artikelname']) ?>">
                                        <?= htmlspecialchars($item['artikelname']) ?>
                                    </td>
                                    <td style="text-align: center;">
                                        <a href="bestandsabweichungen_historie.php?asin=<?= urlencode($item['ASIN']) ?>" style="text-decoration: none;">
                                            <span class="badge <?= $stock_class ?>" title="Roher Lagerbestand: <?= isset($item['stock_data']) ? $item['stock_data']['pure'] : 0 ?>"><?= $stock_val ?></span>
                                        </a>
                                    </td>
                                    <td style="text-align: center;">
                                        <span class="badge <?= $bb_class ?>"><?= $bb_status ?></span>
                                    </td>
                                    <td><span class="badge <?= $min_class ?>" title="<?= $at_min ? 'Aktueller Preis liegt an der Min-Grenze!' : '' ?>"><?= htmlspecialchars(number_format((float)$item['min_preis'], 2, ',', '.')) ?> <?= $currency ?></span></td>
                                    <td><span class="badge <?= $max_class ?>" title="<?= $at_max ? 'Aktueller Preis liegt an der Max-Grenze!' : '' ?>"><?= htmlspecialchars(number_format((float)$item['max_preis'], 2, ',', '.')) ?> <?= $currency ?></span></td>
                                    <td><span class="badge badge-blue"><?= $eigener_preis_str ?></span></td>
                                    <td style="text-align: right;">
                                        <a href="results.php?country=<?= urlencode($current_marketplace_code) ?>&asin=<?= urlencode($item['ASIN']) ?>" class="action-link">Bearbeiten &rarr;</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>

    <script>
        // Table filtering script
        const searchInput = document.getElementById('tableSearchInput');
        const filterBuybox = document.getElementById('filterBuybox');
        const filterStock = document.getElementById('filterStock');
        const filterLimit = document.getElementById('filterLimit');
        const rows = document.querySelectorAll('.product-row');

        function applyFilters() {
            const searchText = searchInput ? searchInput.value.toLowerCase() : '';
            const bbFilter = filterBuybox ? filterBuybox.value : 'all';
            const stockFilter = filterStock ? filterStock.value : 'all';
            const limitFilter = filterLimit ? filterLimit.value : 'all';

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

                // 4. Limit filter
                if (show && limitFilter !== 'all') {
                    const atMin = row.getAttribute('data-at-min');
                    const atMax = row.getAttribute('data-at-max');
                    
                    if (limitFilter === 'min' && atMin !== '1') show = false;
                    if (limitFilter === 'max' && atMax !== '1') show = false;
                }

                row.style.display = show ? '' : 'none';
            });
        }

        if (searchInput) searchInput.addEventListener('keyup', applyFilters);
        if (filterBuybox) filterBuybox.addEventListener('change', applyFilters);
        if (filterStock) filterStock.addEventListener('change', applyFilters);
        if (filterLimit) filterLimit.addEventListener('change', applyFilters);

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

        // Marketplace Switching Logic
        const marketplaceSelect = document.getElementById('marketplaceSelect');
        const currentMarketplaceFlag = document.getElementById('currentMarketplaceFlag');
        const marketplacesData = <?php echo json_encode($marketplaces); ?>;

        marketplaceSelect.addEventListener('change', function() {
            const selectedUrl = this.value;
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
            location.href = selectedUrl;
        });
    </script>
</body>
</html>