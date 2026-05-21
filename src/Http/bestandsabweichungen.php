<?php

declare(strict_types=1);

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

            if (!preg_match('/^\[(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})\] \[[A-Z]+\] .*? \| Context: (.+)$/', $line, $matches)) {
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
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Source+Serif+4:wght@400;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
    <style>
        :root {
            --ink: #1c1c1c;
            --muted: #60646c;
            --accent: #ff7a18;
            --accent-2: #2bb4ff;
            --surface: #ffffff;
            --surface-soft: #f8fafc;
            --stroke: #e4e7ec;
            --shadow: 0 12px 30px rgba(16, 24, 40, 0.12);
            --radius: 16px;
        }
        * {
            box-sizing: border-box;
        }
        body {
            font-family: "Space Grotesk", "Segoe UI", sans-serif;
            color: var(--ink);
            background: radial-gradient(1200px circle at top left, #fff1e6 0%, #f2f6ff 42%, #eefbf7 70%, #f4f4f4 100%);
            margin: 0;
            padding: 32px 20px 60px;
        }
        .page {
            max-width: 1180px;
            margin: 0 auto;
        }
        .hero {
            display: flex;
            flex-wrap: wrap;
            gap: 24px;
            align-items: flex-end;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        .eyebrow {
            text-transform: uppercase;
            letter-spacing: 0.14em;
            font-size: 0.7rem;
            color: var(--muted);
            margin: 0 0 8px;
        }
        h1 {
            font-size: 2.4rem;
            margin: 0 0 6px;
            font-weight: 700;
        }
        .subtitle {
            font-family: "Source Serif 4", serif;
            margin: 0;
            color: var(--muted);
            font-size: 1.05rem;
        }
        .hero-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 12px;
            min-width: 320px;
        }
        .stat-card {
            background: var(--surface);
            border: 1px solid var(--stroke);
            border-radius: 14px;
            padding: 14px 16px;
            box-shadow: var(--shadow);
        }
        .stat-label {
            font-size: 0.8rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.08em;
        }
        .stat-value {
            font-size: 1.5rem;
            font-weight: 600;
            margin-top: 6px;
        }
        .panel {
            background: var(--surface);
            padding: 20px 22px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            border: 1px solid rgba(255, 255, 255, 0.7);
        }
        .panel h2 {
            margin: 0 0 16px;
            font-size: 1.4rem;
        }
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 16px;
        }
        .field {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        label {
            font-weight: 600;
            color: var(--muted);
        }
        input[type="text"],
        input[type="number"],
        input[type="date"],
        select {
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--stroke);
            font-size: 0.95rem;
            background: var(--surface-soft);
        }
        .checkbox {
            justify-content: center;
        }
        .checkbox label {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
            color: var(--ink);
        }
        .filter-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 16px;
            align-items: center;
        }
        button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 999px;
            border: none;
            background: linear-gradient(120deg, var(--accent), #ffb347);
            color: #1a1a1a;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(255, 122, 24, 0.25);
        }
        .ghost {
            display: inline-flex;
            align-items: center;
            padding: 10px 16px;
            border-radius: 999px;
            border: 1px solid var(--stroke);
            text-decoration: none;
            color: var(--ink);
            background: var(--surface);
            font-weight: 600;
        }
        .warning {
            color: #8a6d3b;
            background: #fcf8e3;
            border: 1px solid #faebcc;
            padding: 10px;
            border-radius: 10px;
            margin-top: 12px;
        }
        .error {
            color: #a94442;
            background: #f2dede;
            border: 1px solid #ebccd1;
            padding: 10px;
            border-radius: 10px;
            margin-top: 12px;
        }
        .dates {
            margin-top: 14px;
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            font-size: 0.9em;
        }
        .dates a {
            text-decoration: none;
            padding: 6px 12px;
            border-radius: 999px;
            background: var(--surface-soft);
            color: var(--ink);
            border: 1px solid var(--stroke);
        }
        .active-filters {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            margin-bottom: 16px;
        }
        .chip {
            padding: 6px 10px;
            border-radius: 999px;
            background: #eef7ff;
            color: #1d4f91;
            font-size: 0.85rem;
            border: 1px solid #cfe6ff;
        }
        .table-wrap {
            background: var(--surface);
            border-radius: var(--radius);
            overflow: hidden;
            box-shadow: var(--shadow);
            border: 1px solid var(--stroke);
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--stroke);
            text-align: left;
            font-size: 0.95em;
        }
        th {
            background: #f1f5f9;
            font-weight: 600;
            position: sticky;
            top: 0;
            z-index: 1;
        }
        tr:nth-child(even) td {
            background: #fbfcfe;
        }
        .diff {
            font-weight: 600;
        }
        .diff.positive {
            color: #146c43;
        }
        .diff.negative {
            color: #b42318;
        }
        .diff.zero {
            color: #6b7280;
        }
        .empty {
            padding: 28px;
            text-align: center;
            color: var(--muted);
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        .asin-link {
            color: var(--ink);
            font-weight: 600;
            text-decoration: none;
        }
        .asin-link:hover {
            color: var(--accent-2);
            text-decoration: underline;
        }
        @media (max-width: 720px) {
            body {
                padding: 24px 16px 40px;
            }
            h1 {
                font-size: 2rem;
            }
            .hero {
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="hero">
            <div>
                <p class="eyebrow">Log scan</p>
                <h1>Bestandsabweichungen</h1>
                <p class="subtitle">Zeigt die aktuellen Abweichungen zwischen Amazon und Tricoma. Optional mit Filtern und Deduplikation.</p>
            </div>
            <div class="hero-stats">
                <div class="stat-card">
                    <div class="stat-label">Eintraege (Tag)</div>
                    <div class="stat-value"><?= $totalEntries ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Eintraege (gefiltert)</div>
                    <div class="stat-value"><?= count($filteredEntries) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">ASINs (gefiltert)</div>
                    <div class="stat-value"><?= count(array_unique(array_filter(array_map(static function (array $entry): string {
                        return (string)$entry['asin'];
                    }, $filteredEntries)))) ?></div>
                </div>
            </div>
        </div>

        <div class="panel">
            <h2>Filter</h2>
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
                            <option value="any" <?= $filterDirection === 'any' ? 'selected' : '' ?>>Alle</option>
                            <option value="increase" <?= $filterDirection === 'increase' ? 'selected' : '' ?>>Nur Erhoehung</option>
                            <option value="decrease" <?= $filterDirection === 'decrease' ? 'selected' : '' ?>>Nur Absenkung</option>
                        </select>
                    </div>
                    <div class="field checkbox">
                        <label>
                            <input type="checkbox" name="unique" value="1" <?= $filterUnique ? 'checked' : '' ?>>
                            Nur neueste pro ASIN
                        </label>
                    </div>
                </div>
            </form>
            <div class="filter-actions">
                <button type="submit" form="filter-form">Filter anwenden</button>
                <a class="ghost" href="?date=<?= h($selectedDate) ?>">Zuruecksetzen</a>
            </div>
            <?php if ($dateWarning): ?>
                <div class="warning"><?= h($dateWarning) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="error"><?= h($error) ?></div>
            <?php endif; ?>
            <?php if (!empty($availableDates)): ?>
                <div class="dates">
                    Schnellauswahl:
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
                                if (is_numeric($entry['diff'])) {
                                    $diffValue = (float)$entry['diff'];
                                    if ($diffValue > 0) {
                                        $diffClass = 'diff positive';
                                    } elseif ($diffValue < 0) {
                                        $diffClass = 'diff negative';
                                    }
                                }
                            ?>
                            <tr>
                                <td><?= h($entry['date']) ?></td>
                                <td><?= h($entry['time']) ?></td>
                                <td><?= h((string)$entry['sku']) ?></td>
                                <td>
                                    <?php if ($entry['asin'] !== ''): ?>
                                        <a class="asin-link" href="bestandsabweichungen_historie.php?asin=<?= h((string)$entry['asin']) ?>">
                                            <?= h((string)$entry['asin']) ?>
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?= h(formatValue($entry['amazon_bisher'])) ?></td>
                                <td><?= h(formatValue($entry['tricoma_neu'])) ?></td>
                                <td class="<?= h($diffClass) ?>"><?= h(formatValue($entry['diff'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

    </div>
</body>
</html>
