<?php

declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');

date_default_timezone_set('Europe/Berlin');

$logDir = APP_ROOT . '/logs';
$rawDate = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
$selectedDate = '';
$dateWarning = '';
$filterAsin = isset($_GET['asin']) ? trim((string)$_GET['asin']) : '';
$filterSku = isset($_GET['sku']) ? trim((string)$_GET['sku']) : '';
$rawMinAbsDiff = isset($_GET['min_abs_diff']) ? trim((string)$_GET['min_abs_diff']) : '';
$filterDirection = isset($_GET['direction']) ? trim((string)$_GET['direction']) : 'any';
$filterUnique = isset($_GET['unique']) && $_GET['unique'] === '1';
$filterExactDuplicates = isset($_GET['exact_duplicates']) && $_GET['exact_duplicates'] === '1'; // NEUER FILTER
$minAbsDiff = null;

if ($rawDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
    $selectedDate = $rawDate;
} else {
    $selectedDate = date('Y-m-d');
    if ($rawDate !== '') {
        $dateWarning = 'Ungueltiges Datum. Format: YYYY-MM-DD.';
    }
}

if ($rawMinAbsDiff !== '' && is_numeric($rawMinAbsDiff)) {
    $minAbsDiff = (float)$rawMinAbsDiff;
}

if (!in_array($filterDirection, ['any', 'increase', 'decrease'], true)) {
    $filterDirection = 'any';
}

$availableDates = [];
if (is_dir($logDir)) {
    foreach (glob($logDir . '/app_*.log') as $file) {
        $base = basename($file);
        if (preg_match('/app_(\d{4}-\d{2}-\d{2})\.log$/', $base, $matches)) {
            $availableDates[] = $matches[1];
        }
    }
    rsort($availableDates);
}

$logFile = $logDir . '/app_' . $selectedDate . '.log';
$entries = [];
$error = '';

if (!is_dir($logDir)) {
    $error = 'Log-Verzeichnis nicht gefunden.';
} elseif (!is_file($logFile)) {
    $error = 'Keine Logdatei fuer ' . $selectedDate . ' gefunden.';
} else {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        $error = 'Logdatei konnte nicht gelesen werden.';
    } else {
        foreach ($lines as $line) {
            if (strpos($line, 'Bestandsabweichung festgestellt!') === false) {
                continue;
            }

            if (!preg_match('/^\[(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})\](?: \[[^\]]+\])* \[[A-Z]+\] .*? \| Context: (.+)$/', $line, $matches)) {
                continue;
            }

            $context = json_decode(trim($matches[3]), true);
            if (!is_array($context)) {
                $context = [];
            }

            $amazon = $context['amazon_bisher'] ?? null;
            $tricoma = $context['tricoma_neu'] ?? null;
            $diff = null;

            if (is_numeric($amazon) && is_numeric($tricoma)) {
                $diff = (float)$tricoma - (float)$amazon;
            }

            $entries[] = [
                'date' => $matches[1],
                'time' => $matches[2],
                'timestamp' => $matches[1] . ' ' . $matches[2],
                'sku' => $context['sku'] ?? '',
                'asin' => $context['asin'] ?? '',
                'amazon_bisher' => $amazon,
                'tricoma_neu' => $tricoma,
                'diff' => $diff,
            ];
        }
    }
}


$totalEntries = count($entries);
$filteredEntries = $entries;
$activeFilters = [];

