<?php

declare(strict_types=1);
ini_set('default_charset',  'UTF-8');

date_default_timezone_set('Europe/Berlin');

$logDir = APP_ROOT . '/logs';
$rawDate = isset($_GET['date']) ? trim((string)$_GET['date']) : '';
$selectedDate = '';
$dateWarning = '';
$filterAsin = isset($_GET['asin']) ? trim((string)$_GET['asin']) : '';
$filterSku = isset($_GET['sku']) ? trim((string)$_GET['sku']) : '';
$filterRunId = isset($_GET['runid']) ? trim((string)$_GET['runid']) : '';
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

$appLogFile = $logDir . '/app_' . $selectedDate . '.log';
$perfLogFile = $logDir . '/performance_' . $selectedDate . '.log';

$lines = [];
$error = '';

if (!is_dir($logDir)) {
    $error = 'Log-Verzeichnis nicht gefunden.';
} else {
    $hasLogs = false;
    if (is_file($appLogFile)) {
        $lines = array_merge($lines, file($appLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $hasLogs = true;
    }
    if (is_file($perfLogFile)) {
        $lines = array_merge($lines, file($perfLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $hasLogs = true;
    }
    
    if (!$hasLogs) {
        $error = 'Keine Logdateien fuer ' . $selectedDate . ' gefunden.';
    }
}

$rawEntries = [];
if ($error === '') {
    foreach ($lines as $line) {
        $parsed = parseLogLine(trim($line));
        if ($parsed !== null) {
            $rawEntries[] = $parsed;
        }
    }

    // Chronologisch sortieren, damit Performance-Logs sicher den vorherigen App-Logs zugeordnet werden können
    usort($rawEntries, static function (array $left, array $right): int {
        return strcmp($left['timestamp'], $right['timestamp']);
    });
}

// ------------------------------------------------------------------
// LOG-MERGE & STATS EXTRACTION
// ------------------------------------------------------------------
$mergedEntries = [];
$lastAsinEntryIndex = []; 
$runTotalTimes = [];      
$recentRuns = []; // Speichert die Daten fuer die Schnellzugriffs-Buttons

foreach ($rawEntries as $entry) {
    $runId = $entry['runId'];
    $asin = $entry['asin'];
    $isPerf = ($entry['level'] === 'PERF');
    $duration = $entry['duration'];

    // 1. Run ID Statistik fuer die Top-Leiste sammeln
    if ($runId !== '') {
        $recentRuns[$runId] = [
            'first_seen' => $recentRuns[$runId]['first_seen'] ?? $entry['timestamp'],
            'last_seen' => $entry['timestamp'],
            'has_error' => (isset($recentRuns[$runId]['has_error']) ? $recentRuns[$runId]['has_error'] : false) || ($entry['level'] === 'ERROR'),
            'runId' => $runId
        ];
    }

    // Globale Skriptlaufzeit abgreifen
    if ($isPerf && $runId !== '' && stripos($entry['message'], 'Total Script Execution Time') !== false && $duration !== null) {
        $runTotalTimes[$runId] = $duration;
    }

    // Wenn es ein Performance-Log mit einer ASIN ist -> An den letzten App-Log anhaengen
    if ($isPerf && $asin !== '' && $duration !== null && $runId !== '') {
        if (isset($lastAsinEntryIndex[$runId][$asin])) {
            $idx = $lastAsinEntryIndex[$runId][$asin];
            $mergedEntries[$idx]['display_duration'] = $duration;
            continue; 
        }
    }

    // Normales Log oder Standalone Performance-Log hinzufuegen
    $mergedEntries[] = $entry;
    $idx = count($mergedEntries) - 1;

    if (!$isPerf && $asin !== '' && $runId !== '') {
        $lastAsinEntryIndex[$runId][$asin] = $idx;
    }
}

// Filter fuer die Quick-Access Runs (Letzte 2 Stunden, max 15)
$twoHoursAgo = date('Y-m-d H:i:s', time() - 7200);
$isToday = ($selectedDate === date('Y-m-d'));
$displayRuns = [];

foreach ($recentRuns as $rId => $data) {
    if ($isToday) {
        if ($data['last_seen'] >= $twoHoursAgo) {
            $displayRuns[] = $data;
        }
    } else {
        $displayRuns[] = $data;
    }
}
usort($displayRuns, static fn($a, $b) => strcmp($b['first_seen'], $a['first_seen']));
$displayRuns = array_slice($displayRuns, 0, 15);

// Graph-Daten fuer die letzten Runs (nur Runs mit Gesamtzeit)
$graphData = [];
foreach ($displayRuns as $run) {
    $runId = $run['runId'];
    if ($runId === '' || !isset($runTotalTimes[$runId])) {
        continue;
    }
    $graphData[] = [
        'runId' => $runId,
        'duration' => (float)$runTotalTimes[$runId],
        'has_error' => (bool)$run['has_error'],
        'last_seen' => $run['last_seen']
    ];
}
// Aelteste Runs links anzeigen
if (!empty($graphData)) {
    $graphData = array_values(array_reverse($graphData));
}


// ------------------------------------------------------------------
// FILTERING & FINAL SORT
// ------------------------------------------------------------------
$entries = [];
$totalEntries = count($mergedEntries);

foreach ($mergedEntries as $entry) {
    if (!matchesFilters($entry, $filterAsin, $filterSku, $filterRunId)) {
        continue;
    }
    $entries[] = $entry;
}

// Fliessend von neu nach alt sortieren
usort($entries, static function (array $left, array $right): int {
    return strcmp($right['timestamp'], $left['timestamp']);
});

if (count($entries) > $entryLimit) {
    $entries = array_slice($entries, 0, $entryLimit);
}

$activeFilters = [];
if ($filterAsin !== '') $activeFilters[] = 'ASIN: ' . $filterAsin;
if ($filterSku !== '') $activeFilters[] = 'SKU: ' . $filterSku;
if ($filterRunId !== '') $activeFilters[] = 'Run ID: ' . $filterRunId;

$filteredRuntime = null;
if ($filterRunId !== '' && isset($runTotalTimes[$filterRunId])) {
    $filteredRuntime = $runTotalTimes[$filterRunId];
}

// ------------------------------------------------------------------
// FUNCTIONS
// ------------------------------------------------------------------

function parseLogLine(string $line): ?array
{
    if (!preg_match('/^\[(.*?) (.*?)\](?: \[RunID: (.*?)\])? \[([A-Z]+)\] (.+)$/', $line, $matches)) {
        return null;
    }

    $date = $matches[1];
    $time = $matches[2];
    $runId = trim($matches[3] ?? '');
    $level = $matches[4];
    $rawMessage = $matches[5];
    
    $message = $rawMessage;
    $contextRaw = '';
    $context = null;
    $contextDisplay = '';
    $duration = null;

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
            if (isset($context['duration_seconds'])) {
                $duration = (float)$context['duration_seconds'];
            }
        } else {
            $contextDisplay = $contextRaw;
        }
    }

    $message = trim($message);
    $asin = is_array($context) ? (string)($context['asin'] ?? '') : '';
    $sku = is_array($context) ? (string)($context['sku'] ?? '') : '';

    return [
        'date' => $date,
        'time' => $time,
        'timestamp' => $date . ' ' . $time,
        'runId' => $runId,
        'level' => $level,
        'message' => $message,
        'context' => $contextDisplay,
        'context_array' => $context,
        'asin' => $asin,
        'sku' => $sku,
        'duration' => $duration,
        'display_duration' => $duration,
        'raw_line' => $line
    ];
}

function matchesFilters(array $entry, string $filterAsin, string $filterSku, string $filterRunId): bool
{
    $line = $entry['raw_line'];
    
    if ($filterRunId !== '' && stripos($entry['runId'], $filterRunId) === false) {
        return false;
    }

    if ($filterAsin !== '') {
        if (stripos($entry['asin'], $filterAsin) === false && stripos($line, $filterAsin) === false) {
            return false;
        }
    }

    if ($filterSku !== '') {
        if (stripos($entry['sku'], $filterSku) === false && stripos($line, $filterSku) === false) {
            return false;
        }
    }

    return true;
}

function buildDateLink(string $date, string $filterAsin, string $filterSku, string $filterRunId): string
{
    $params = ['date' => $date];
    if ($filterAsin !== '') $params['asin'] = $filterAsin;
    if ($filterSku !== '') $params['sku'] = $filterSku;
    if ($filterRunId !== '') $params['runid'] = $filterRunId;

    return '?' . http_build_query($params);
}

function formatDuration(?float $seconds): string 
{
    if ($seconds === null) return '-';
    return number_format($seconds, 2, ',', '.') . ' s';
}

function levelClass(string $level): string
{
    switch ($level) {
        case 'ERROR': return 'level error';
        case 'WARNING': return 'level warning';
        case 'DEBUG': return 'level debug';
        case 'PERF': return 'level perf';
        default: return 'level info';
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
    <title>Log Viewer & Performance Tracker</title>
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

        .hero {
            display: flex;
            flex-wrap: wrap;
            gap: 32px;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 32px;
        }

        .hero-text { flex: 1; min-width: 300px; }

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

        /* --- NEU: Quick Runs Styles --- */
        .quick-runs {
            margin-top: 20px;
            display: flex;
            flex-direction: column;
            gap: 10px;
        }

        .quick-runs-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .quick-runs-list {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
        }

        .quick-run-badge {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.85rem;
            padding: 4px 10px;
            background: var(--surface);
            border: 1px solid var(--stroke);
            border-radius: 6px;
            color: var(--ink);
            text-decoration: none;
            transition: all 0.2s;
            box-shadow: var(--shadow-sm);
        }

        .quick-run-badge:hover {
            border-color: var(--muted-light);
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }

        .quick-run-badge.active {
            background: var(--ink);
            color: white;
            border-color: var(--ink);
        }

        .quick-run-badge.error {
            border-color: #fecaca;
            background: #fef2f2;
            color: #b91c1c;
        }

        .quick-run-badge.error:hover {
            border-color: #f87171;
        }

        .quick-run-badge.error.active {
            background: #b91c1c;
            color: white;
            border-color: #b91c1c;
        }
        /* ------------------------------- */

        .hero-stats { display: flex; gap: 16px; flex-wrap: wrap; align-items: flex-start; }

        .stat-card {
            background: var(--surface);
            border: 1px solid var(--stroke);
            border-radius: var(--radius);
            padding: 16px 20px;
            box-shadow: var(--shadow-sm);
            min-width: 140px;
        }
        
        .stat-card.highlight {
            border-color: #cbd5e1;
            background: #f8fafc;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .stat-card.highlight .stat-label { color: #334155; }
        .stat-card.highlight .stat-value { color: var(--accent); }

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

        .field { display: flex; flex-direction: column; gap: 8px; }
        .field label { font-size: 0.85rem; font-weight: 600; color: var(--muted); }

        input[type="text"], input[type="date"] {
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

        input:hover { border-color: var(--muted-light); }
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

        .ghost:hover { background: var(--surface-soft); border-color: var(--muted-light); }

        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            margin-top: 20px;
            font-size: 0.95rem;
            font-weight: 500;
            display: flex;
            align-items: center;
        }
        
        .alert.warning { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .alert.error { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }

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

        .dates a:hover { border-color: var(--muted-light); background: var(--surface); }

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

        tr { border-bottom: 1px solid var(--stroke); transition: background 0.15s ease; }
        tr:last-child { border-bottom: none; }
        tr:hover { background: var(--surface-soft); }

        td { font-variant-numeric: tabular-nums; color: var(--ink); }

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

        .level.info { background: #e0f2fe; color: #0369a1; }
        .level.warning { background: #fef3c7; color: #b45309; }
        .level.error { background: #fee2e2; color: #b91c1c; }
        .level.debug { background: #e2e8f0; color: #475569; }
        .level.perf { background: #f3e8ff; color: #7e22ce; }

        .run-id-badge {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.85rem;
            color: var(--muted);
            background: var(--surface-soft);
            padding: 4px 8px;
            border-radius: 6px;
            border: 1px solid var(--stroke);
        }

        .duration-badge {
            display: inline-flex;
            align-items: center;
            background: #f8fafc;
            color: #334155;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 0.85rem;
            font-weight: 600;
            border: 1px solid var(--stroke);
            box-shadow: 0 1px 2px rgba(0,0,0,0.02);
        }

        .message-cell, .context-cell { white-space: normal; min-width: 280px; }
        .context-cell pre {
            white-space: pre-wrap;
            word-break: break-word;
            font-family: "Space Grotesk", system-ui, -apple-system, sans-serif;
            font-size: 0.85rem;
            color: var(--muted);
            margin: 0;
            max-width: 400px;
        }

        .muted { color: var(--muted); }

        .empty {
            padding: 48px 24px;
            text-align: center;
            color: var(--muted);
            background: var(--surface);
            border-radius: var(--radius);
            border: 1px dashed var(--muted-light);
            font-size: 1.05rem;
        }

        .chart-wrap {
            width: 100%;
            position: relative;
            padding: 12px 12px 4px;
            border: 1px solid var(--stroke);
            border-radius: var(--radius);
            background: linear-gradient(135deg, #fff7ed 0%, #f8fafc 45%, #eef2ff 100%);
            overflow: hidden;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.6), 0 16px 30px rgba(15, 23, 42, 0.06);
        }

        .chart-canvas {
            width: 100%;
            height: 260px;
            display: block;
            cursor: crosshair;
        }

        .chart-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 16px;
            margin-top: 12px;
            color: var(--muted);
            font-size: 0.85rem;
            font-weight: 600;
        }

        .legend-item {
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .legend-dot {
            width: 10px;
            height: 10px;
            border-radius: 999px;
            display: inline-block;
        }

        .legend-dot.ok { background: var(--accent); }
        .legend-dot.error { background: #b91c1c; }

        .chart-empty {
            padding: 24px;
            border-radius: var(--radius);
            border: 1px dashed var(--muted-light);
            color: var(--muted);
            background: var(--surface);
        }

        .chart-tooltip {
            position: absolute;
            top: 0;
            left: 0;
            min-width: 180px;
            padding: 10px 12px;
            border-radius: 10px;
            background: rgba(15, 23, 42, 0.92);
            color: #f8fafc;
            border: 1px solid rgba(255,255,255,0.1);
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.25);
            font-size: 0.8rem;
            line-height: 1.4;
            opacity: 0;
            transform: translateY(6px);
            transition: opacity 0.15s ease, transform 0.15s ease;
            pointer-events: none;
            z-index: 5;
        }

        .chart-tooltip.is-visible {
            opacity: 1;
            transform: translateY(0);
        }

        .chart-tooltip strong {
            display: block;
            font-size: 0.85rem;
            margin-bottom: 6px;
        }

        .chart-tooltip .tooltip-row {
            display: flex;
            justify-content: space-between;
            gap: 12px;
            color: #e2e8f0;
        }

        .chart-tooltip .tooltip-row span:last-child {
            color: #f8fafc;
            font-weight: 600;
        }

        .chart-tooltip .status.ok { color: #fdba74; }
        .chart-tooltip .status.error { color: #fecaca; }

        @media (max-width: 768px) {
            body { padding: 24px 16px 40px; }
            .hero { flex-direction: column; align-items: flex-start; gap: 20px; }
            .hero-stats { width: 100%; }
            .stat-card { flex: 1; }
            .filter-actions { flex-direction: column; align-items: stretch; }
            button, .ghost { width: 100%; }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="hero">
            <div class="hero-text">
                <p class="eyebrow">Log Viewer</p>
                <h1>System & Performance Logs</h1>
                <p class="subtitle">Analysiere Skript-Ausfuerungen, setze Run IDs ein und ueberwache die Laufzeiten.</p>
                
                <div class="quick-runs">
                    <div class="quick-runs-label">
                        <?= $isToday ? 'Runs der letzten 2h:' : 'Letzte Runs dieses Tages:' ?>
                    </div>
                    <div class="quick-runs-list">
                        <?php foreach ($displayRuns as $run): ?>
                            <?php 
                                $isError = $run['has_error'] ? 'error' : '';
                                $isActive = ($filterRunId === $run['runId']) ? 'active' : '';
                            ?>
                            <a href="?date=<?= h($selectedDate) ?>&runid=<?= h($run['runId']) ?>" 
                               class="quick-run-badge <?= $isError ?> <?= $isActive ?>"
                               title="Letzter Eintrag: <?= h($run['last_seen']) ?>">
                               <?= h($run['runId']) ?>
                            </a>
                        <?php endforeach; ?>
                        
                        <?php if (empty($displayRuns)): ?>
                            <span class="muted" style="font-size: 0.85rem;">Keine Runs gefunden.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="hero-stats">
                <?php if ($filteredRuntime !== null): ?>
                    <div class="stat-card highlight">
                        <div class="stat-label">Gesamtlaufzeit Run</div>
                        <div class="stat-value"><?= h(formatDuration($filteredRuntime)) ?></div>
                    </div>
                <?php endif; ?>
                <div class="stat-card">
                    <div class="stat-label">Alle Logs (Tag)</div>
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
                        <label for="runid">Run ID</label>
                        <input type="text" id="runid" name="runid" value="<?= h($filterRunId) ?>" placeholder="z.B. a7b39f">
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
                    <strong>Tage mit Logs:</strong>
                    <?php foreach (array_slice($availableDates, 0, 10) as $dateOption): ?>
                        <a href="<?= h(buildDateLink($dateOption, '', '', '')) ?>"><?= h($dateOption) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($filterRunId === ''): ?>
            <div class="panel">
                <h2>Run-Laufzeiten (letzte Runs)</h2>
                <p class="muted">Nur Runs mit gemessener Gesamtlaufzeit werden angezeigt.</p>
                <?php if (empty($graphData)): ?>
                    <div class="chart-empty">Keine Laufzeiten fuer die letzten Runs gefunden.</div>
                <?php else: ?>
                    <div class="chart-wrap">
                        <canvas id="run-duration-chart" class="chart-canvas" aria-label="Run Laufzeiten Diagramm"></canvas>
                        <div id="run-duration-tooltip" class="chart-tooltip" role="status" aria-live="polite"></div>
                    </div>
                    <div class="chart-legend">
                        <span class="legend-item"><span class="legend-dot ok"></span>Run ohne Fehler</span>
                        <span class="legend-item"><span class="legend-dot error"></span>Run mit Fehler</span>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

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
                            <th>Zeit</th>
                            <th>Run ID</th>
                            <th>Level</th>
                            <th>Dauer</th>
                            <th>SKU</th>
                            <th>ASIN</th>
                            <th>Nachricht</th>
                            <th>Kontext</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($entries as $entry): ?>
                            <tr>
                                <td><?= h($entry['time']) ?></td>
                                <td>
                                    <?php if ($entry['runId'] !== ''): ?>
                                        <a href="?date=<?= h($selectedDate) ?>&runid=<?= h($entry['runId']) ?>" style="text-decoration: none;">
                                            <span class="run-id-badge"><?= h($entry['runId']) ?></span>
                                        </a>
                                    <?php else: ?>
                                        <span class="muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><span class="<?= h(levelClass($entry['level'])) ?>"><?= h($entry['level']) ?></span></td>
                                <td>
                                    <?php if ($entry['display_duration'] !== null): ?>
                                        <span class="duration-badge">⏱ <?= h(formatDuration($entry['display_duration'])) ?></span>
                                    <?php else: ?>
                                        <span class="muted">-</span>
                                    <?php endif; ?>
                                </td>
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

    <script>
        (() => {
            const data = <?= json_encode($graphData, JSON_UNESCAPED_UNICODE) ?>;
            const canvas = document.getElementById('run-duration-chart');
            if (!canvas || !Array.isArray(data) || data.length === 0) {
                return;
            }

            const ctx = canvas.getContext('2d');
            const rootStyles = getComputedStyle(document.documentElement);
            const accent = rootStyles.getPropertyValue('--accent').trim() || '#ea580c';
            const muted = rootStyles.getPropertyValue('--muted').trim() || '#64748b';
            const stroke = rootStyles.getPropertyValue('--stroke').trim() || '#e2e8f0';
            const chartWrap = canvas.closest('.chart-wrap');
            const tooltip = chartWrap ? chartWrap.querySelector('#run-duration-tooltip') : null;
            const grid = 'rgba(148, 163, 184, 0.35)';

            let barRects = [];
            let hoverIndex = -1;

            const escapeHtml = (value) => String(value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');

            const pickUnit = (seconds) => {
                if (seconds >= 86400) return { unit: 'd', factor: 86400 };
                if (seconds >= 3600) return { unit: 'h', factor: 3600 };
                if (seconds >= 60) return { unit: 'min', factor: 60 };
                return { unit: 's', factor: 1 };
            };

            const formatScaled = (seconds, unit, forcedDecimals) => {
                const value = seconds / unit.factor;
                const decimals = forcedDecimals !== undefined
                    ? forcedDecimals
                    : (value >= 100 ? 0 : value >= 10 ? 1 : 2);
                return value.toLocaleString('de-DE', {
                    minimumFractionDigits: decimals,
                    maximumFractionDigits: decimals
                }) + ' ' + unit.unit;
            };

            const formatAuto = (seconds) => formatScaled(seconds, pickUnit(seconds));

            const drawRoundedRect = (x, y, width, height, radius) => {
                const r = Math.min(radius, width / 2, height / 2);
                ctx.beginPath();
                ctx.moveTo(x + r, y);
                ctx.lineTo(x + width - r, y);
                ctx.quadraticCurveTo(x + width, y, x + width, y + r);
                ctx.lineTo(x + width, y + height);
                ctx.lineTo(x, y + height);
                ctx.lineTo(x, y + r);
                ctx.quadraticCurveTo(x, y, x + r, y);
                ctx.closePath();
            };

            const drawChart = () => {
                const width = canvas.clientWidth || 600;
                const height = 260;
                const dpr = window.devicePixelRatio || 1;

                canvas.width = Math.floor(width * dpr);
                canvas.height = Math.floor(height * dpr);
                canvas.style.height = height + 'px';
                ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

                ctx.clearRect(0, 0, width, height);

                const padding = { top: 26, right: 20, bottom: 44, left: 62 };
                const plotWidth = width - padding.left - padding.right;
                const plotHeight = height - padding.top - padding.bottom;

                const maxDuration = Math.max(...data.map((item) => item.duration));
                const scaleMax = maxDuration > 0 ? maxDuration * 1.1 : 1;
                const barSlot = plotWidth / data.length;
                const barGap = Math.min(10, barSlot * 0.25);
                const barWidth = Math.max(8, barSlot - barGap);

                ctx.strokeStyle = stroke;
                ctx.fillStyle = muted;
                ctx.lineWidth = 1;
                ctx.font = '12px "Space Grotesk", system-ui, -apple-system, sans-serif';

                const axisUnit = pickUnit(maxDuration);
                const axisLabel = 'Laufzeit (' + axisUnit.unit + ')';

                const background = ctx.createLinearGradient(0, padding.top, 0, height - padding.bottom);
                background.addColorStop(0, 'rgba(248, 250, 252, 0.85)');
                background.addColorStop(1, 'rgba(226, 232, 240, 0.25)');
                ctx.fillStyle = background;
                ctx.fillRect(padding.left, padding.top, plotWidth, plotHeight);

                ctx.fillStyle = muted;
                ctx.textAlign = 'left';
                ctx.textBaseline = 'alphabetic';
                ctx.fillText(axisLabel, padding.left, padding.top - 8);

                const gridLines = 4;
                ctx.save();
                ctx.strokeStyle = grid;
                ctx.setLineDash([4, 4]);
                ctx.lineWidth = 1;
                ctx.textAlign = 'right';
                ctx.textBaseline = 'middle';
                for (let i = 0; i <= gridLines; i += 1) {
                    const y = padding.top + (plotHeight * i) / gridLines;
                    ctx.beginPath();
                    ctx.moveTo(padding.left, y);
                    ctx.lineTo(width - padding.right, y);
                    ctx.stroke();

                    const labelValue = scaleMax - (scaleMax * i) / gridLines;
                    ctx.fillText(formatScaled(labelValue, axisUnit), padding.left - 10, y);
                }
                ctx.restore();

                const labelStep = data.length > 12 ? Math.ceil(data.length / 12) : 1;
                barRects = [];

                data.forEach((item, index) => {
                    const x = padding.left + index * barSlot + (barSlot - barWidth) / 2;
                    const barHeight = (item.duration / scaleMax) * plotHeight;
                    const y = padding.top + plotHeight - barHeight;

                    if (barHeight <= 0) {
                        return;
                    }

                    const baseColor = item.has_error ? '#b91c1c' : accent;
                    const radius = Math.min(10, barWidth / 2, barHeight / 2);

                    ctx.save();
                    if (index === hoverIndex) {
                        ctx.shadowColor = 'rgba(15, 23, 42, 0.25)';
                        ctx.shadowBlur = 16;
                        ctx.shadowOffsetY = 6;
                    }

                    ctx.fillStyle = baseColor;
                    drawRoundedRect(x, y, barWidth, barHeight, radius);
                    ctx.fill();

                    const gloss = ctx.createLinearGradient(0, y, 0, y + barHeight);
                    gloss.addColorStop(0, 'rgba(255,255,255,0.45)');
                    gloss.addColorStop(0.6, 'rgba(255,255,255,0.12)');
                    gloss.addColorStop(1, 'rgba(255,255,255,0)');
                    ctx.fillStyle = gloss;
                    drawRoundedRect(x, y, barWidth, barHeight, radius);
                    ctx.fill();
                    ctx.restore();

                    barRects.push({ x, y, width: barWidth, height: barHeight, item });

                    if (index % labelStep === 0) {
                        ctx.fillStyle = muted;
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'alphabetic';
                        const label = item.runId.length > 6 ? item.runId.slice(0, 6) : item.runId;
                        ctx.fillText(label, x + barWidth / 2, height - 16);
                    }
                });

                ctx.strokeStyle = stroke;
                ctx.lineWidth = 1;
                ctx.setLineDash([]);
                ctx.beginPath();
                ctx.moveTo(padding.left, padding.top + plotHeight);
                ctx.lineTo(width - padding.right, padding.top + plotHeight);
                ctx.stroke();
            };

            const updateTooltip = (bar, event) => {
                if (!tooltip || !chartWrap) {
                    return;
                }
                const statusLabel = bar.item.has_error ? 'Fehler' : 'OK';
                const statusClass = bar.item.has_error ? 'error' : 'ok';
                tooltip.innerHTML = `
                    <strong>Run ${escapeHtml(bar.item.runId)}</strong>
                    <div class="tooltip-row"><span>Laufzeit</span><span>${escapeHtml(formatAuto(bar.item.duration))}</span></div>
                    <div class="tooltip-row"><span>Status</span><span class="status ${statusClass}">${statusLabel}</span></div>
                    <div class="tooltip-row"><span>Letzter Eintrag</span><span>${escapeHtml(bar.item.last_seen)}</span></div>
                `;

                tooltip.classList.add('is-visible');
                const wrapRect = chartWrap.getBoundingClientRect();
                const tooltipRect = tooltip.getBoundingClientRect();
                const offsetX = event.clientX - wrapRect.left;
                const offsetY = event.clientY - wrapRect.top;

                let left = offsetX + 14;
                let top = offsetY - tooltipRect.height - 14;
                if (left + tooltipRect.width > wrapRect.width - 8) {
                    left = wrapRect.width - tooltipRect.width - 8;
                }
                if (left < 8) {
                    left = 8;
                }
                if (top < 8) {
                    top = offsetY + 14;
                }
                tooltip.style.left = `${left}px`;
                tooltip.style.top = `${top}px`;
            };

            const hideTooltip = () => {
                if (tooltip) {
                    tooltip.classList.remove('is-visible');
                }
                if (hoverIndex !== -1) {
                    hoverIndex = -1;
                    drawChart();
                }
            };

            const handleMove = (event) => {
                const rect = canvas.getBoundingClientRect();
                const x = event.clientX - rect.left;
                const y = event.clientY - rect.top;
                const index = barRects.findIndex((bar) => x >= bar.x && x <= bar.x + bar.width && y >= bar.y && y <= bar.y + bar.height);

                if (index !== hoverIndex) {
                    hoverIndex = index;
                    drawChart();
                }

                if (index === -1) {
                    hideTooltip();
                    return;
                }

                updateTooltip(barRects[index], event);
            };

            drawChart();
            window.addEventListener('resize', drawChart);
            if (chartWrap) {
                chartWrap.addEventListener('mousemove', handleMove);
                chartWrap.addEventListener('mouseleave', hideTooltip);
            }
        })();
    </script>
</body>
</html>