<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
date_default_timezone_set('Europe/Berlin');

require_once APP_ROOT . '/config/db_connection.php';
// Erwartet $dbConnectionTric (Tricoma) und $dbConnectionTric4Calc (Kalkulation)

// --- Parameter Handling ---
$months = isset($_GET['months']) ? (int)$_GET['months'] : 12;
$stock_filter = isset($_GET['stock']) ? $_GET['stock'] : 'zero'; // 'zero', 'in_stock' oder 'all'

if (!in_array($months, [1, 3, 6, 12, 24])) {
    $months = 12;
}
$date_start = date('Y-m-d', strtotime("-$months months"));

// Bekannte FBA Lagerplätze aus Tricoma
$fba_places = "38, 54, 55, 56, 57, 58, 59, 60, 61";
$error = '';
$results = [];
$total_lost_potential = 0;

try {
    // 1. ZUERST Verkäufe im Zeitraum abrufen (Nur FBA-Artikel, erkennbar an werbekennzeichen = 8)
    // Dies dient als Basis-Filter, um die folgenden Abfragen massiv zu beschleunigen.
    $sqlSales = "
        SELECT T1.produktid, SUM(T1.anzahl) as sales
        FROM bestellungen_positionen T1
        JOIN bestellungen T2 ON T1.bestellungsid = T2.id
        WHERE T1.datum >= :start_date 
          AND T2.werbekennzeichen = 8
        GROUP BY T1.produktid 
        HAVING sales > 0
    ";
    
    $stmtSales = $dbConnectionTric->prepare($sqlSales);
    $stmtSales->execute([':start_date' => $date_start]);
    $salesData = [];
    while ($row = $stmtSales->fetch(PDO::FETCH_ASSOC)) {
        $salesData[$row['produktid']] = (int)$row['sales'];
    }

    $relevantProductIds = array_keys($salesData);

    // Wenn es Verkäufe gibt, laden wir den Rest NUR für diese relevanten Produkte
    if (!empty($relevantProductIds)) {
        $inClauseIds = implode(',', array_map('intval', $relevantProductIds));

        // 2. Bestand (FBA & Lokal) in EINER Abfrage und NUR für relevante Produkte
        $stmtStock = $dbConnectionTric->query("
            SELECT 
                vk_ID as produktid, 
                SUM(CASE WHEN lagerplatz IN ($fba_places) THEN menge ELSE 0 END) as fba_stock,
                SUM(CASE WHEN lagerplatz NOT IN ($fba_places) THEN menge ELSE 0 END) as local_stock
            FROM lager 
            WHERE vk_ID IN ($inClauseIds)
            GROUP BY vk_ID
        ");
        
        $fbaStocks = [];
        $localStocks = [];
        if ($stmtStock) {
            while ($row = $stmtStock->fetch(PDO::FETCH_ASSOC)) {
                $pid = $row['produktid'];
                $fbaStocks[$pid] = (int)$row['fba_stock'];
                $localStocks[$pid] = (int)$row['local_stock'];
            }
        }

        // 3. Tricoma Produktdaten (Titel, SKU, ASIN) bündeln & NUR für relevante Produkte
        $stmtFields = $dbConnectionTric->query("
            SELECT produktid, feldid, wert1 
            FROM produkte_felder_werte 
            WHERE feldid IN (40, 44, 57) 
              AND produktid IN ($inClauseIds)
        ");
        
        $tricomaData = [];
        $foundSkus = [];
        if ($stmtFields) {
            while ($row = $stmtFields->fetch(PDO::FETCH_ASSOC)) {
                $pid = $row['produktid'];
                $fid = (int)$row['feldid'];
                $val = trim((string)$row['wert1']);
                
                if (!isset($tricomaData[$pid])) {
                    $tricomaData[$pid] = ['sku' => null, 'asin' => null, 'title' => null];
                }
                
                if ($val !== '') {
                    if ($fid === 40) $tricomaData[$pid]['title'] = $val; // Titel
                    if ($fid === 44) {
                        $tricomaData[$pid]['sku'] = $val;   // Artikelnummer
                        $foundSkus[] = $val; // Case-Insensitive Matching später
                    }
                    if ($fid === 57) $tricomaData[$pid]['asin'] = $val;  // ASIN
                }
            }
        }

        // 4. Fallback aus tric4calc NUR für die gefundenen SKUs
        $calcArticles = [];
        $foundSkus = array_unique(array_filter($foundSkus));
        if (!empty($foundSkus)) {
            $skuIn = implode(',', array_map(function($s) use ($dbConnectionTric4Calc) {
                return $dbConnectionTric4Calc->quote($s);
            }, $foundSkus));
            
            $stmtArtikel = $dbConnectionTric4Calc->query("
                SELECT sku, asin, artikelname 
                FROM Artikel 
                WHERE sku IN ($skuIn)
            ");
            
            if ($stmtArtikel) {
                while ($row = $stmtArtikel->fetch(PDO::FETCH_ASSOC)) {
                    $safeSku = strtolower(trim((string)$row['sku']));
                    $calcArticles[$safeSku] = $row;
                }
            }
        }

        // --- Daten zusammenführen ---
        foreach ($salesData as $pid => $sales) {
            $sku = $tricomaData[$pid]['sku'] ?? null;
            if (!$sku) continue; 
            
            // Gewünschte System-SKUs überspringen
            $skuLower = strtolower($sku);
            if (strpos($skuLower, 'versandkosten') !== false || strpos($skuLower, 'gutschein') !== false) {
                continue;
            }

            $fbaStock = $fbaStocks[$pid] ?? 0;
            
            // Serverseitiger Bestands-Filter anwenden
            if ($stock_filter === 'zero' && $fbaStock > 0) {
                continue;
            }
            if ($stock_filter === 'in_stock' && $fbaStock <= 0) {
                continue;
            }

            $localStock = $localStocks[$pid] ?? 0;
            
            $tricomaTitle = $tricomaData[$pid]['title'] ?? null;
            $tricomaAsin = $tricomaData[$pid]['asin'] ?? null;

            // Namen & ASIN zuweisen (Tricoma bevorzugt, Calc als Fallback)
            $name = $tricomaTitle ?: ($calcArticles[$skuLower]['artikelname'] ?? '');
            $asin = $tricomaAsin ?: ($calcArticles[$skuLower]['asin'] ?? '');

            $avg_monthly = $sales / $months;

            // Reichweite (Runway) in Tagen berechnen
            $runway_days = 0;
            $runway_text = '0 Tage';
            $runway_class = 'bg-red';
            
            if ($fbaStock > 0) {
                if ($avg_monthly > 0) {
                    $months_left = $fbaStock / $avg_monthly;
                    $runway_days = $months_left * 30;
                    
                    if ($months_left >= 12) {
                        $runway_text = number_format($months_left / 12, 1, ',', '.') . ' Jahre';
                        $runway_class = 'bg-green';
                    } elseif ($months_left >= 1) {
                        $runway_text = number_format($months_left, 1, ',', '.') . ' Mon.';
                        $runway_class = 'bg-gray';
                    } else {
                        $runway_text = number_format($runway_days, 0, ',', '.') . ' Tage';
                        $runway_class = $runway_days <= 14 ? 'bg-red' : 'bg-yellow'; 
                    }
                } else {
                    $runway_days = 999999;
                    $runway_text = '∞';
                    $runway_class = 'bg-green';
                }
            }

            if ($fbaStock <= 0) {
                $total_lost_potential += $avg_monthly;
            }

            $results[] = [
                'produktid' => $pid,
                'sku' => $sku,
                'asin' => $asin,
                'name' => $name,
                'sales' => $sales,
                'avg_monthly' => $avg_monthly,
                'fba_stock' => $fbaStock,
                'local_stock' => $localStock,
                'runway' => $runway_text,
                'runway_class' => $runway_class,
                'runway_days' => $runway_days
            ];
        }
    }
} catch (PDOException $e) {
    $error = "Datenbankfehler: " . $e->getMessage();
}

function h(string $value): string {
    if (!mb_check_encoding($value, 'UTF-8')) {
        $value = mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FBA Restock Manager</title>
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
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
            --radius: 12px;
            --radius-sm: 8px;
            --ring: rgba(37, 99, 235, 0.25);
        }
        
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: "Space Grotesk", system-ui, -apple-system, sans-serif;
            color: var(--ink);
            background: radial-gradient(1200px circle at top left, #fff1e6 0%, #f2f6ff 42%, #eefbf7 70%, #f4f4f4 100%);
            background-attachment: fixed;
            padding: 40px 20px 80px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .page { max-width: 1400px; margin: 0 auto; }

        .hero { display: flex; flex-wrap: wrap; gap: 32px; align-items: center; justify-content: space-between; margin-bottom: 32px; }
        .hero-text .eyebrow { text-transform: uppercase; letter-spacing: 0.1em; font-size: 0.75rem; font-weight: 600; color: var(--primary); margin-bottom: 8px; }
        .hero-text h1 { font-size: 2.25rem; font-weight: 700; letter-spacing: -0.02em; margin-bottom: 8px; }
        .hero-text .subtitle { color: var(--muted); font-size: 1rem; max-width: 500px; }

        .hero-stats { display: flex; gap: 16px; flex-wrap: wrap; }
        .stat-card { background: var(--surface); border: 1px solid var(--stroke); border-radius: var(--radius); padding: 16px 20px; box-shadow: var(--shadow-sm); min-width: 160px; }
        .stat-label { font-size: 0.75rem; color: var(--muted); text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; }
        .stat-value { font-size: 1.75rem; font-weight: 700; margin-top: 4px; font-variant-numeric: tabular-nums; }
        
        .panel { background: var(--surface); padding: 24px; border-radius: var(--radius); box-shadow: var(--shadow); border: 1px solid var(--stroke); margin-bottom: 24px; }

        .preset-row { display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid var(--stroke); }
        .btn-preset {
            display: inline-flex; align-items: center; justify-content: center;
            padding: 6px 14px; background: var(--surface-soft); border: 1px solid var(--stroke);
            border-radius: 20px; font-size: 0.85rem; font-weight: 600; color: var(--ink);
            cursor: pointer; transition: all 0.2s ease; font-family: inherit;
        }
        .btn-preset:hover { background: var(--ink); color: #fff; border-color: var(--ink); }

        .filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 20px; align-items: end; }
        .field { display: flex; flex-direction: column; gap: 8px; }
        .field label { font-size: 0.85rem; font-weight: 600; color: var(--muted); }
        input[type="text"], select { width: 100%; height: 42px; padding: 0 12px; border-radius: var(--radius-sm); border: 1px solid var(--stroke); font-family: inherit; font-size: 0.95rem; background: var(--surface); color: var(--ink); box-shadow: var(--shadow-sm); }
        input:focus, select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--ring); }
        
        .options-row { display: flex; flex-wrap: wrap; gap: 24px; margin-top: 20px; border-top: 1px solid var(--stroke); padding-top: 16px; }
        .checkbox-wrapper { display: flex; align-items: center; gap: 8px; cursor: pointer; font-size: 0.9rem; font-weight: 500; }
        .checkbox-wrapper input { width: 18px; height: 18px; cursor: pointer; accent-color: var(--primary); }

        .table-wrap { background: var(--surface); border-radius: var(--radius); box-shadow: var(--shadow); border: 1px solid var(--stroke); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; text-align: left; }
        th, td { padding: 14px 16px; font-size: 0.95rem; vertical-align: middle; border-bottom: 1px solid var(--stroke); }
        
        td:not(.col-title) { white-space: nowrap; }
        .col-title { white-space: normal; word-wrap: break-word; min-width: 280px; max-width: 400px; line-height: 1.4; }
        .data-missing { color: #dc2626; font-style: italic; font-size: 0.85em; font-weight: 600; background: #fee2e2; padding: 2px 6px; border-radius: 4px; display: inline-block; }

        th { background: var(--surface-soft); color: var(--muted); font-weight: 600; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.05em; cursor: pointer; user-select: none; transition: background 0.2s; white-space: nowrap; }
        th:hover { background: #e2e8f0; }
        th.sort-asc::after { content: " ↑"; color: var(--primary); }
        th.sort-desc::after { content: " ↓"; color: var(--primary); }
        th.no-sort { cursor: default; }
        th.no-sort:hover { background: var(--surface-soft); }
        th.no-sort::after { content: none !important; }

        tr { transition: background 0.15s ease; }
        tr:hover { background: var(--surface-soft); }
        
        .hidden-row { display: none !important; }
        .sku-ignored { opacity: 0.3; background: #f8fafc; }
        .badge { display: inline-flex; align-items: center; padding: 4px 10px; border-radius: 6px; font-weight: 600; font-size: 0.85rem; }
        .bg-red { background: #fee2e2; color: #991b1b; }
        .bg-yellow { background: #fef08a; color: #854d0e; }
        .bg-green { background: #dcfce7; color: #166534; }
        .bg-gray { background: #f1f5f9; color: #475569; }
        
        .btn-icon { display: inline-flex; align-items: center; justify-content: center; width: 36px; height: 36px; border-radius: var(--radius-sm); border: 1px solid var(--stroke); color: var(--muted); background: var(--surface); cursor: pointer; transition: all 0.2s ease; }
        .btn-icon:hover { background: var(--surface-soft); color: var(--ink); border-color: var(--muted-light); }
        
        .alert { padding: 12px 16px; border-radius: var(--radius-sm); font-size: 0.95rem; font-weight: 500; background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; margin-bottom: 24px; }
    </style>
</head>
<body>
    <?php include __DIR__ . '/global_header.php'; ?>
    
    <script>
        const PHP_PARAMS_KEY = 'fba_restock_php_params_v1';
        // Auto-Restore PHP Form Parameters on Load
        if (!window.location.search && localStorage.getItem(PHP_PARAMS_KEY)) {
            window.location.replace(window.location.pathname + localStorage.getItem(PHP_PARAMS_KEY));
        }
    </script>

    <div class="page">
        <div class="hero">
            <div class="hero-text">
                <p class="eyebrow">Inventory Analytics</p>
                <h1>FBA Restock Manager</h1>
                <p class="subtitle">Finde heraus, welche Artikel aktuell im FBA-Lager fehlen oder knapp werden.</p>
            </div>
            <div class="hero-stats">
                <div class="stat-card">
                    <div class="stat-label">Gefundene Artikel</div>
                    <div class="stat-value" id="countRows"><?= count($results) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Verpasste Sales / Monat</div>
                    <div class="stat-value" style="color: #b91c1c;">~<?= number_format($total_lost_potential, 0, ',', '.') ?></div>
                </div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="alert"><?= h($error) ?></div>
        <?php endif; ?>

        <div class="panel">
            <div class="preset-row">
                <span style="font-size: 0.85rem; font-weight: 600; color: var(--muted); margin-top: 6px; margin-right: 10px;">Schnellfilter:</span>
                
                <button type="button" class="btn-preset" onclick="applyPreset(12, 'in_stock', 6, 'asc', 'all')">
                    🔥 Reichweite
                </button>
                
                <button type="button" class="btn-preset" onclick="applyPreset(12, 'all', 3, 'desc', 'under_30')">
                    ⚠️ Kritisch (0 - 30 Tage)
                </button>
                
                <button type="button" class="btn-preset" onclick="applyPreset(12, 'zero', 3, 'desc', 'all')">
                    🚨 Out of Stock 
                </button>
            </div>

            <form id="php-filter-form" method="get">
                <div class="filter-grid">
                    <div class="field">
                        <label for="search">Suchen (Titel, ASIN, SKU)</label>
                        <input type="text" id="search" placeholder="Tippen zum filtern..." autocomplete="off">
                    </div>
                    
                    <div class="field">
                        <label for="uiRunway">Reichweite Filter</label>
                        <select id="uiRunway">
                            <option value="all">Alle Reichweiten</option>
                            <option value="out">Leer (0 Tage)</option>
                            <option value="under_30">Kritisch (0 - 30 Tage)</option>
                            <option value="critical">Sehr Knapp (1 - 13 Tage)</option>
                            <option value="low">Knapp (14 - 30 Tage)</option>
                            <option value="good">Ausreichend (> 30 Tage)</option>
                        </select>
                    </div>

                    <div class="field">
                        <label for="months">Verkaufszeitraum (DB)</label>
                        <select name="months" id="months">
                            <option value="1" <?= $months === 1 ? 'selected' : '' ?>>Letzter 1 Monat</option>
                            <option value="3" <?= $months === 3 ? 'selected' : '' ?>>Letzte 3 Monate</option>
                            <option value="6" <?= $months === 6 ? 'selected' : '' ?>>Letzte 6 Monate</option>
                            <option value="12" <?= $months === 12 ? 'selected' : '' ?>>Letzte 12 Monate</option>
                            <option value="24" <?= $months === 24 ? 'selected' : '' ?>>Letzte 24 Monate</option>
                        </select>
                    </div>
                    

                    
                    <div class="field">
                        <label for="stock">FBA Filter (DB)</label>
                        <select name="stock" id="stock">
                            <option value="zero" <?= $stock_filter === 'zero' ? 'selected' : '' ?>>Nur Bestand = 0</option>
                            <option value="in_stock" <?= $stock_filter === 'in_stock' ? 'selected' : '' ?>>Nur Bestand > 0</option>
                            <option value="all" <?= $stock_filter === 'all' ? 'selected' : '' ?>>Alle anzeigen</option>
                        </select>
                    </div>
                </div>
            </form>
            <div class="options-row">
                <label class="checkbox-wrapper">
                    <input type="checkbox" id="toggleIgnored">
                    Ignorierte Artikel einblenden
                </label>
                <label class="checkbox-wrapper">
                    <input type="checkbox" id="toggleLocalStock">
                    Lokalen Bestand einblenden
                </label>
            </div>
        </div>

        <div class="table-wrap">
            <table id="restockTable">
                <thead>
                    <tr>
                        <th data-type="string">ASIN</th> <th data-type="string">SKU</th> <th data-type="string">Produktname</th> <th data-type="number">Verkäufe (<?= $months ?>M)</th> <th data-type="number">Ø / Monat</th> <th data-type="number">FBA Bestand</th> <th data-type="number">Reichweite</th> <th data-type="number" class="col-local">Lokal</th> <th class="no-sort" style="text-align: right;">Aktion</th> </tr>
                </thead>
                <tbody id="tableBody">
                    <?php foreach ($results as $item): ?>
                        <tr class="product-row" 
                            data-sku="<?= h($item['sku']) ?>" 
                            data-runway="<?= $item['runway_days'] ?>">
                            
                            <td data-sort="<?= h($item['asin']) ?>" style="font-family: monospace; font-weight: 600;">
                                <?php if (empty($item['asin'])): ?>
                                    <span class="data-missing" title="Weder in Tricoma noch im Kalkulations-Skript hinterlegt">Fehlt in DB</span>
                                <?php else: ?>
                                    <?= h($item['asin']) ?>
                                <?php endif; ?>
                            </td>
                            
                            <td data-sort="<?= h($item['sku']) ?>" style="color: var(--muted);">
                                <?= h($item['sku']) ?>
                            </td>
                            
                            <td data-sort="<?= h($item['name']) ?>" class="col-title">
                                <?php if (empty($item['name'])): ?>
                                    <span class="data-missing">Titel fehlt in DB</span>
                                <?php else: ?>
                                    <?= h($item['name']) ?>
                                <?php endif; ?>
                            </td>
                            
                            <td data-sort="<?= $item['sales'] ?>" style="font-weight: 600;">
                                <?= $item['sales'] ?>
                                <?= $item['avg_monthly'] >= 30 ? '🔥' : '' ?>
                            </td>
                            
                            <td data-sort="<?= $item['avg_monthly'] ?>">
                                ~<?= number_format($item['avg_monthly'], 1, ',', '.') ?>
                            </td>
                            
                            <td data-sort="<?= $item['fba_stock'] ?>">
                                <span class="badge <?= $item['fba_stock'] > 0 ? 'bg-green' : 'bg-red' ?>">
                                    <?= $item['fba_stock'] ?>
                                </span>
                            </td>
                            
                            <td data-sort="<?= $item['runway_days'] ?>">
                                <span class="badge <?= $item['runway_class'] ?>">
                                    <?= $item['runway'] ?>
                                </span>
                            </td>
                            
                            <td data-sort="<?= $item['local_stock'] ?>" class="col-local">
                                <span class="badge <?= $item['local_stock'] > 0 ? 'bg-green' : 'bg-gray' ?>">
                                    <?= $item['local_stock'] ?>
                                </span>
                            </td>
                            
                            <td style="text-align: right;">
                                <button class="btn-icon ignore-btn" onclick="toggleIgnore('<?= h($item['sku']) ?>')" title="Ausblenden"></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // --- Persistence & State Management ---
        const UI_STATE_KEY = 'fba_restock_ui_state_v2';
        const IGNORE_KEY = 'fba_ignored_skus';
        
        let uiState = JSON.parse(localStorage.getItem(UI_STATE_KEY)) || {
            showIgnored: false,
            showLocal: false,
            runwayFilter: 'all',
            sortCol: 3, 
            sortDir: 'desc'
        };
        let ignoredSkus = JSON.parse(localStorage.getItem(IGNORE_KEY)) || [];

        // DOM Elements
        const searchInput = document.getElementById('search');
        const uiRunwaySelect = document.getElementById('uiRunway');
        const toggleIgnoredCheckbox = document.getElementById('toggleIgnored');
        const toggleLocalCheckbox = document.getElementById('toggleLocalStock');
        const countDisplay = document.getElementById('countRows');
        const tbody = document.getElementById('tableBody');
        let rows = Array.from(tbody.querySelectorAll('.product-row'));
        const headers = Array.from(document.querySelectorAll('th[data-type]'));

        // Icons
        const iconEyeOff = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width: 18px; height: 18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 0 0 1.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0 1 12 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 0 1-4.293 5.774M6.228 6.228 3 3m3.228 3.228 3.65 3.65m7.894 7.894L21 21m-3.228-3.228-3.65-3.65m0 0a3 3 0 1 1-4.243-4.243m4.242 4.242L9.88 9.88" /></svg>`;
        const iconEyeOn = `<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" style="width: 18px; height: 18px;"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z" /></svg>`;

        rows.forEach((row, index) => row.dataset.originalIndex = index);

        function saveUIState() {
            uiState.showIgnored = toggleIgnoredCheckbox.checked;
            uiState.showLocal = toggleLocalCheckbox.checked;
            uiState.runwayFilter = uiRunwaySelect.value;
            localStorage.setItem(UI_STATE_KEY, JSON.stringify(uiState));
        }

        // --- PRESET LOGIC ---
        // Parameter: Monate, Quelle, Bestand, Sortierungsspalte, Sortierungsrichtung, JS-Reichweiten-Filter
        function applyPreset(months, stock, sortCol, sortDir, runwayFilter = 'all') {
            uiState.sortCol = sortCol;
            uiState.sortDir = sortDir;
            uiState.runwayFilter = runwayFilter; 
            localStorage.setItem(UI_STATE_KEY, JSON.stringify(uiState));

            document.getElementById('months').value = months;
            document.getElementById('stock').value = stock;

            const formData = new FormData(document.getElementById('php-filter-form'));
            const params = new URLSearchParams(formData).toString();
            localStorage.setItem(PHP_PARAMS_KEY, '?' + params);
            document.getElementById('php-filter-form').submit();
        }

        function sortByColumn(colIndex, type, direction = 'asc', persist = true) {
            const decorated = rows.map(row => {
                const cell = row.children[colIndex];
                let raw = cell.dataset.sort !== undefined ? cell.dataset.sort : cell.textContent.trim();
                let parsed = type === 'number' ? parseFloat(raw) : raw.toLowerCase();
                if (type === 'number' && isNaN(parsed)) parsed = direction === 'asc' ? Infinity : -Infinity;
                return { row, value: parsed, orig: parseInt(row.dataset.originalIndex) };
            });

            decorated.sort((A, B) => {
                if (type === 'number') {
                    if (A.value !== B.value) return direction === 'asc' ? A.value - B.value : B.value - A.value;
                } else {
                    const cmp = A.value.localeCompare(B.value, 'de', { numeric: true });
                    if (cmp !== 0) return direction === 'asc' ? cmp : -cmp;
                }
                return A.orig - B.orig;
            });

            rows = decorated.map(d => d.row);
            rows.forEach(r => tbody.appendChild(r));

            headers.forEach(h => h.classList.remove('sort-asc', 'sort-desc'));
            headers[colIndex].classList.add(direction === 'asc' ? 'sort-asc' : 'sort-desc');

            if (persist) {
                uiState.sortCol = colIndex;
                uiState.sortDir = direction;
                saveUIState();
            }
        }

        headers.forEach((th, idx) => {
            th.addEventListener('click', () => {
                if(th.classList.contains('no-sort')) return;
                const type = th.dataset.type || 'string';
                const asc = th.classList.contains('sort-asc');
                sortByColumn(idx, type, asc ? 'desc' : 'asc');
            });
        });

        function toggleIgnore(sku) {
            if (ignoredSkus.includes(sku)) {
                ignoredSkus = ignoredSkus.filter(s => s !== sku);
            } else {
                ignoredSkus.push(sku);
            }
            localStorage.setItem(IGNORE_KEY, JSON.stringify(ignoredSkus));
            applyFilters();
        }

        function applyFilters() {
            saveUIState();
            
            const searchTerm = searchInput.value.toLowerCase();
            const showIgnored = toggleIgnoredCheckbox.checked;
            const showLocal = toggleLocalCheckbox.checked;
            const rwFilter = uiRunwaySelect.value;
            let visibleCount = 0;

            document.querySelectorAll('.col-local').forEach(el => {
                el.style.display = showLocal ? '' : 'none';
            });

            rows.forEach(row => {
                let show = true;
                const sku = row.dataset.sku;
                const text = row.textContent.toLowerCase();
                const runwayDays = parseFloat(row.dataset.runway);
                const isIgnored = ignoredSkus.includes(sku);

                if (searchTerm && !text.includes(searchTerm)) show = false;

                // JS Reichweiten-Filter anwenden
                if (rwFilter === 'out' && runwayDays > 0) show = false;
                if (rwFilter === 'under_30' && runwayDays > 30) show = false; // NEUER FILTER 0-30 Tage
                if (rwFilter === 'critical' && (runwayDays === 0 || runwayDays > 13)) show = false;
                if (rwFilter === 'low' && (runwayDays < 14 || runwayDays > 30)) show = false;
                if (rwFilter === 'good' && runwayDays <= 30) show = false;

                const btn = row.querySelector('.ignore-btn');
                if (isIgnored) {
                    row.classList.add('sku-ignored');
                    if(btn) { btn.title = 'Wieder einblenden'; btn.innerHTML = iconEyeOn; }
                    if (!showIgnored) show = false;
                } else {
                    row.classList.remove('sku-ignored');
                    if(btn) { btn.title = 'Ausblenden'; btn.innerHTML = iconEyeOff; }
                }

                if (show) {
                    row.classList.remove('hidden-row');
                    visibleCount++;
                } else {
                    row.classList.add('hidden-row');
                }
            });

            countDisplay.textContent = visibleCount;
        }

        document.addEventListener('DOMContentLoaded', () => {
            toggleIgnoredCheckbox.checked = uiState.showIgnored;
            toggleLocalCheckbox.checked = uiState.showLocal;
            uiRunwaySelect.value = uiState.runwayFilter;

            searchInput.addEventListener('keyup', applyFilters);
            uiRunwaySelect.addEventListener('change', applyFilters);
            toggleIgnoredCheckbox.addEventListener('change', applyFilters);
            toggleLocalCheckbox.addEventListener('change', applyFilters);

            if (headers[uiState.sortCol]) {
                sortByColumn(uiState.sortCol, headers[uiState.sortCol].dataset.type, uiState.sortDir, false);
            }
            applyFilters();
            
            document.querySelectorAll('#php-filter-form select').forEach(el => {
                if(el.id === 'uiRunway') return; 
                el.addEventListener('change', () => {
                    const formData = new FormData(document.getElementById('php-filter-form'));
                    const params = new URLSearchParams(formData).toString();
                    localStorage.setItem(PHP_PARAMS_KEY, '?' + params);
                    document.getElementById('php-filter-form').submit();
                });
            });
        });
    </script>
</body>
</html>