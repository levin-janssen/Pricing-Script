<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');

$logDir = APP_ROOT . '/logs';
$rawDate = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
$selectedDate = '';
$dateWarning = '';
$filterAsin = isset($_GET['asin']) ? trim((string)$_GET['asin']) : '';
$filterSku = isset($_GET['sku']) ? trim((string)$_GET['sku']) : '';
$entryLimit = 1000;

if ($rawDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $rawDate)) {
    $selectedDate = $rawDate;
} else {
    $selectedDate = date('Y-m-d');
    if ($rawDate !== '') {
        $dateWarning = 'Ungueltiges Datum. Format: YYYY-MM-DD.';
    }
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
$totalEntries = 0;
$error = '';

if (!is_dir($logDir)) {
    $error = 'Log-Verzeichnis nicht gefunden.';
} elseif (!is_file($logFile)) {
    $error = 'Keine Logdatei fuer ' . $selectedDate . ' gefunden.';
} else {
    $handle = fopen($logFile, 'rb');
    if ($handle === false) {
        $error = 'Logdatei konnte nicht gelesen werden.';
    } else {
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parsed = parseLogLine($line);
            if ($parsed === null) {
                continue;
            }

            $totalEntries++;
            if (!matchesFilters($parsed, $line, $filterAsin, $filterSku)) {
                continue;
            }

            $entries[] = $parsed;
        }
        fclose($handle);
    }
}

if ($error === '') {
    usort($entries, static function (array $left, array $right): int {
        return strcmp($right['timestamp'], $left['timestamp']);
    });

    if (count($entries) > $entryLimit) {
        $entries = array_slice($entries, 0, $entryLimit);
    }
}

$activeFilters = [];
if ($filterAsin !== '') {
    $activeFilters[] = 'ASIN: ' . $filterAsin;
}
if ($filterSku !== '') {
    $activeFilters[] = 'SKU: ' . $filterSku;
}

function parseLogLine(string $line): ?array
{
    if (!preg_match('/^\[(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})\] \[([A-Z]+)\] (.+)$/', $line, $matches)) {
        return null;
    }

    $rawMessage = $matches[4];
    $message = $rawMessage;
    $contextRaw = '';
    $context = null;
    $contextDisplay = '';

    if (strpos($rawMessage, ' | Context: ') !== false) {
        [$message, $contextRaw] = explode(' | Context: ', $rawMessage, 2);
        $contextRaw = trim($contextRaw);
        $decoded = json_decode($contextRaw, true);
        if (is_array($decoded)) {
            $context = $decoded;
            $contextDisplay = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($contextDisplay === false) {
                $contextDisplay = $contextRaw;
            }
        } else {
            $contextDisplay = $contextRaw;
        }
    }

    $message = trim($message);
    $asin = is_array($context) ? (string)($context['asin'] ?? '') : '';
    $sku = is_array($context) ? (string)($context['sku'] ?? '') : '';

    return [
        'date' => $matches[1],
        'time' => $matches[2],
        'timestamp' => $matches[1] . ' ' . $matches[2],
        'level' => $matches[3],
        'message' => $message,
        'context' => $contextDisplay,
        'asin' => $asin,
        'sku' => $sku,
    ];
}

function matchesFilters(array $entry, string $line, string $filterAsin, string $filterSku): bool
{
    if ($filterAsin !== '') {
        $hit = stripos($entry['asin'], $filterAsin) !== false || stripos($line, $filterAsin) !== false;
        if (!$hit) {
            return false;
        }
    }

    if ($filterSku !== '') {
        $hit = stripos($entry['sku'], $filterSku) !== false || stripos($line, $filterSku) !== false;
        if (!$hit) {
            return false;
        }
    }

    return true;
}

function buildDateLink(string $date, string $filterAsin, string $filterSku): string
{
    $params = ['date' => $date];
    if ($filterAsin !== '') {
        $params['asin'] = $filterAsin;
    }
    if ($filterSku !== '') {
        $params['sku'] = $filterSku;
    }

    return '?' . http_build_query($params);
}

function levelClass(string $level): string
{
    switch ($level) {
        case 'ERROR':
            return 'level error';
        case 'WARNING':
            return 'level warning';
        case 'DEBUG':
            return 'level debug';
        default:
            return 'level info';
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Log Viewer</title>
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
            max-width: 520px;
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
        input[type="date"] {
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

        input:hover {
            border-color: var(--muted-light);
        }

        input:focus {
            outline: none;
            border-color: var(--accent);
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
            vertical-align: top;
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

        .level {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .level.info {
            background: #e0f2fe;
            color: #0369a1;
        }

        .level.warning {
            background: #fef3c7;
            color: #b45309;
        }

        .level.error {
            background: #fee2e2;
            color: #b91c1c;
        }

        .level.debug {
            background: #e2e8f0;
            color: #475569;
        }

        .message-cell,
        .context-cell {
            white-space: normal;
            max-width: 520px;
        }

        .context-cell pre {
            white-space: pre-wrap;
            word-break: break-word;
            font-family: "Space Grotesk", system-ui, -apple-system, sans-serif;
            font-size: 0.85rem;
            color: var(--muted);
            margin: 0;
        }

        .muted {
            color: var(--muted);
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

        @media (max-width: 768px) {
            body {
                padding: 24px 16px 40px;
            }
            .hero {
                flex-direction: column;
                align-items: flex-start;
                gap: 20px;
            }
            .hero-stats {
                width: 100%;
            }
            .stat-card {
                flex: 1;
            }
            .filter-actions {
                flex-direction: column;
                align-items: stretch;
            }
            button, .ghost {
                width: 100%;
            }
            .message-cell,
            .context-cell {
                max-width: none;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="hero">
            <div class="hero-text">
                <p class="eyebrow">Log Viewer</p>
                <h1>Log-Eintraege</h1>
                <p class="subtitle">Nach ASIN oder SKU filtern und alle passenden Eintraege pro Tag anzeigen.</p>
            </div>
            <div class="hero-stats">
                <div class="stat-card">
                    <div class="stat-label">Eintraege (Tag)</div>
                    <div class="stat-value"><?= $totalEntries ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Treffer (max. <?= $entryLimit ?>)</div>
                    <div class="stat-value"><?= count($entries) ?></div>
                </div>
                <div class="stat-card">
                    <div class="stat-label">Datum</div>
                    <div class="stat-value"><?= h($selectedDate) ?></div>
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
                        <a href="<?= h(buildDateLink($dateOption, $filterAsin, $filterSku)) ?>"><?= h($dateOption) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$error && empty($entries)): ?>
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
                            <th>Level</th>
                            <th>SKU</th>
                            <th>ASIN</th>
                            <th>Nachricht</th>
                            <th>Kontext</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td><?= h($entry['date']) ?></td>
                                <td><?= h($entry['time']) ?></td>
                                <td><span class="<?= h(levelClass($entry['level'])) ?>"><?= h($entry['level']) ?></span></td>
                                <td><?= $entry['sku'] !== '' ? h($entry['sku']) : '<span class="muted">-</span>' ?></td>
                                <td><?= $entry['asin'] !== '' ? h($entry['asin']) : '<span class="muted">-</span>' ?></td>
                                <td class="message-cell"><?= h($entry['message']) ?></td>
                                <td class="context-cell">
                                    <?php if ($entry['context'] !== ''): ?>
                                        <pre><?= h($entry['context']) ?></pre>
                                    <?php else: ?>
                                        <span class="muted">-</span>
                                    <?php endif; ?>
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