if ($error === '') {
    usort($filteredEntries, static function (array $left, array $right): int {
        return strcmp($right['timestamp'], $left['timestamp']);
    });

    $filteredEntries = array_values(array_filter($filteredEntries, static function (array $entry) use ($filterAsin, $filterSku, $minAbsDiff, $filterDirection): bool {
        if ($filterAsin !== '' && stripos($entry['asin'], $filterAsin) === false) {
            return false;
        }
        if ($filterSku !== '' && stripos($entry['sku'], $filterSku) === false) {
            return false;
        }
        if ($minAbsDiff !== null) {
            if (!is_numeric($entry['diff']) || abs((float)$entry['diff']) < $minAbsDiff) {
                return false;
            }
        }
        if ($filterDirection === 'increase' && (!is_numeric($entry['diff']) || (float)$entry['diff'] <= 0)) {
            return false;
        }
        if ($filterDirection === 'decrease' && (!is_numeric($entry['diff']) || (float)$entry['diff'] >= 0)) {
            return false;
        }

        return true;
    }));

    // NEUER FILTER: Exakte Duplikate entfernen (älteste behalten)
    if ($filterExactDuplicates) {
        $oldestExact = [];
        foreach ($filteredEntries as $entry) {
            // Eindeutiger Key aus den Werten generieren
            $key = implode('|', [
                (string)$entry['sku'],
                (string)$entry['asin'],
                (string)$entry['amazon_bisher'],
                (string)$entry['tricoma_neu'],
                (string)$entry['diff']
            ]);

            // Wenn Key noch nicht existiert oder der aktuelle Eintrag älter ist (kleinerer Timestamp) -> Speichern
            if (!isset($oldestExact[$key]) || strcmp($entry['timestamp'], $oldestExact[$key]['timestamp']) < 0) {
                $oldestExact[$key] = $entry;
            }
        }
        $filteredEntries = array_values($oldestExact);
        
        // Zurück sortieren nach neusten zuerst
        usort($filteredEntries, static function (array $left, array $right): int {
            return strcmp($right['timestamp'], $left['timestamp']);
        });
    }

    if ($filterUnique) {
        $latestByAsin = [];
        $withoutAsin = [];
        foreach ($filteredEntries as $entry) {
            $key = $entry['asin'];
            if ($key === '') {
                $withoutAsin[] = $entry;
                continue;
            }
            if (!isset($latestByAsin[$key]) || $entry['timestamp'] > $latestByAsin[$key]['timestamp']) {
                $latestByAsin[$key] = $entry;
            }
        }
        $filteredEntries = array_values(array_merge($latestByAsin, $withoutAsin));
        usort($filteredEntries, static function (array $left, array $right): int {
            return strcmp($right['timestamp'], $left['timestamp']);
        });
    }
}

