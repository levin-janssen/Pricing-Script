<?php
header('Content-Type: text/html; charset=utf-8');
require_once APP_ROOT . '/src/Services/sp_api_functions.php';
require_once APP_ROOT . '/config/db_connection.php';
require_once APP_ROOT . '/src/Services/ManoManoFeedBuilder.php';
require_once APP_ROOT . '/src/Services/AmazonFeedBuilder.php';

$sku_to_search = $_GET['sku'] ?? '10-2-14-441'; 
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
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Produktdaten-Bericht</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;500;700&display=swap" rel="stylesheet">
<style>
:root{
 --primary:#2563eb; --primary-dark:#1e40af; --bg:#f9fafb; --text:#111827;
 --muted:#6b7280; --card:#fff; --radius:12px;
}
*{box-sizing:border-box}
body{font-family:Inter,system-ui,-apple-system,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;margin:0;background:var(--bg);color:var(--text);padding:24px}
.container{max-width:1100px;margin:0 auto}
h1{text-align:center;color:var(--primary-dark);font-weight:600;margin-bottom:20px}
.control-panel{display:flex;flex-wrap:wrap;gap:12px;background:#f3f4f6;padding:16px;border-radius:12px;margin-bottom:20px}
.form-group{min-width:160px;flex:1;display:flex;flex-direction:column}
label{font-size:13px;color:var(--muted);margin-bottom:6px;font-weight:500}
input,select{padding:8px 10px;border-radius:8px;border:1px solid #d1d5db;background:#fff;font-size:14px}
button{padding:10px 14px;border-radius:8px;border:none;background:var(--primary);color:white;font-weight:700;cursor:pointer}
button:hover{background:var(--primary-dark)}

.card{background:var(--card);padding:16px;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.04);margin-bottom:16px}
.summary-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px}
.summary-item{background:#f3f4f6;padding:12px;border-radius:8px;text-align:center}
.summary-item .value{font-size:1.15rem;font-weight:700;margin-top:6px}

.table-controls{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:8px;flex-wrap:wrap}
.table-controls .left{display:flex;align-items:center;gap:10px}
.table-controls label{margin:0;font-size:14px;color:var(--muted)}
.table-controls select{width:auto}

table{width:100%;border-collapse:collapse;background:transparent}
thead th{background:#f8fafc;color:var(--muted);font-weight:600;padding:10px;border-bottom:1px solid #e6edf3;text-align:left;cursor:pointer;user-select:none}
tbody td{padding:10px;border-bottom:1px solid #eef3f7}
th.sort-asc::after{content:' ▲';font-size:11px;color:var(--muted)}
th.sort-desc::after{content:' ▼';font-size:11px;color:var(--muted)}
.no-data{padding:14px;background:#fff4f4;border-radius:8px;color:#7b1111}
@media (max-width:700px){.control-panel{flex-direction:column}.table-controls{flex-direction:column;align-items:flex-start}}
</style>
</head>
<body>
    <?php include __DIR__ . '/global_header.php'; ?>
<div class="container">
 <h1>📊 Produktdaten-Bericht</h1>

 <div class="control-panel">
  <form method="GET" action="" style="display:flex;flex:1;gap:12px;flex-wrap:wrap">
   <div class="form-group">
    <label for="sku">SKU</label>
    <input id="sku" name="sku" type="text" value="<?php echo htmlspecialchars($sku_to_search, ENT_QUOTES); ?>">
   </div>
   <div class="form-group">
    <label for="time_period">Zeitraum</label>
    <select id="time_period" name="time_period">
     <option value="7"  <?php echo ($time_period=='7') ? 'selected' : ''; ?>>Letzte 7 Tage</option>
     <option value="30" <?php echo ($time_period=='30')? 'selected' : ''; ?>>Letzte 30 Tage</option>
     <option value="90" <?php echo ($time_period=='90')? 'selected' : ''; ?>>Letzte 90 Tage</option>
     <option value="365"<?php echo ($time_period=='365')? 'selected' : ''; ?>>Letzte 365 Tage</option>
    </select>
   </div>
   <div class="form-group">
    <label for="source">Verkaufsquelle</label>
    <select id="source" name="source">
     <option value="all" <?php echo ($source=='all')? 'selected' : ''; ?>>Alle Verkäufe</option>
     <option value="amazon" <?php echo ($source=='amazon')? 'selected' : ''; ?>>Nur Amazon</option>
    </select>
   </div>
   <div style="display:flex;align-items:flex-end">
    <button type="submit">Bericht aktualisieren</button>
   </div>
  </form>
 </div>

<?php
if ($dbConnectionTric instanceof PDO) {
  $data = getProductData($dbConnectionTric, $sku_to_search, (int)$time_period, $source);
  if ($data) {
    // 🔹 NEW: Try to fetch title + image if ASIN available
    $productMeta = null;
    if (!empty($data['asin'])) {
      $productMeta = getProductTitleAndImage($data['asin']);
    }
    if ($productMeta) {
      echo '<div class="card" style="display:flex;align-items:center;gap:16px">';
      if ($productMeta['image']) {
        echo '<img src="' . htmlspecialchars($productMeta['image'], ENT_QUOTES) . '" alt="Product Image" style="max-width:120px;border-radius:8px">';
      }
      echo '<div>';
      echo '<h2 style="margin:0 0 6px 0">' . htmlspecialchars($productMeta['title'], ENT_QUOTES) . '</h2>';
      echo '<p style="margin:0;color:#666">ASIN: ' . htmlspecialchars($data['asin'], ENT_QUOTES) . '</p>';
      echo '<p style="margin:0;color:#666"> Produkt-ID: ' . htmlspecialchars((string)$data['product_id'], ENT_QUOTES) . '</p>';
      echo '<p style="margin:0;color:#666">SKU: ' . htmlspecialchars($data['sku'], ENT_QUOTES) . '</p>';
      echo '</div></div>';
    }

    $source_title = ($source === 'amazon') ? 'Amazon' : 'Alle';
    $summary = $data['sales_summary'];

    echo '<div class="card">';
    echo "<h2 style='margin:0 0 8px 0'>{$source_title}-Verkaufsübersicht (Letzte {$data['days']} Tage)</h2>";
    if ($summary && ($summary['total_quantity'] ?? 0) > 0) {
      $rev_vat = ($summary['total_revenue_pre_vat'] ?? 0) * 1.19;
      $avg_price = $rev_vat / max(1, (int)$summary['total_quantity']);
      echo '<div class="summary-grid">';
      echo '<div class="summary-item"><div class="label">Verkaufte Menge</div><div class="value">' . (int)$summary['total_quantity'] . '</div></div>';
      echo '<div class="summary-item"><div class="label">Umsatz (inkl. MwSt.)</div><div class="value">' . number_format($rev_vat, 2, ',', '.') . ' €</div></div>';
      echo '<div class="summary-item"><div class="label">Durchschnittspreis (inkl. MwSt.)</div><div class="value">' . number_format($avg_price, 2, ',', '.') . ' €</div></div>';
      echo '</div>';
    } else {
      echo '<p class="no-data">Keine Verkaufsdaten für den ausgewählten Zeitraum/die Quelle verfügbar.</p>';
    }
    echo '</div>';

    // Orders table
    echo '<div class="card">';
    echo "<h2 style='margin:0 0 8px 0'>Letzte {$source_title}-Verkäufe</h2>";

    if (!empty($data['recent_orders'])) {
      echo '<div class="table-controls">';
      echo '<div class="left"><label for="rowLimit">Zeilen anzeigen</label>';
      echo '<select id="rowLimit" aria-label="Zeilen anzeigen">';
      echo '<option value="10">10</option>';
      echo '<option value="25">25</option>';
      echo '<option value="50">50</option>';
      echo '<option value="100">100</option>';
      echo '</select></div>';
      echo '<div class="right" style="color:var(--muted);font-size:13px">Klicken Sie auf eine Spaltenüberschrift, um zu sortieren (aufsteigend/absteigend)</div>';
      echo '</div>';

      echo '<table id="ordersTable" aria-describedby="orders-desc"><thead><tr>';
      // Types: Order ID -> string, Price -> number, Werb -> string, Date -> date
      echo '<th data-type="string">Bestell-ID</th>';
      echo '<th data-type="number">Preis (inkl. MwSt.)</th>';
      echo '<th data-type="string">Plattform</th>';
      echo '<th data-type="date">Datum</th>';
      echo '</tr></thead><tbody>';

      foreach ($data['recent_orders'] as $i => $order) {
        $orderId = $order['bestellungsid'] ?? '';

        // Price including VAT
        $price_val = (float) ($order['einzelpreis'] ?? 0.0) * 1.19;
        $price_sort = number_format($price_val, 2, '.', ''); // canonical
        $price_display = number_format($price_val, 2, ',', '.');

        // Werbekennzeichen
        $werb = $order['werbekennzeichen'] ?? '';

        // Date parsing: robust normalization
        $raw_date = $order['datum'] ?? '';
        $timestamp = normalizeToTimestamp($raw_date);
        // display: use original raw if parsing failed; else show standardized Y-m-d H:i:s
        $date_display = $timestamp ? date('Y-m-d H:i:s', $timestamp) : htmlspecialchars((string)$raw_date, ENT_QUOTES);

        // Output row with data-sort attributes for each cell
        echo '<tr data-original-index="' . (int)$i . '">';
        // Order ID (string)
        echo '<td data-sort="' . htmlspecialchars((string)$orderId, ENT_QUOTES) . '">' . htmlspecialchars((string)$orderId, ENT_QUOTES) . '</td>';
        // Price (number canonical)
        echo '<td data-sort="' . $price_sort . '">' . $price_display . ' €</td>';
        // Werbekennzeichen (string)
        echo '<td data-sort="' . htmlspecialchars((string)$werb, ENT_QUOTES) . '">' . htmlspecialchars((string)$werb, ENT_QUOTES) . '</td>';
        // Date (timestamp numeric)
        echo '<td data-sort="' . (int)$timestamp . '">' . htmlspecialchars($date_display, ENT_QUOTES) . '</td>';
        echo '</tr>';
      }

      echo '</tbody></table>';
    } else {
      echo '<p class="no-data">Keine aktuellen Bestelldaten für dieses Produkt verfügbar.</p>';
    }

    echo '</div>'; // card end
  } else {
    echo '<p class="no-data">❌ Produkt nicht gefunden oder keine Daten verfügbar.</p>';
  }
} else {
  echo '<p class="no-data">❌ Datenbankverbindung konnte nicht hergestellt werden.</p>';
}
?>
</div>

<script>
(function () {
 const STORAGE_KEY = 'orders_table_state_v2';

 document.addEventListener('DOMContentLoaded', () => {
  const table = document.getElementById('ordersTable');
  if (!table) return;

  const tbody = table.tBodies[0];
  let rows = Array.from(tbody.querySelectorAll('tr'));
  const headers = Array.from(table.tHead.querySelectorAll('th'));
  const rowLimitSelect = document.getElementById('rowLimit');

  // Ensure original index exists for stable sort fallback
  rows.forEach((r, i) => {
   if (!r.dataset.originalIndex) r.dataset.originalIndex = i;
  });

  // Load persisted state
  const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
  if (saved.rowLimit) {
   if (Array.from(rowLimitSelect.options).some(o => o.value === String(saved.rowLimit))) {
    rowLimitSelect.value = String(saved.rowLimit);
   }
  }

  // Read canonical value from cell (prefer data-sort)
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
    // raw is expected to be a Unix timestamp in seconds (canonical)
    const n = Number(raw);
    if (Number.isFinite(n) && n > 0) return n;
    // fallback: try parsing as a date string (accepts "2025-09-09 - 22:53:14" too)
    const normalized = String(raw).replace(/\s*-\s*/, ' ');
    const parsedMs = Date.parse(normalized);
    if (!isNaN(parsedMs)) return Math.floor(parsedMs / 1000);
    // last fallback
    return 0;
   }
   // string
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
   // decorate
   const decorated = rows.map((row, idx) => {
    const raw = getCellSortValue(row, colIndex);
    const parsed = parseForType(raw, type);
    const orig = parseInt(row.dataset.originalIndex || idx, 10);
    return {row, value: parsed, orig};
   });

   decorated.sort((A, B) => {
    const cmp = compareValues(A.value, B.value, type);
    if (cmp !== 0) return direction === 'asc' ? cmp : -cmp;
    return A.orig - B.orig; // stable tie-break
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

  rowLimitSelect.addEventListener('change', applyRowLimit);

  // Apply saved sort if present
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