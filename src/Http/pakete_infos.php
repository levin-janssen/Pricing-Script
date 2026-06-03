<?php
error_reporting(E_ALL);
ini_set('default_charset', 'UTF-8');
mb_internal_encoding("UTF-8");

require_once __DIR__ . '/../../config/db_connection.php';
require_once __DIR__ . '/../../config/marketplaces.php';
$pdo = $dbConnectionTric;

$datum = $_GET["datum"] ?? date("d.m.Y");
if ($datum == "") $datum = date("d.m.Y");

$datum_morgen = date("d.m.Y", strtotime($datum) + (3600 * 24));
$datum_gestern = date("d.m.Y", strtotime($datum) - (3600 * 24));

// Data fetching - KPIs
$sql_open = "SELECT titel, count(*) as Anzahl FROM `lieferungen` JOIN scanstation on scanstation_ID=scanstation.id WHERE versandart='' GROUP BY scanstation_ID";
$open_pkgs = $pdo->query($sql_open)->fetchAll(PDO::FETCH_ASSOC);

$sql_day = "SELECT versandedit, count(*) as Pakete FROM `lieferungen` WHERE versandart='ja' AND SUBSTRING(versanddatum,1,10) = '".(DateTime::createFromFormat('d.m.Y', $datum)->format('Y-m-d'))."' GROUP BY versandedit ORDER BY `Pakete` DESC";
$day_pkgs = $pdo->query($sql_day)->fetchAll(PDO::FETCH_ASSOC);

$sql_month = "SELECT versandedit, count(*) as Pakete FROM `lieferungen` WHERE versandart='ja' AND versanddatum LIKE '".date("Y-m-")."%' GROUP BY versandedit ORDER BY `Pakete` DESC";
$month_pkgs = $pdo->query($sql_month)->fetchAll(PDO::FETCH_ASSOC);
$month_sum = array_sum(array_column($month_pkgs, 'Pakete'));

