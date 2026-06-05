<?php
header('Content-Type: text/html; charset=utf-8');
require_once APP_ROOT . '/src/Services/sp_api_functions.php';
require_once APP_ROOT . '/config/db_connection.php';
require_once APP_ROOT . '/src/Services/ManoManoFeedBuilder.php';
require_once APP_ROOT . '/src/Services/AmazonFeedBuilder.php';

// Initial state is empty.
$sku_to_search = $_GET['sku'] ?? ''; 
$time_period = $_GET['time_period'] ?? '7';
$source = $_GET['source'] ?? 'all';

/**
 * Try multiple strategies to obtain a Unix timestamp (seconds).
 */
function normalizeToTimestamp($raw): int {
  $raw = trim((string)$raw);
  if ($raw === '') return 0;
  $candidate = preg_replace('/\s*-\s*/', ' ', $raw);
  $formats = [
    'Y-m-d H:i:s','Y-m-d H:i','Y-m-d',
    'Y-m-d\TH:i:s','d.m.Y H:i:s','d.m.Y H:i',
    'd-m-Y H:i:s','d/m/Y H:i:s','m/d/Y H:i:s'
  ];
  foreach ($formats as $fmt) {
    $dt = DateTime::createFromFormat($fmt, $candidate);
    if ($dt !== false) return $dt->getTimestamp();
  }
  $ts = strtotime($candidate);
  if ($ts !== false) return (int)$ts;
  if (preg_match('/(\d{4}-\d{2}-\d{2}).*?(\d{2}:\d{2}(?::\d{2})?)/', $raw, $m)) {
    $s = $m[1] . ' ' . $m[2];
    $ts = strtotime($s);
    if ($ts !== false) return (int)$ts;
  }
  $ts = strtotime($raw);
  return $ts !== false ? (int)$ts : 0;
}

