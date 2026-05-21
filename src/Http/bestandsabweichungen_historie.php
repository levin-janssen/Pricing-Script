<?php

declare(strict_types=1);

date_default_timezone_set('Europe/Berlin');

$logDir = APP_ROOT . '/logs';
$asinRaw = isset($_GET['asin']) ? trim((string)$_GET['asin']) : '';
$asin = '';
$warning = '';
$error = '';
$entries = [];

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

            foreach ($lines as $line) {
                if (strpos($line, 'Bestandsabweichung festgestellt!') === false) {
                    continue;
                }
                if (stripos($line, $asin) === false) {
                    continue;
                }
                if (!preg_match('/^\[(\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})\] \[[A-Z]+\] .*? \| Context: (.+)$/', $line, $matches)) {
                    continue;
                }

                $context = json_decode(trim($matches[3]), true);
                if (!is_array($context)) {
                    $context = [];
                }
                if (($context['asin'] ?? '') !== $asin) {
                    continue;
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

        usort($entries, static function (array $left, array $right): int {
            return strcmp($left['timestamp'], $right['timestamp']);
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
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=Source+Serif+4:wght@400;600&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/moment"></script>
    <script src="https://cdn.jsdelivr.net/npm/chartjs-adapter-moment"></script>
    <style>
        :root {
            --ink: #1c1c1c;
            --muted: #60646c;
            --accent: #2bb4ff;
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
            background: radial-gradient(900px circle at top right, #eff7ff 0%, #f7f9fc 45%, #f3f7f5 70%, #f4f4f4 100%);
            margin: 0;
            padding: 32px 20px 60px;
        }
        .page {
            max-width: 1100px;
            margin: 0 auto;
        }
        .topbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 24px;
        }
        .back {
            text-decoration: none;
            color: var(--ink);
            background: var(--surface);
            border: 1px solid var(--stroke);
            padding: 8px 14px;
            border-radius: 999px;
            font-weight: 600;
        }
        h1 {
            font-size: 2.2rem;
            margin: 0 0 6px;
        }
        .subtitle {
            font-family: "Source Serif 4", serif;
            color: var(--muted);
            margin: 0;
        }
        .panel {
            background: var(--surface);
            padding: 20px 22px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }
        .search-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
        }
        label {
            font-weight: 600;
            color: var(--muted);
        }
        input[type="text"] {
            padding: 10px 12px;
            border-radius: 10px;
            border: 1px solid var(--stroke);
            font-size: 0.95rem;
            background: var(--surface-soft);
            min-width: 200px;
        }
        select {
            padding: 8px 10px;
            border-radius: 10px;
            border: 1px solid var(--stroke);
            font-size: 0.95rem;
            background: var(--surface-soft);
        }
        button {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 16px;
            border-radius: 999px;
            border: none;
            background: linear-gradient(120deg, var(--accent), #5ad1ff);
            color: #0f1c24;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }
        button:hover {
            transform: translateY(-1px);
            box-shadow: 0 10px 20px rgba(43, 180, 255, 0.25);
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
        .stats {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 16px;
        }
        .chart-controls {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-top: 16px;
        }
        .stat {
            padding: 10px 14px;
            border-radius: 12px;
            border: 1px solid var(--stroke);
            background: var(--surface-soft);
            font-weight: 600;
        }
        .chart-wrap {
            background: var(--surface);
            border-radius: var(--radius);
            padding: 16px;
            border: 1px solid var(--stroke);
            box-shadow: var(--shadow);
            height: 360px;
        }
        .chart-wrap canvas {
            width: 100%;
            min-height: 320px;
        }
        .table-wrap {
            margin-top: 20px;
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
        }
        tr:nth-child(even) td {
            background: #fbfcfe;
        }
        .empty {
            padding: 20px;
            text-align: center;
            color: var(--muted);
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
        }
        @media (max-width: 720px) {
            h1 {
                font-size: 1.9rem;
            }
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="topbar">
            <a class="back" href="bestandsabweichungen.php">Zurueck</a>
        </div>

        <div class="panel">
            <h1>ASIN Historie</h1>
            <p class="subtitle">Zeitliche Entwicklung der Bestandsabweichungen in allen Logs.</p>
            <form method="get" class="search-row">
                <label for="asin">ASIN</label>
                <input type="text" id="asin" name="asin" value="<?= h($asinRaw) ?>" placeholder="B000000000">
                <button type="submit">Historie laden</button>
            </form>
            <?php if ($warning): ?>
                <div class="warning"><?= h($warning) ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="error"><?= h($error) ?></div>
            <?php endif; ?>
            <?php if ($asin !== '' && !$error): ?>
                <div class="stats">
                    <div class="stat">Eintraege: <?= count($entries) ?></div>
                    <?php if (!empty($entries)): ?>
                        <div class="stat">Von: <?= h($entries[0]['timestamp']) ?></div>
                        <div class="stat">Bis: <?= h($entries[count($entries) - 1]['timestamp']) ?></div>
                    <?php endif; ?>
                </div>
                <?php if (!empty($entries)): ?>
                    <div class="chart-controls">
                        <label for="timespan">Zeitraum</label>
                        <select id="timespan">
                            <option value="1">Letzte 24h</option>
                            <option value="7" selected>Letzte 7 Tage</option>
                            <option value="30">Letzte 30 Tage</option>
                            <option value="90">Letzte 90 Tage</option>
                            <option value="365">Letztes Jahr</option>
                            <option value="all">Gesamter Zeitraum</option>
                        </select>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($asin !== '' && !$error): ?>
            <?php if (empty($entries)): ?>
                <div class="empty">Keine Eintraege fuer diese ASIN gefunden.</div>
            <?php else: ?>
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
                                <th>Tricoma neu</th>
                                <th>Diff</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($entries as $entry): ?>
                                <tr>
                                    <td><?= h($entry['date']) ?></td>
                                    <td><?= h($entry['time']) ?></td>
                                    <td><?= h((string)$entry['sku']) ?></td>
                                    <td><?= h(formatValue($entry['amazon_bisher'])) ?></td>
                                    <td><?= h(formatValue($entry['tricoma_neu'])) ?></td>
                                    <td><?= h(formatValue($entry['diff'])) ?></td>
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
        const historyData = <?= json_encode($entries) ?>;
        let currentChart;
        let currentData = historyData;

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
                            label: 'Tricoma Bestand',
                            data: series.tricoma,
                            borderColor: 'rgba(43, 180, 255, 1)',
                            backgroundColor: 'rgba(43, 180, 255, 0.2)',
                            tension: 0.4,
                            cubicInterpolationMode: 'monotone',
                            pointRadius: 2,
                            spanGaps: true
                        },
                        {
                            label: 'Amazon Bestand',
                            data: series.amazon,
                            borderColor: 'rgba(255, 122, 24, 1)',
                            backgroundColor: 'rgba(255, 122, 24, 0.2)',
                            tension: 0.4,
                            cubicInterpolationMode: 'monotone',
                            pointRadius: 2,
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
                                pointStyle: 'circle'
                            }
                        },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function (context) {
                                    const value = context.parsed.y;
                                    return `${context.dataset.label}: ${formatNumber(value)}`;
                                },
                                footer: function (items) {
                                    if (!items.length) return '';
                                    const index = items[0].dataIndex;
                                    const tricoma = Number(currentData[index]?.tricoma_neu);
                                    const amazon = Number(currentData[index]?.amazon_bisher);
                                    if (!Number.isFinite(tricoma) || !Number.isFinite(amazon)) return '';
                                    const delta = tricoma - amazon;
                                    return `Delta (T - A): ${formatNumber(delta)}`;
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
                            title: {
                                display: true,
                                text: 'Zeit'
                            }
                        },
                        y: {
                            title: {
                                display: true,
                                text: 'Bestand'
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