// Base Order Statement
$orderStmt = $pdo->prepare("
    SELECT bp.einzelpreis, bp.steuer, bw.titel, bp.kundennummer, kfa.wert2 AS land
    FROM bestellungen b
    JOIN (
        SELECT bestellungsid, kundennummer, einzelpreis, steuer
        FROM bestellungen_positionen 
        WHERE produktid = (
            SELECT produktid 
            FROM produkte_felder_werte 
            WHERE feldid = 44 AND wert1 = :artnr
        ) 
        ORDER BY datum DESC 
        LIMIT 3
    ) bp ON b.ID = bp.bestellungsid
    LEFT JOIN kunden_felder_werte kfw ON kfw.kundennummer = bp.kundennummer AND kfw.feldid = 48
    LEFT JOIN kunden_felder_auswahl kfa ON kfa.auswahlid = kfw.wert1 AND kfa.feldid = 48
    JOIN bestellungen_werbekennzeichen bw ON bw.ID = b.werbekennzeichen
    LIMIT 25
");

function getMarketplaceLabel(?string $land, array $marketplaces): string {
    $land = strtoupper(trim((string)$land));

    if ($land === '') {
        return 'Unbekannter Marktplatz';
    }

    return $land;
}

function fetchTableData($pdo, $sql, $orderStmt, array $marketplaces) {
    $data = [];
    $replacements = [
        '(Otto)' => '<img src="img/otto.png" alt="Otto" width="35" height="16" style="vertical-align: middle;">',
        '(Amazon)' => '<img src="img/amazon.png" alt="Amazon" width="16" height="16" style="vertical-align: middle;">',
        '(manomano)' => '<img src="img/manomano.png" alt="Manomano" width="16" height="16" style="vertical-align: middle;">',
        '(eBay)' => '<img src="img/ebay.png" alt="eBay" width="40" height="16" style="vertical-align: middle;">'
    ];

    foreach ($pdo->query($sql) as $row) {
        $artnr = $row['artnr'];
        $titel = empty($row['titel']) ? $row['keywords'] : $row['titel'];
        $formattedTitle = mb_convert_encoding($titel, 'UTF-8', mb_detect_encoding($titel, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true));
        
        $titleStyle = 'text-main';
        if (substr($artnr, 0, 4) == "8-21") {
            $titleStyle = "color-blue";
        } elseif (substr($artnr, 0, 3) == "10-") {
            $titleStyle = "color-red";
        } elseif (substr($artnr, 0, 5) == "14-15") {
            $titleStyle = "color-green";
        }

        $orderStmt->bindParam(':artnr', $artnr, PDO::PARAM_STR);
        $orderStmt->execute();
        $result = $orderStmt->fetchAll(PDO::FETCH_ASSOC);

        $orders = [];
        foreach ($result as $item) {
            // Steuer auslesen (mit Fallback auf 19%, falls leer)
            $steuer = (isset($item["steuer"]) && is_numeric($item["steuer"])) ? (float)$item["steuer"] : 19.0;
            
            // Multiplikator berechnen (z.B. 19 -> 1.19, 7 -> 1.07, 0 -> 1.00)
            $steuerMultiplikator = 1 + ($steuer / 100);
            
            // Bruttopreis berechnen
            $bruttoPreis = (float)$item["einzelpreis"] * $steuerMultiplikator;

            $marketplaceLabel = getMarketplaceLabel($item['land'] ?? null, $marketplaces);
            $price = number_format($bruttoPreis, 2, ',', '') . "€ <span style='font-size:0.85em; color:var(--muted);'>(" . htmlspecialchars($item["titel"]) . ")</span> <span style='display:inline-block; margin-left:6px; padding:1px 6px; border-radius:999px; background:#eff6ff; color:var(--primary); font-size:0.78em; font-weight:600;'>" . htmlspecialchars($marketplaceLabel) . "</span>";
            $orders[] = str_replace(array_keys($replacements), array_values($replacements), $price);
        }
        while (count($orders) < 3) $orders[] = "";

        $data[] = [
            'anzahl' => $row['anzahl'],
            'artnr' => $artnr,
            'titel' => $formattedTitle,
            'titleStyle' => $titleStyle,
            'orders' => $orders
        ];
    }
    return $data;
}

$mainSql = "
    SELECT SUM(lp.anzahl) as anzahl, pfw.wert1 as artnr, p.titel, p.keywords 
    FROM lieferungen_positionen lp
    JOIN lieferungen l ON l.ID = lp.lieferungsid 
    JOIN produkte p ON p.ID = lp.produktid 
    JOIN produkte_felder_werte pfw ON p.ID = pfw.produktid 
    WHERE l.versandart = '' AND l.kundennummer NOT IN ('126948', '451641', '451642') AND pfw.feldid = '44' AND l.lieferart NOT IN ('16', '17') 
    GROUP BY lp.produktid ORDER BY SUM(lp.anzahl) DESC
";

$vendorSql = "
    SELECT SUM(lp.anzahl) as anzahl, pfw.wert1 as artnr, p.titel, p.keywords 
    FROM lieferungen_positionen lp
    JOIN lieferungen l ON l.ID = lp.lieferungsid 
    JOIN produkte p ON p.ID = lp.produktid 
    JOIN produkte_felder_werte pfw ON p.ID = pfw.produktid 
    WHERE l.versandart = '' AND l.kundennummer IN ('126948', '451641', '451642') AND pfw.feldid = '44' AND l.lieferart NOT IN ('16', '17') 
    GROUP BY lp.produktid ORDER BY SUM(lp.anzahl) DESC
";

$positionen = fetchTableData($pdo, $mainSql, $orderStmt, $marketplaces);
$vendor = fetchTableData($pdo, $vendorSql, $orderStmt, $marketplaces);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Paket- und Versandinfos</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/2.2.2/css/dataTables.dataTables.css" />
    <link rel="icon" type="image/x-icon" href="img/tag.ico" sizes="32x32">

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
            --danger: #ef4444;
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
            max-width: 98%;
            margin: 0 auto;
        }

        /* --- Header & Nav --- */
        .top-nav {
            margin-bottom: 24px;
        }

        .btn-ghost {
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
            background: var(--surface);
            color: var(--ink);
            border: 1px solid var(--stroke);
        }
        .btn-ghost:hover {
            background: var(--surface-soft);
            border-color: var(--muted-light);
        }

        .hero {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 32px;
        }

        .hero .eyebrow {
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--primary);
        }

        .hero h1 {
            font-size: 2.25rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--ink);
        }

        /* --- Dashboard Layout --- */
        .dashboard-grid {
            display: grid;
            grid-template-columns: 340px 1fr;
            gap: 24px;
            align-items: start;
        }

        .kpi-sidebar {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        .tables-content {
            display: flex;
            flex-direction: column;
            gap: 24px;
        }

        /* --- Panels --- */
        .panel {
            background: var(--surface);
            padding: 24px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--stroke);
            position: relative; /* WICHTIG: Damit die absolute Positionierung der Tabelle greift */
        }

        .panel-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            border-bottom: 1px solid var(--stroke);
            padding-bottom: 12px;
        }

        .panel-title {
            font-size: 1.15rem;
            font-weight: 600;
            color: var(--ink);
        }

        /* --- KPI Tables --- */
        .kpi-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.95rem;
        }

        .kpi-table td {
            padding: 8px 0;
            border-bottom: 1px solid var(--stroke);
            color: var(--ink);
        }

        .kpi-table tr:last-child td {
            border-bottom: none;
        }

        .kpi-table td:last-child {
            text-align: right;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }

        /* --- Date Navigator --- */
        .date-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--surface-soft);
            padding: 8px 16px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--stroke);
            margin-bottom: 16px;
        }

        .date-nav a {
            text-decoration: none;
            color: var(--muted);
            font-weight: 600;
            font-size: 1.25rem;
            padding: 0 8px;
            transition: color 0.2s ease;
        }
        
        .date-nav a:hover {
            color: var(--primary);
        }

        .date-nav span {
            font-weight: 600;
            font-size: 0.95rem;
            color: var(--ink);
        }

        /* --- DataTables Overrides --- */
        .data-table-title {
            font-size: 1.25rem;
            font-weight: 600;
            color: var(--ink);
            /* NEU: Absolute Positionierung, um sich die Zeile mit dem Suchfeld zu teilen */
            position: absolute;
            top: 24px;
            left: 24px;
            margin: 0;
            line-height: 32px; /* Zentriert die Schrift vertikal zur Suchbox */
            z-index: 10;
        }

        table.dataTable {
            border-collapse: collapse !important;
            width: 100% !important;
            margin-top: 10px !important;
            margin-bottom: 10px !important;
            border-bottom: 1px solid var(--stroke) !important;
        }

        table.dataTable thead th {
            background-color: var(--surface-soft) !important;
            color: var(--muted) !important;
            font-weight: 600 !important;
            font-size: 0.75rem !important;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 8px 10px !important;
            border-bottom: 1px solid var(--stroke) !important;
            border-top: none !important;
            font-family: 'Space Grotesk', sans-serif !important;
        }

        table.dataTable tbody td {
            vertical-align: middle;
            padding: 6px 10px !important;
            border-bottom: 1px solid var(--stroke) !important;
            font-size: 0.85rem;
            line-height: 1.3;
            transition: background-color 0.15s ease;
            box-sizing: border-box;
        }
        
        table.dataTable.no-footer {
            border-bottom: 1px solid var(--stroke) !important;
        }

        table.dataTable tbody tr:hover td {
            background-color: var(--surface-soft);
        }

        .dt-search {
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
            min-height: 32px; /* Setzt die Höhe passend zur line-height der Überschrift */
        }
        
        .dt-search label {
            color: var(--muted);
            font-size: 0.90rem;
            font-weight: 500;
        }

        .dt-search input {
            border: 1px solid var(--stroke);
            border-radius: var(--radius-sm);
            padding: 6px 10px;
            font-family: inherit;
            font-size: 0.90rem;
            outline: none;
            transition: all 0.2s ease;
            width: 200px;
        }
        
        .dt-search input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--ring);
        }
        
        .dt-scroll-body {
            border-bottom: none !important;
        }

        /* --- Typography Helpers --- */
        .color-blue { color: #2563eb; font-weight: 500; }
        .color-red { color: #dc2626; font-weight: 500; }
        .color-green { color: #16a34a; font-weight: 500; }
        .text-main { color: var(--ink); font-weight: 500; }
        .red-text { color: var(--danger) !important; font-weight: 600; background-color: #fef2f2; border-radius: 4px; padding: 2px 4px; }
        .dt-left { text-align: left !important; }
        
        .artnr-link {
            color: var(--primary);
            font-weight: 600;
            text-decoration: none;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.95em;
        }
        .artnr-link:hover {
            text-decoration: underline;
        }

        @media (max-width: 1200px) {
            .dashboard-grid { grid-template-columns: 1fr; }
            .kpi-sidebar {
                flex-direction: row;
                flex-wrap: wrap;
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            }
        }
        @media (max-width: 768px) {
            body { padding: 24px 16px 40px; }
            .kpi-sidebar { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/global_header.php'; ?>
    <div class="page">

        <!-- <header class="hero">
            <p class="eyebrow">Logistik</p>
            <h1>Paket- und Versandinfos</h1>
        </header> -->

        <div class="dashboard-grid">
            
            <aside class="kpi-sidebar">
                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">Offene Stationen</div>
                    </div>
                    <table class="kpi-table">
                        <?php if(empty($open_pkgs)): ?>
                            <tr><td colspan="2" style="text-align: center; color: var(--muted); border: none;">Keine offenen Lieferungen</td></tr>
                        <?php else: ?>
                            <?php foreach($open_pkgs as $row): ?>
                                <tr><td><?= htmlspecialchars($row['titel']) ?></td><td><?= $row['Anzahl'] ?></td></tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </table>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">Tagesübersicht</div>
                    </div>
                    <div class="date-nav">
                        <a href="?datum=<?= $datum_gestern ?>">&laquo;</a>
                        <span><?= htmlspecialchars($datum) ?></span>
                        <a href="?datum=<?= $datum_morgen ?>">&raquo;</a>
                    </div>
                    <table class="kpi-table">
                        <?php if(empty($day_pkgs)): ?>
                            <tr><td colspan="2" style="text-align: center; color: var(--muted); border: none;">Keine Daten für diesen Tag</td></tr>
                        <?php else: ?>
                            <?php foreach($day_pkgs as $row): ?>
                                <?php $v = empty($row['versandedit']) ? "amazon FBA" : $row['versandedit']; ?>
                                <tr><td><?= htmlspecialchars($v) ?></td><td><?= $row['Pakete'] ?></td></tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </table>
                </div>

                <div class="panel">
                    <div class="panel-header">
                        <div class="panel-title">Monatsübersicht (<?= date("F Y") ?>)</div>
                    </div>
                    <table class="kpi-table">
                        <?php if(empty($month_pkgs)): ?>
                            <tr><td colspan="2" style="text-align: center; color: var(--muted); border: none;">Keine Daten für diesen Monat</td></tr>
                        <?php else: ?>
                            <?php foreach($month_pkgs as $row): ?>
                                <?php $v = empty($row['versandedit']) ? "amazon FBA" : $row['versandedit']; ?>
                                <tr><td><?= htmlspecialchars($v) ?></td><td><?= $row['Pakete'] ?></td></tr>
                            <?php endforeach; ?>
                            <tr style="border-top: 2px solid var(--stroke);">
                                <td style="padding-top: 16px;"><strong>Summe</strong></td>
                                <td style="padding-top: 16px; color: var(--primary);"><strong><?= $month_sum ?></strong></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </aside>

            <main class="tables-content">
                <div class="panel">
                    <span class="data-table-title">Positionen (Regulär)</span>
                    <table id="resultTable" class="display" style="width:100%">
                        <thead>
                            <tr>
                                <th width="8%">Anzahl</th>
                                <th width="35%">Artikel</th>
                                <th>Art-Nr</th>
                                <th>Order 1</th>
                                <th>Order 2</th>
                                <th>Order 3</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($positionen as $row): ?>
                                <tr>
                                    <td style="text-align: center; font-weight: 600; font-size: 0.95rem;"><?= $row['anzahl'] ?></td>
                                    <td><span class="<?= $row['titleStyle'] ?>"><?= $row['titel'] ?></span></td>
                                    <td><a href="http://192.168.3.191:888/?ArtNr=<?= urlencode($row['artnr']) ?>" target="_blank" class="artnr-link"><?= htmlspecialchars($row['artnr']) ?></a></td>
                                    <td><?= $row['orders'][0] ?></td>
                                    <td><?= $row['orders'][1] ?></td>
                                    <td><?= $row['orders'][2] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="panel">
                    <span class="data-table-title">Vendor (Spezial)</span>
                    <table id="vendorTable" class="display" style="width:100%">
                        <thead>
                            <tr>
                                <th width="8%">Anzahl</th>
                                <th width="35%">Artikel</th>
                                <th>Art-Nr</th>
                                <th>Order 1</th>
                                <th>Order 2</th>
                                <th>Order 3</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($vendor as $row): ?>
                                <tr>
                                    <td style="text-align: center; font-weight: 600; font-size: 0.95rem;"><?= $row['anzahl'] ?></td>
                                    <td><span class="<?= $row['titleStyle'] ?>"><?= $row['titel'] ?></span></td>
                                    <td><a href="http://192.168.3.191:888/?ArtNr=<?= urlencode($row['artnr']) ?>" target="_blank" class="artnr-link"><?= htmlspecialchars($row['artnr']) ?></a></td>
                                    <td><?= $row['orders'][0] ?></td>
                                    <td><?= $row['orders'][1] ?></td>
                                    <td><?= $row['orders'][2] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </main>
            
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.datatables.net/2.2.2/js/dataTables.js"></script>
    <script>
        function checkAndColorRows(tableId) {
            const table = document.getElementById(tableId);
            if(!table) return;
            for (let i = 1; i < table.rows.length; i++) {
                const row = table.rows[i];
                if (row.cells.length < 6) continue;
                
                const col3 = row.cells[3].innerHTML.trim();
                const col4 = row.cells[4].innerHTML.trim();
                const col5 = row.cells[5].innerHTML.trim();

                if (col3 && col4 && col5 && (col3 !== col4 || col3 !== col5 || col4 !== col5)) {
                    row.cells[3].classList.add('red-text');
                    row.cells[4].classList.add('red-text');
                    row.cells[5].classList.add('red-text');
                }
            }
        }

        $(document).ready(function() {
            let commonOptions = {
                paging: false,
                scrollY: '75vh', 
                scrollCollapse: true,
                info: false,
                order: [[0, 'desc']],
                language: {
                    search: "Suchen:"
                },
                columnDefs: [
                    { targets: [3, 4, 5], className: 'dt-left' }
                ],
                drawCallback: function(settings) {
                    checkAndColorRows(settings.sTableId);
                }
            };

            new DataTable('#resultTable', commonOptions);
            new DataTable('#vendorTable', commonOptions);
        });
    </script>
</body>
</html>