function getProductData(PDO $dbConnection, string $sku, int $days, string $source): ?array {
  $date_start = (new DateTime())->modify("-{$days} days")->format('Y-m-d');
  $source_clause = ($source === 'amazon') ? 'AND T2.werbekennzeichen IN (2,8)' : '';
  try {
    $stmt_product_id = $dbConnection->prepare("
      SELECT produktid FROM produkte_felder_werte
      WHERE feldid = '44' AND wert1 = :sku LIMIT 1
    ");
    $stmt_product_id->execute([':sku' => $sku]);
    $product_id = $stmt_product_id->fetchColumn();
    if (!$product_id) return null;

    $stmt_sales_summary = $dbConnection->prepare("
      SELECT SUM(T1.einzelpreis * T1.anzahl) AS total_revenue_pre_vat,
          SUM(T1.anzahl) AS total_quantity
      FROM bestellungen_positionen AS T1
      JOIN bestellungen AS T2 ON T2.id = T1.bestellungsid
      WHERE T1.datum > :date_start
       AND T1.produktid = :product_id
       {$source_clause}
    ");
    $stmt_sales_summary->execute([':date_start'=>$date_start, ':product_id'=>$product_id]);
    $sales_summary = $stmt_sales_summary->fetch(PDO::FETCH_ASSOC);

    $stmt_recent_orders = $dbConnection->prepare("
      SELECT T1.einzelpreis, T2.id AS bestellungsid, 
          T3.titel AS werbekennzeichen, T2.datum AS datum
      FROM bestellungen_positionen AS T1
      JOIN bestellungen AS T2 ON T2.id = T1.bestellungsid
      JOIN bestellungen_werbekennzeichen AS T3 ON T2.werbekennzeichen = T3.id
      WHERE T1.produktid = :product_id AND T2.datum > :date_start
       {$source_clause}
      ORDER BY T2.datum DESC
      LIMIT 500;
    ");
    $stmt_recent_orders->execute([':product_id'=>$product_id, ':date_start'=>$date_start]);
    $recent_orders = $stmt_recent_orders->fetchAll(PDO::FETCH_ASSOC);

    $stmt_asin = $dbConnection->prepare("
      SELECT wert1 FROM produkte_felder_werte
      WHERE produktid = :product_id AND feldid = '57'
    ");
    $stmt_asin->execute([':product_id'=>$product_id]);
    $asin = $stmt_asin->fetchColumn();

    return [
      'sku' => $sku,
      'product_id' => $product_id,
      'sales_summary' => $sales_summary,
      'recent_orders' => $recent_orders,
      'asin' => $asin,
      'days' => $days,
      'source' => $source
    ];
  } catch (PDOException $e) {
    return null;
  }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Produktdaten-Bericht</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #0f172a;
            --muted: #64748b;
            --muted-light: #94a3b8;
            --primary: #2563eb;
            --primary-dark: #1e40af;
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
            max-width: 1200px;
            margin: 0 auto;
        }

        /* --- Hero Section --- */
        .hero {
            display: flex;
            flex-wrap: wrap;
            gap: 32px;
            align-items: center;
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
        }

        .hero-text .subtitle {
            color: var(--muted);
            font-size: 1rem;
            max-width: 500px;
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

        .panel h2 {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--ink);
        }

        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
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

        input[type="text"],
        select {
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

        input:hover, select:hover { border-color: var(--muted-light); }
        input:focus, select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--ring);
        }

        .filter-actions {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--stroke);
        }

        button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 42px;
            padding: 0 24px;
            border-radius: var(--radius-sm);
            border: none;
            background: var(--primary);
            color: white;
            font-family: inherit;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
        }

        button:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }

        /* --- Stats Overview --- */
        .hero-stats {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--stroke);
            border-radius: var(--radius);
            padding: 16px 20px;
            box-shadow: var(--shadow-sm);
            flex: 1;
            min-width: 200px;
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
            color: var(--ink);
        }

        /* --- Product Meta Card --- */
        .product-meta {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .product-meta img {
            width: 100px;
            height: auto;
            border-radius: var(--radius-sm);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--stroke);
        }

        .product-meta .meta-info h2 {
            margin-bottom: 6px;
            font-size: 1.25rem;
        }

        .product-meta .meta-info p {
            color: var(--muted);
            font-size: 0.95rem;
            margin-bottom: 4px;
            font-weight: 500;
        }

        .product-meta .meta-info strong {
            color: var(--ink);
        }

        /* --- Alerts & Helpers --- */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            margin-bottom: 20px;
            font-size: 0.95rem;
            font-weight: 500;
        }
        
        .alert.error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        .empty {
            padding: 48px 24px;
            text-align: center;
            color: var(--muted);
            background: var(--surface);
            border-radius: var(--radius);
            border: 1px dashed var(--muted-light);
            font-size: 1.05rem;
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
            font-variant-numeric: tabular-nums;
            color: var(--ink);
            vertical-align: middle;
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
        }

        .badge-gray { background: #f1f5f9; color: #475569; border: 1px solid var(--stroke); }
        .badge-blue { background: #e0f2fe; color: #075985; border: 1px solid #bae6fd; }

        /* --- Responsive --- */
        @media (max-width: 768px) {
            body { padding: 24px 12px 60px; }
            
            /* Hero area & Stats */
            .hero { flex-direction: column; align-items: stretch; gap: 20px; }
            .hero-text h1 { font-size: 1.8rem; }
            .hero-stats { flex-direction: column; width: 100%; gap: 12px; }
            .stat-card { width: 100%; flex: none; }
            
            /* Panels and inputs */
            .panel { padding: 16px; margin-bottom: 16px; }
            .filter-grid { grid-template-columns: 1fr; gap: 12px; }
            input[type="text"], input[type="date"], select { height: 50px; font-size: 1rem; }
            
            /* Buttons */
            .filter-actions { flex-direction: column; align-items: stretch; gap: 12px; }
            button, .btn-primary, .btn-ghost { width: 100%; height: 50px; justify-content: center; }
            
            /* Charts edge-to-edge */
            .chart-wrap, .chart-container { 
                height: 300px; 
                padding: 12px; 
                margin: 0 -12px 24px -12px; 
                border-radius: 0; 
                border-left: none; 
                border-right: none; 
            }

            /* Table edge-to-edge */
            .table-wrap { 
                margin: 0 -12px; 
                border-radius: 0; 
                border-left: none; 
                border-right: none; 
            }
            th, td { padding: 12px 16px; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/global_header.php'; ?>
    <div class="page">
        
        <div class="hero">
            <div class="hero-text">
                <p class="eyebrow">Analytics</p>
                <h1>Produktdaten-Bericht</h1>
                <p class="subtitle">Detailauswertung von Verkäufen nach SKU und Zeitraum.</p>
            </div>
        </div>

        <div class="panel">
            <h2>Filter & Suche</h2>
            <form method="GET" action="" id="report-form">
                <div class="filter-grid">
                    <div class="field">
                        <label for="sku">SKU</label>
                        <input id="sku" name="sku" type="text" placeholder="z. B. 10-2-14-441" value="<?= htmlspecialchars($sku_to_search, ENT_QUOTES) ?>">
                    </div>
                    <div class="field">
                        <label for="time_period">Zeitraum</label>
                        <select id="time_period" name="time_period">
                            <option value="7"  <?= ($time_period == '7') ? 'selected' : '' ?>>Letzte 7 Tage</option>
                            <option value="30" <?= ($time_period == '30') ? 'selected' : '' ?>>Letzte 30 Tage</option>
                            <option value="90" <?= ($time_period == '90') ? 'selected' : '' ?>>Letzte 90 Tage</option>
                            <option value="365"<?= ($time_period == '365') ? 'selected' : '' ?>>Letzte 365 Tage</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="source">Verkaufsquelle</label>
                        <select id="source" name="source">
                            <option value="all" <?= ($source == 'all') ? 'selected' : '' ?>>Alle Verkäufe</option>
                            <option value="amazon" <?= ($source == 'amazon') ? 'selected' : '' ?>>Nur Amazon</option>
                        </select>
                    </div>
                </div>
                <div class="filter-actions">
                    <button type="submit">Bericht abrufen</button>
                </div>
            </form>
        </div>

        <?php
        // Logic-Gate: Execute only if an SKU is entered explicitly
        if (empty($sku_to_search)) {
            echo '<div class="empty">Bitte geben Sie eine SKU ein, um den Bericht zu laden.</div>';
        } else {
            if ($dbConnectionTric instanceof PDO) {
                $data = getProductData($dbConnectionTric, $sku_to_search, (int)$time_period, $source);
                
                if ($data) {
                    // External API Call
                    $productMeta = null;
                    if (!empty($data['asin'])) {
                        $productMeta = getProductTitleAndImage($data['asin']);
                    }

                    if ($productMeta) {
                        echo '<div class="panel product-meta">';
                        if ($productMeta['image']) {
                            echo '<img src="' . htmlspecialchars($productMeta['image'], ENT_QUOTES) . '" alt="Product Image">';
                        }
                        echo '<div class="meta-info">';
                        echo '<h2>' . htmlspecialchars($productMeta['title'], ENT_QUOTES) . '</h2>';
                        echo '<p>ASIN: <strong>' . htmlspecialchars($data['asin'], ENT_QUOTES) . '</strong></p>';
                        echo '<p>Produkt-ID: <strong>' . htmlspecialchars((string)$data['product_id'], ENT_QUOTES) . '</strong></p>';
                        echo '<p>SKU: <strong>' . htmlspecialchars($data['sku'], ENT_QUOTES) . '</strong></p>';
                        echo '</div></div>';
                    }

                    $source_title = ($source === 'amazon') ? 'Amazon' : 'Alle';
                    $summary = $data['sales_summary'];

                    if ($summary && ($summary['total_quantity'] ?? 0) > 0) {
                        $rev_vat = ($summary['total_revenue_pre_vat'] ?? 0) * 1.19;
                        $avg_price = $rev_vat / max(1, (int)$summary['total_quantity']);
                        
                        echo '<div class="hero-stats">';
                        echo '<div class="stat-card"><div class="stat-label">Verkaufte Menge</div><div class="stat-value">' . (int)$summary['total_quantity'] . '</div></div>';
                        echo '<div class="stat-card"><div class="stat-label">Umsatz (' . $source_title . ')</div><div class="stat-value">' . number_format($rev_vat, 2, ',', '.') . ' €</div></div>';
                        echo '<div class="stat-card"><div class="stat-label">Ø Preis (inkl. MwSt.)</div><div class="stat-value">' . number_format($avg_price, 2, ',', '.') . ' €</div></div>';
                        echo '</div>';
                    } else {
                        echo '<div class="empty">Keine Verkaufsdaten für den ausgewählten Zeitraum und die Quelle verfügbar.</div>';
                    }

                    // Orders Table Section
                    if (!empty($data['recent_orders'])) {
                        echo '<div class="panel" style="margin-top: 24px;">';
                        echo '<div style="display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center; margin-bottom: 20px;">';
                        echo '<h2 style="margin: 0;">Letzte ' . $source_title . '-Verkäufe</h2>';
                        
                        echo '<div class="field" style="flex-direction: row; align-items: center; gap: 12px;">';
                        echo '<label for="rowLimit" style="margin: 0;">Zeilen anzeigen</label>';
                        echo '<select id="rowLimit" style="width: auto; height: 36px; padding: 0 32px 0 12px;">';
                        echo '<option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option>';
                        echo '</select>';
                        echo '</div>';
                        echo '</div>';

                        echo '<div class="table-wrap">';
                        echo '<table id="ordersTable"><thead><tr>';
                        echo '<th data-type="string">Bestell-ID</th>';
                        echo '<th data-type="number">Preis (inkl. MwSt.)</th>';
                        echo '<th data-type="string">Plattform</th>';
                        echo '<th data-type="date">Datum</th>';
                        echo '</tr></thead><tbody>';

                        foreach ($data['recent_orders'] as $i => $order) {
                            $orderId = $order['bestellungsid'] ?? '';
                            $price_val = (float) ($order['einzelpreis'] ?? 0.0) * 1.19;
                            $price_sort = number_format($price_val, 2, '.', '');
                            $price_display = number_format($price_val, 2, ',', '.');
                            $werb = $order['werbekennzeichen'] ?? '';
                            $raw_date = $order['datum'] ?? '';
                            $timestamp = normalizeToTimestamp($raw_date);
                            $date_display = $timestamp ? date('Y-m-d H:i:s', $timestamp) : htmlspecialchars((string)$raw_date, ENT_QUOTES);

                            echo '<tr data-original-index="' . (int)$i . '">';
                            echo '<td data-sort="' . htmlspecialchars((string)$orderId, ENT_QUOTES) . '" style="font-weight: 500;">' . htmlspecialchars((string)$orderId, ENT_QUOTES) . '</td>';
                            echo '<td data-sort="' . $price_sort . '"><span class="badge badge-blue">' . $price_display . ' €</span></td>';
                            echo '<td data-sort="' . htmlspecialchars((string)$werb, ENT_QUOTES) . '"><span class="badge badge-gray">' . htmlspecialchars((string)$werb, ENT_QUOTES) . '</span></td>';
                            echo '<td data-sort="' . (int)$timestamp . '">' . htmlspecialchars($date_display, ENT_QUOTES) . '</td>';
                            echo '</tr>';
                        }

                        echo '</tbody></table></div></div>';
                    } elseif ($summary && ($summary['total_quantity'] ?? 0) > 0) {
                        echo '<div class="empty">Keine aktuellen Bestelldaten für dieses Produkt verfügbar.</div>';
                    }

                } else {
                    echo '<div class="alert error">❌ Produkt nicht gefunden oder keine Daten verfügbar.</div>';
                }
            } else {
                echo '<div class="alert error">❌ Datenbankverbindung konnte nicht hergestellt werden.</div>';
            }
        }
        ?>
    </div>

    <script>
    (function () {
        const STORAGE_KEY = 'orders_table_state_v3'; // Incremented key just in case

        document.addEventListener('DOMContentLoaded', () => {
            const table = document.getElementById('ordersTable');
            if (!table) return;

            const tbody = table.tBodies[0];
            let rows = Array.from(tbody.querySelectorAll('tr'));
            const headers = Array.from(table.tHead.querySelectorAll('th'));
            const rowLimitSelect = document.getElementById('rowLimit');

            rows.forEach((r, i) => {
                if (!r.dataset.originalIndex) r.dataset.originalIndex = i;
            });

            const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
            if (saved.rowLimit) {
                if (Array.from(rowLimitSelect.options).some(o => o.value === String(saved.rowLimit))) {
                    rowLimitSelect.value = String(saved.rowLimit);
                }
            }

            function getCellSortValue(row, colIndex) {
                const cell = row.children[colIndex];
                if (!cell) return '';
                if (cell.dataset && cell.dataset.sort !== undefined) return cell.dataset.sort;
                return cell.innerText.trim();
            }

            function parseForType(raw, type) {
                if (type === 'number') {
                    const n = parseFloat(String(raw).replace(',', '.'));
                    return Number.isFinite(n) ? n : Number.NEGATIVE_INFINITY;
                }
                if (type === 'date') {
                    const n = Number(raw);
                    if (Number.isFinite(n) && n > 0) return n;
                    const normalized = String(raw).replace(/\s*-\s*/, ' ');
                    const parsedMs = Date.parse(normalized);
                    if (!isNaN(parsedMs)) return Math.floor(parsedMs / 1000);
                    return 0;
                }
                return String(raw).toLowerCase();
            }

            function compareValues(a, b, type) {
                if (type === 'number' || type === 'date') return a - b;
                return a.localeCompare(b, undefined, {numeric:true, sensitivity:'base'});
            }

            function clearSortIndicators() {
                headers.forEach(h => { h.classList.remove('sort-asc', 'sort-desc'); });
            }

            function sortByColumn(colIndex, type, direction = 'asc', persist = true) {
                const decorated = rows.map((row, idx) => {
                    const raw = getCellSortValue(row, colIndex);
                    const parsed = parseForType(raw, type);
                    const orig = parseInt(row.dataset.originalIndex || idx, 10);
                    return {row, value: parsed, orig};
                });

                decorated.sort((A, B) => {
                    const cmp = compareValues(A.value, B.value, type);
                    if (cmp !== 0) return direction === 'asc' ? cmp : -cmp;
                    return A.orig - B.orig;
                });

                rows = decorated.map(d => d.row);
                rows.forEach(r => tbody.appendChild(r));

                clearSortIndicators();
                headers[colIndex].classList.add(direction === 'asc' ? 'sort-asc' : 'sort-desc');

                if (persist) {
                    const toSave = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
                    toSave.lastSort = {colIndex, direction};
                    toSave.rowLimit = parseInt(rowLimitSelect.value, 10) || 10;
                    localStorage.setItem(STORAGE_KEY, JSON.stringify(toSave));
                }

                applyRowLimit();
            }

            headers.forEach((th, idx) => {
                const type = th.dataset.type || 'string';
                th.tabIndex = 0;
                th.addEventListener('click', () => {
                    const asc = th.classList.contains('sort-asc');
                    const newDir = asc ? 'desc' : 'asc';
                    sortByColumn(idx, type, newDir);
                });
                th.addEventListener('keydown', (ev) => {
                    if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); th.click(); }
                });
            });

            function applyRowLimit() {
                const limit = parseInt(rowLimitSelect.value, 10) || 10;
                rows.forEach((r, i) => r.style.display = i < limit ? '' : 'none');
                const toSave = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
                toSave.rowLimit = limit;
                localStorage.setItem(STORAGE_KEY, JSON.stringify(toSave));
            }

            if (rowLimitSelect) {
                rowLimitSelect.addEventListener('change', applyRowLimit);
            }

            if (saved.lastSort && Number.isInteger(saved.lastSort.colIndex)) {
                const idx = Number(saved.lastSort.colIndex);
                const dir = saved.lastSort.direction === 'desc' ? 'desc' : 'asc';
                const th = headers[idx];
                const type = (th && th.dataset.type) ? th.dataset.type : 'string';
                sortByColumn(idx, type, dir, false);
            } else {
                applyRowLimit();
            }
        });
    })();
    </script>
</body>
</html>