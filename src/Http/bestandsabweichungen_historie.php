<?php

declare(strict_types=1);

// UTF-8 erzwingen
ini_set('default_charset', 'UTF-8');
header('Content-Type: text/html; charset=utf-8');

date_default_timezone_set('Europe/Berlin');

require_once APP_ROOT . '/config/marketplaces.php';

$logDir = APP_ROOT . '/logs';
$asinRaw = isset($_GET['asin']) ? trim((string)$_GET['asin']) : '';
$asin = '';
$warning = '';
$error = '';
$entries = [];
$selectedCountry = isset($_GET['country']) ? strtoupper(trim((string)$_GET['country'])) : '';
if ($selectedCountry === '' || !isset($marketplaces[$selectedCountry])) {
    $selectedCountry = isset($marketplaces['DE']) ? 'DE' : (array_key_first($marketplaces) ?? '');
}

if ($asinRaw !== '') {
    $asinRaw = strtoupper($asinRaw);
    if (preg_match('/^[A-Z0-9]{10}$/', $asinRaw)) {
        $asin = $asinRaw;
    } else {
        $warning = 'Ungueltige ASIN. Format: 10 Zeichen (Grossbuchstaben/Ziffern).';
    }
}

if ($asin !== '') {
    if (!is_dir($logDir)) {
        $error = 'Log-Verzeichnis nicht gefunden.';
    } else {
        foreach (glob($logDir . '/app_*.log') as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES);
            if ($lines === false) {
                $error = 'Mindestens eine Logdatei konnte nicht gelesen werden.';
                continue;
            }

            $skuToAsin = [];
            foreach ($lines as $line) {
                if (!preg_match('/^\[(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})\] \[[A-Z]+\] .*? \| Context: (.+)$/', $line, $matches)) {
                    continue;
                }

                $context = json_decode(trim($matches[3]), true);
                if (!is_array($context)) {
                    continue;
                }

                $sku = $context['sku'] ?? '';
                $contextAsin = $context['asin'] ?? '';
                if ($sku !== '' && preg_match('/^[A-Z0-9]{10}$/', (string)$contextAsin)) {
                    $skuToAsin[$sku] = strtoupper((string)$contextAsin);
                }
            }

            foreach ($lines as $line) {
                $hasDeviation = strpos($line, 'Bestandsabweichung festgestellt!') !== false;
                $hasSync = strpos($line, 'Bestand ist synchron') !== false;
                if (!$hasDeviation && !$hasSync) {
                    continue;
                }

                if (!preg_match('/^\[(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})\] \[[A-Z]+\] .*? \| Context: (.+)$/', $line, $matches)) {
                    continue;
                }

                $context = json_decode(trim($matches[3]), true);
                if (!is_array($context)) {
                    $context = [];
                }

                $sku = $context['sku'] ?? '';
                $entryAsin = $context['asin'] ?? ($sku !== '' ? ($skuToAsin[$sku] ?? '') : '');
                $entryAsin = strtoupper((string)$entryAsin);
                if (!preg_match('/^[A-Z0-9]{10}$/', $entryAsin)) {
                    $entryAsin = '';
                }
                if ($entryAsin !== $asin) {
                    continue;
                }

                $amazon = $context['amazon_bisher'] ?? null;
                $tricoma = $context['tricoma_neu'] ?? null;
                $tricoma_pure = $context['tricoma_pure'] ?? null;

                if ($hasSync) {
                    $quantity = $context['quantity'] ?? null;
                    if ($quantity !== null && $quantity !== '') {
                        $amazon = $quantity;
                        $tricoma = $quantity;
                    }
                }

                $diff = null;
                if (is_numeric($amazon) && is_numeric($tricoma)) {
                    $diff = (float)$tricoma - (float)$amazon;
                }

                $entries[] = [
                    'date' => $matches[1],
                    'time' => $matches[2],
                    'timestamp' => $matches[1] . ' ' . $matches[2],
                    'sku' => $sku,
                    'asin' => $entryAsin,
                    'amazon_bisher' => $amazon,
                    'tricoma_neu' => $tricoma,
                    'tricoma_pure' => $tricoma_pure,
                    'diff' => $diff,
                ];
            }
        }

        usort($entries, static function (array $left, array $right): int {
            return strcmp($right['timestamp'], $left['timestamp']);
        });
    }
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
    <title>ASIN Historie</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/moment"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-moment"></script>
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
            background: radial-gradient(1200px circle at top right, #fff1e6 0%, #f2f6ff 42%, #eefbf7 70%, #f4f4f4 100%);
            background-attachment: fixed;
            padding: 40px 20px 80px;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        .page {
            max-width: 1200px;
            margin: 0 auto;
        }

        /* --- Header & Navigation --- */
        .topbar {
            margin-bottom: 32px;
        }

        .back {
            display: inline-flex;
            align-items: center;
            height: 36px;
            padding: 0 16px;
            border-radius: 999px;
            border: 1px solid var(--stroke);
            text-decoration: none;
            color: var(--ink);
            background: var(--surface);
            font-weight: 600;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
        }

        .back:hover {
            background: var(--surface-soft);
            border-color: var(--muted-light);
            transform: translateY(-1px);
        }

        .header-text {
            margin-bottom: 24px;
        }

        .header-text h1 {
            font-size: 2.25rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin-bottom: 8px;
        }

        .header-text .subtitle {
            color: var(--muted);
            font-size: 1rem;
        }

        /* --- Panels --- */
        .panel {
            background: var(--surface);
            padding: 24px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--stroke);
            margin-bottom: 24px;
        }

        /* --- Forms & Controls --- */
        .filter-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            align-items: flex-end;
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

        input:hover, select:hover {
            border-color: var(--muted-light);
        }

        input:focus, select:focus {
            outline: none;
            border-color: var(--accent);
            box-shadow: 0 0 0 3px var(--ring);
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
            white-space: nowrap;
        }

        button:hover {
            background: #1e293b;
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }

        .ghost-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 42px;
            padding: 0 24px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--stroke);
            background: var(--surface);
            color: var(--ink);
            font-family: inherit;
            font-weight: 600;
            font-size: 0.95rem;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: var(--shadow-sm);
        }

        .ghost-button:hover {
            background: var(--surface-soft);
            border-color: var(--muted-light);
        }

        /* --- Alerts --- */
        .alert {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            margin-top: 20px;
            font-size: 0.95rem;
            font-weight: 500;
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

        /* --- Stats --- */
        .hero-stats {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--stroke);
        }

        .stat-card {
            background: var(--surface-soft);
            border: 1px solid var(--stroke);
            border-radius: var(--radius-sm);
            padding: 12px 16px;
            min-width: 140px;
            flex: 1;
        }

        .stat-label {
            font-size: 0.75rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            font-weight: 600;
        }

        .stat-value {
            font-size: 1.1rem;
            font-weight: 600;
            margin-top: 4px;
            color: var(--ink);
            font-variant-numeric: tabular-nums;
        }

        /* --- Toolbar (Above Chart) --- */
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 16px;
        }

        .toolbar-group {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* --- Chart --- */
        .chart-wrap {
            background: var(--surface);
            border-radius: var(--radius);
            padding: 20px;
            border: 1px solid var(--stroke);
            box-shadow: var(--shadow);
            height: 400px;
            margin-bottom: 24px;
        }

        .chart-wrap canvas {
            width: 100%;
            height: 100%;
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
            .filter-grid {
                grid-template-columns: 1fr;
            }
            button {
                width: 100%;
            }
            .hero-stats {
                flex-direction: column;
            }
            .toolbar {
                flex-direction: column;
                align-items: flex-start;
            }
            .toolbar-group {
                width: 100%;
            }
            .toolbar-group select, .ghost-button {
                flex: 1;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <a class="back" href="bestandsabweichungen.php">&larr; Start</a>
        </div>

        <div class="header-text">
            <h1>ASIN Historie</h1>
            <p class="subtitle">Zeitliche Entwicklung der Bestandsabweichungen analysieren.</p>
        </div>

        <div class="panel">
            <form method="get" class="filter-grid">
                <div class="field">
                    <label for="asin">ASIN</label>
                    <input type="text" id="asin" name="asin" value="<?= h($asinRaw) ?>" placeholder="B000000000">
                </div>
                <div class="field">
                    <label for="country">Land</label>
                    <select id="country" name="country">
                        <?php foreach ($marketplaces as $code => $details): ?>
                            <option value="<?= h($code) ?>" <?= $code === $selectedCountry ? 'selected' : '' ?>>
                                <?= h($details['name']) ?> (<?= h($code) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <button type="submit">Historie laden</button>
                </div>
            </form>

            <?php if ($warning): ?>
                <div class="alert warning"><?= h($warning) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert error"><?= h($error) ?></div>
            <?php endif; ?>

            <?php if ($asin !== '' && !$error): ?>
                <div class="hero-stats">
                    <div class="stat-card">
                        <div class="stat-label">Gesamte Eintraege</div>
                        <div class="stat-value"><?= count($entries) ?></div>
                    </div>
                    <?php if (!empty($entries)): ?>
                        <div class="stat-card">
                            <div class="stat-label">Erster Eintrag</div>
                            <div class="stat-value"><?= h($entries[count($entries) - 1]['timestamp']) ?></div>
                        </div>
                        <div class="stat-card">
                            <div class="stat-label">Letzter Eintrag</div>
                            <div class="stat-value"><?= h($entries[0]['timestamp']) ?></div>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($asin !== '' && !$error): ?>
            <?php if (empty($entries)): ?>
                <div class="empty">Keine Eintraege fuer diese ASIN gefunden.</div>
            <?php else: ?>
                
                <div class="toolbar">
                    <div class="toolbar-group">
                        <a class="ghost-button" href="results.php?asin=<?= urlencode($asin) ?>&country=<?= urlencode($selectedCountry) ?>">
                            Zur Ergebnis-Seite
                        </a>
                    </div>
                    <div class="toolbar-group">
                        <div class="field" style="flex-direction: row; align-items: center;">
                            <label for="timespan" style="margin:0; white-space:nowrap;">Zeitraum:</label>
                            <select id="timespan" style="min-width: 180px;">
                                <option value="1">Letzte 24h</option>
                                <option value="7" selected>Letzte 7 Tage</option>
                                <option value="30">Letzte 30 Tage</option>
                                <option value="90">Letzte 90 Tage</option>
                                <option value="365">Letztes Jahr</option>
                                <option value="all">Gesamter Zeitraum</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="chart-wrap">
                    <canvas id="stockChart"></canvas>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Datum</th>
                                <th>Zeit</th>
                                <th>SKU</th>
                                <th>Amazon bisher</th>
                                <th>Tricoma (Netto)</th>
                                <th>Tricoma (Roh)</th>
                                <th>Diff (T. Netto - A.)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entries as $entry): ?>
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
                                    <td><?= h(formatValue($entry['amazon_bisher'])) ?></td>
                                    <td><?= h(formatValue($entry['tricoma_neu'])) ?></td>
                                    <td><?= h(formatValue($entry['tricoma_pure'])) ?></td>
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
        <?php endif; ?>
    </div>

    <?php if (!empty($entries)): ?>
    <script>
        // Sortiere für Chart.js aufsteigend nach Timestamp
        const historyData = <?= json_encode(array_reverse($entries)) ?>;
        let currentChart;
        let currentData = historyData;

        Chart.defaults.font.family = '"Space Grotesk", system-ui, sans-serif';
        Chart.defaults.color = '#64748b';

        function formatNumber(value) {
            if (!Number.isFinite(value)) return '-';
            return new Intl.NumberFormat('de-DE', { maximumFractionDigits: 2 }).format(value);
        }

        function filterByTimespan(data, timespan) {
            if (!Array.isArray(data) || data.length === 0) return [];
            if (timespan === 'all') return data;
            const latest = moment(data[data.length - 1].timestamp);
            const cutoff = latest.clone().subtract(Number(timespan), 'days');
            return data.filter(entry => moment(entry.timestamp).isSameOrAfter(cutoff));
        }

        function buildSeries(data) {
            return {
                labels: data.map(entry => entry.timestamp),
                tricoma: data.map(entry => {
                    if (entry.tricoma_neu === null || entry.tricoma_neu === '') return null;
                    const value = Number(entry.tricoma_neu);
                    return Number.isFinite(value) ? value : null;
                }),
                tricoma_pure: data.map(entry => {
                    if (entry.tricoma_pure === null || entry.tricoma_pure === '') return null;
                    const value = Number(entry.tricoma_pure);
                    return Number.isFinite(value) ? value : null;
                }),
                amazon: data.map(entry => {
                    if (entry.amazon_bisher === null || entry.amazon_bisher === '') return null;
                    const value = Number(entry.amazon_bisher);
                    return Number.isFinite(value) ? value : null;
                })
            };
        }

        function renderChart(data, timespan) {
            const ctx = document.getElementById('stockChart')?.getContext('2d');
            if (!ctx) return;

            currentData = data;
            const series = buildSeries(data);
            const unit = (timespan === '1' || timespan === '7') ? 'hour' : 'day';

            if (currentChart) {
                currentChart.destroy();
            }

            currentChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: series.labels,
                    datasets: [
                        {
                            label: 'Tricoma (Verfügbar)',
                            data: series.tricoma,
                            borderColor: '#0ea5e9', // Tailwind sky-500
                            backgroundColor: 'rgba(14, 165, 233, 0.1)',
                            tension: 0.4,
                            cubicInterpolationMode: 'monotone',
                            pointRadius: 2,
                            pointHoverRadius: 5,
                            spanGaps: true
                        },
                        {
                            label: 'Tricoma (Roh)',
                            data: series.tricoma_pure,
                            borderColor: '#8b5cf6', // Tailwind violet-500
                            backgroundColor: 'rgba(139, 92, 246, 0.1)',
                            tension: 0.4,
                            cubicInterpolationMode: 'monotone',
                            pointRadius: 2,
                            pointHoverRadius: 5,
                            borderDash: [5, 5],
                            spanGaps: true
                        },
                        {
                            label: 'Amazon Bestand',
                            data: series.amazon,
                            borderColor: '#ea580c', // Tailwind orange-600
                            backgroundColor: 'rgba(234, 88, 12, 0.1)',
                            tension: 0.4,
                            cubicInterpolationMode: 'monotone',
                            pointRadius: 2,
                            pointHoverRadius: 5,
                            spanGaps: true
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: {
                        mode: 'index',
                        intersect: false
                    },
                    plugins: {
                        legend: {
                            labels: {
                                usePointStyle: true,
                                pointStyle: 'circle',
                                padding: 20
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            backgroundColor: 'rgba(15, 23, 42, 0.9)',
                            titleFont: { size: 13, weight: '600' },
                            bodyFont: { size: 13 },
                            padding: 12,
                            cornerRadius: 8,
                            callbacks: {
                                label: function (context) {
                                    const value = context.parsed.y;
                                    return ` ${context.dataset.label}: ${formatNumber(value)}`;
                                },
                                footer: function (items) {
                                    if (!items.length) return '';
                                    const index = items[0].dataIndex;
                                    const tricoma = Number(currentData[index]?.tricoma_neu);
                                    const amazon = Number(currentData[index]?.amazon_bisher);
                                    if (!Number.isFinite(tricoma) || !Number.isFinite(amazon)) return '';
                                    const delta = tricoma - amazon;
                                    const sign = delta > 0 ? '+' : '';
                                    return `\nDifferenz (T. Netto - A.): ${sign}${formatNumber(delta)}`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            type: 'time',
                            time: {
                                unit: unit,
                                tooltipFormat: 'YYYY-MM-DD HH:mm:ss'
                            },
                            grid: {
                                color: '#f1f5f9'
                            },
                            title: {
                                display: false
                            }
                        },
                        y: {
                            grid: {
                                color: '#f1f5f9'
                            },
                            title: {
                                display: false
                            },
                            ticks: {
                                precision: 0
                            }
                        }
                    }
                }
            });
        }

        function updateChart() {
            const timespan = document.getElementById('timespan')?.value || '7';
            const filtered = filterByTimespan(historyData, timespan);
            renderChart(filtered, timespan);
        }

        document.getElementById('timespan')?.addEventListener('change', updateChart);
        updateChart();
    </script>
    <?php endif; ?>
</body>
</html>