if ($filterAsin !== '') {
    $activeFilters[] = 'ASIN: ' . $filterAsin;
}
if ($filterSku !== '') {
    $activeFilters[] = 'SKU: ' . $filterSku;
}
if ($minAbsDiff !== null) {
    $activeFilters[] = 'Min. Diff: ' . formatValue($minAbsDiff);
}
if ($filterDirection === 'increase') {
    $activeFilters[] = 'Nur Erhoehung';
} elseif ($filterDirection === 'decrease') {
    $activeFilters[] = 'Nur Absenkung';
}
if ($filterExactDuplicates) {
    $activeFilters[] = 'Ohne exakte Duplikate';
}
if ($filterUnique) {
    $activeFilters[] = 'Nur neueste pro ASIN';
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formatValue($value): string
{
    if ($value === null || $value === '') {
        return '-';
    }

    if (is_float($value)) {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    return (string)$value;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bestandsabweichungen</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --ink: #0f172a;
            --muted: #64748b;
            --muted-light: #94a3b8;
            --accent: #ea580c;
            --surface: #ffffff;
            --surface-soft: #f8fafc;
            --stroke: #e2e8f0;
            --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            --radius: 12px;
            --radius-sm: 8px;
            --ring: rgba(234, 88, 12, 0.25);
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
            color: var(--accent);
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
            min-width: 160px;
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
        input[type="number"],
        input[type="date"],
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

        input:hover, select:hover {
            border-color: var(--muted-light);
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--ring);
        }

        .checkbox-wrapper {
            display: flex;
            align-items: center;
            height: 100%;
            padding-top: 26px; /* Aligns visually with inputs that have labels */
        }

        .checkbox-wrapper label {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 0.95rem;
            font-weight: 500;
            color: var(--ink);
            cursor: pointer;
        }

        input[type="checkbox"] {
            width: 18px;
            height: 18px;
            border-radius: 4px;
            border: 1px solid var(--stroke);
            cursor: pointer;
            accent-color: var(--accent);
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
            background: var(--ink);
            color: white;
            font-family: inherit;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
        }

        button:hover {
            background: #1e293b;
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }

        .ghost {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 42px;
            padding: 0 20px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--stroke);
            text-decoration: none;
            color: var(--ink);
            background: var(--surface);
            font-weight: 600;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }

        .ghost:hover {
            background: var(--surface-soft);
            border-color: var(--muted-light);
        }

        /* --- Alerts & Helpers --- */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            margin-top: 20px;
            font-size: 0.95rem;
            font-weight: 500;
            display: flex;
            align-items: center;
        }
        
        .alert.warning {
            background: #fffbeb;
            color: #b45309;
            border: 1px solid #fde68a;
        }

        .alert.error {
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
        }

        .dates {
            margin-top: 20px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            font-size: 0.9rem;
            color: var(--muted);
        }

        .dates a {
            text-decoration: none;
            padding: 6px 12px;
            border-radius: 999px;
            background: var(--surface-soft);
            color: var(--ink);
            border: 1px solid var(--stroke);
            transition: all 0.2s;
            font-weight: 500;
        }

        .dates a:hover {
            border-color: var(--muted-light);
            background: var(--surface);
        }

        .active-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            margin-bottom: 20px;
        }

        .chip {
            padding: 6px 12px;
            border-radius: 999px;
            background: #f1f5f9;
            color: #475569;
            font-size: 0.85rem;
            font-weight: 600;
            border: 1px solid var(--stroke);
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
        }

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
        }

        /* Diff Badges */
        .diff {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 10px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.9rem;
            min-width: 48px;
        }

        .diff.positive {
            background: #dcfce7;
            color: #166534;
        }

        .diff.negative {
            background: #fee2e2;
            color: #991b1b;
        }

        .diff.zero {
            background: #f1f5f9;
            color: #475569;
        }

        .asin-link {
            color: var(--ink);
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            transition: color 0.2s;
        }

        .asin-link:hover {
            color: var(--accent);
            text-decoration: underline;
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

        /* --- Responsive --- */
        @media (max-width: 768px) {
            body { padding: 24px 12px 60px; }
            
            /* Hero area */
            .hero { flex-direction: column; align-items: stretch; gap: 20px; }
            .hero-text h1 { font-size: 1.8rem; }
            .hero-stats { flex-direction: column; width: 100%; gap: 12px; }
            .stat-card { width: 100%; flex: none; }
            
            /* Panel and inputs */
            .panel { padding: 16px; }
            .filter-grid { grid-template-columns: 1fr; gap: 12px; }
            input[type="text"], input[type="number"], input[type="date"], select { height: 50px; font-size: 1rem; }
            
            /* Checkboxes */
            .checkbox-wrapper { padding-top: 0; }
            
            /* Buttons */
            .filter-actions { flex-direction: column; align-items: stretch; gap: 12px; }
            button, .ghost { width: 100%; height: 50px; }
            
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
                <p class="eyebrow">Log Scanner</p>
                <h1>Bestandsabweichungen</h1>
                <p class="subtitle">Aktuelle Abweichungen zwischen Amazon und Tricoma analysieren und filtern.</p>
            </div>
            <div class="hero-stats">
                <div class="stat-card">
                    <div class="stat-label">Eintraege (Tag)</div>
                    <div class="stat-value"><?= $totalEntries ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Gefiltert</div>
                    <div class="stat-value"><?= count($filteredEntries) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">ASINs</div>
                    <div class="stat-value"><?= count(array_unique(array_filter(array_map(static function (array $entry): string {
                        return (string)$entry['asin'];
                    }, $filteredEntries)))) ?></div>
                </div>
            </div>
        </div>

        <div class="panel">
            <h2>Filter & Suche</h2>
            <form id="filter-form" method="get">
                <div class="filter-grid">
                    <div class="field">
                        <label for="date">Datum</label>
                        <input type="date" id="date" name="date" value="<?= h($selectedDate) ?>">
                    </div>
                    <div class="field">
                        <label for="asin">ASIN</label>
                        <input type="text" id="asin" name="asin" value="<?= h($filterAsin) ?>" placeholder="B000000000">
                    </div>
                    <div class="field">
                        <label for="sku">SKU</label>
                        <input type="text" id="sku" name="sku" value="<?= h($filterSku) ?>" placeholder="SKU enthaelt ...">
                    </div>
                    <div class="field">
                        <label for="min_abs_diff">Min. absolute Diff</label>
                        <input type="number" step="1" min="0" id="min_abs_diff" name="min_abs_diff" value="<?= h($rawMinAbsDiff) ?>" placeholder="0">
                    </div>
                    <div class="field">
                        <label for="direction">Richtung</label>
                        <select id="direction" name="direction">
                            <option value="any" <?= $filterDirection === 'any' ? 'selected' : '' ?>>Alle anzeigen</option>
                            <option value="increase" <?= $filterDirection === 'increase' ? 'selected' : '' ?>>Nur Erhoehung</option>
                            <option value="decrease" <?= $filterDirection === 'decrease' ? 'selected' : '' ?>>Nur Absenkung</option>
                        </select>
                    </div>
                    <div class="checkbox-wrapper">
                        <label>
                            <input type="checkbox" name="unique" value="1" <?= $filterUnique ? 'checked' : '' ?>>
                            Nur neueste pro ASIN
                        </label>
                    </div>
                    <div class="checkbox-wrapper">
                        <label>
                            <input type="checkbox" name="exact_duplicates" value="1" <?= $filterExactDuplicates ? 'checked' : '' ?>>
                            Duplikate filtern
                        </label>
                    </div>
                </div>
            </form>
            <div class="filter-actions">
                <button type="submit" form="filter-form">Filter anwenden</button>
                <a class="ghost" href="?date=<?= h($selectedDate) ?>">Reset</a>
            </div>
            
            <?php if ($dateWarning): ?>
                <div class="alert warning"><?= h($dateWarning) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert error"><?= h($error) ?></div>
            <?php endif; ?>
            
            <?php if (!empty($availableDates)): ?>
                <div class="dates">
                    <strong>Schnellauswahl:</strong>
                    <?php foreach (array_slice($availableDates, 0, 10) as $dateOption): ?>
                        <a href="?date=<?= h($dateOption) ?>"><?= h($dateOption) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$error && empty($filteredEntries)): ?>
            <div class="empty">Keine Eintraege mit den aktuellen Filtern gefunden.</div>
        <?php elseif (!$error): ?>
            <?php if (!empty($activeFilters)): ?>
                <div class="active-filters">
                    <?php foreach ($activeFilters as $filterLabel): ?>
                        <span class="chip"><?= h($filterLabel) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Datum</th>
                            <th>Zeit</th>
                            <th>SKU</th>
                            <th>ASIN</th>
                            <th>Amazon bisher</th>
                            <th>Tricoma neu</th>
                            <th>Diff</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($filteredEntries as $entry): ?>
                            <?php
                                $diffClass = 'diff zero';
                                $diffPrefix = '';
                                if (is_numeric($entry['diff'])) {
                                    $diffValue = (float)$entry['diff'];
                                    if ($diffValue > 0) {
                                        $diffClass = 'diff positive';
                                        $diffPrefix = '+';
                                    } elseif ($diffValue < 0) {
                                        $diffClass = 'diff negative';
                                    }
                                }
                            ?>
                            <tr>
                                <td><?= h($entry['date']) ?></td>
                                <td><?= h($entry['time']) ?></td>
                                <td style="font-weight: 500;"><?= h((string)$entry['sku']) ?></td>
                                <td>
                                    <?php if ($entry['asin'] !== ''): ?>
                                        <a class="asin-link" href="bestandsabweichungen_historie.php?asin=<?= h((string)$entry['asin']) ?>">
                                            <?= h((string)$entry['asin']) ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="color: var(--muted);">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= h(formatValue($entry['amazon_bisher'])) ?></td>
                                <td><?= h(formatValue($entry['tricoma_neu'])) ?></td>
                                <td>
                                    <span class="<?= h($diffClass) ?>">
                                        <?= $diffPrefix ?><?= h(formatValue($entry['diff'])) ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </div>
</body>
</html>