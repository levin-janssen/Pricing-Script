<?php
error_reporting(E_ALL);
ini_set('default_charset', 'UTF-8');
mb_internal_encoding("UTF-8");

require_once __DIR__ . '/../../config/db_connection.php';
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
    SELECT bp.einzelpreis, bw.titel
    FROM bestellungen b
    JOIN (
        SELECT bestellungsid, einzelpreis
        FROM bestellungen_positionen 
        WHERE produktid = (
            SELECT produktid 
            FROM produkte_felder_werte 
            WHERE feldid = 44 AND wert1 = :artnr
        ) 
        ORDER BY datum DESC 
        LIMIT 3
    ) bp ON b.ID = bp.bestellungsid
    JOIN bestellungen_werbekennzeichen bw ON bw.ID = b.werbekennzeichen
    LIMIT 25
");

function fetchTableData($pdo, $sql, $orderStmt) {
    $data = [];
    $replacements = [
        '(Otto)' => '<img src="img/otto.png" alt="Otto" width="35" height="16">',
        '(Amazon)' => '<img src="img/amazon.png" alt="Amazon" width="16" height="16">',
        '(manomano)' => '<img src="img/manomano.png" alt="Manomano" width="16" height="16">',
        '(eBay)' => '<img src="img/ebay.png" alt="eBay" width="40" height="16">'
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
            $price = number_format((float)$item["einzelpreis"]*1.19, 2, ',', '') . "€ (" . $item["titel"] . ")";
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

$positionen = fetchTableData($pdo, $mainSql, $orderStmt);
$vendor = fetchTableData($pdo, $vendorSql, $orderStmt);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <title>Paket- und Versandinfos</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.datatables.net/2.2.2/css/dataTables.dataTables.css" />
    <style>
        :root {
            --bg-color: #f4f7f6;
            --text-main: #1f2937;
            --text-muted: #6b7280;
            --primary: #374151;
            --primary-hover: #111827;
            --card-bg: #ffffff;
            --border-color: #e5e7eb;
            --danger: #ef4444;
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.05), 0 4px 6px -2px rgba(0, 0, 0, 0.025);
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            margin: 0;
            padding: 0;
            line-height: 1.5;
            -webkit-font-smoothing: antialiased;
        }

        /* Optimiertes Padding und vergrößerte Breite für große Bildschirme */
        .dashboard-container {
            max-width: 1750px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        .header {
            margin-bottom: 2rem;
            padding: 0 0.5rem;
        }
        
        .header-title h1 {
            font-size: 2.25rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin: 0;
            color: var(--text-main);
        }

        .nav-back {
            text-decoration: none;
            color: var(--text-muted);
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 1.25rem;
            padding: 0 0.5rem;
            transition: color 0.2s ease;
        }

        .nav-back:hover {
            color: var(--primary-hover);
        }

        /* Haupt-Layout: Sidebar + Content-Grid */
        .main-content-wrapper {
            display: grid;
            grid-template-columns: 350px 1fr;
            gap: 1.5rem;
            align-items: start;
        }

        .kpi-sidebar {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
        }

        .card {
            background: var(--card-bg);
            border: none;
            border-radius: 16px;
            padding: 1.5rem;
            box-shadow: var(--shadow-md);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .card:hover {
            box-shadow: var(--shadow-lg);
        }

        .card-header-flex {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.25rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.75rem;
        }

        .card-title {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--primary);
        }

        .kpi-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.9rem;
        }

        .kpi-table td {
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border-color);
            color: var(--text-main);
        }

        .kpi-table tr:last-child td {
            border-bottom: none;
        }

        .kpi-table td:last-child {
            text-align: right;
            font-weight: 600;
        }

        .date-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #f9fafb;
            padding: 0.75rem 1rem;
            border-radius: 10px;
            border: 1px solid var(--border-color);
        }

        .date-nav a {
            text-decoration: none;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 1.25rem;
            padding: 0 0.5rem;
            transition: color 0.2s ease;
        }
        
        .date-nav a:hover {
            color: var(--primary-hover);
        }

        .date-nav span {
            font-weight: 600;
            font-size: 0.95rem;
        }

        .tables-content {
            display: flex;
            flex-direction: column;
            gap: 2rem;
        }

        /* Moderne DataTables Anpassungen */
        .dataTables_wrapper {
            background: var(--card-bg);
            border: none;
            border-radius: 16px;
            padding: 1.75rem;
            box-shadow: var(--shadow-md);
        }

        .data-table-title {
            font-size: 1.35rem;
            font-weight: 700;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            color: var(--primary);
            border-bottom: 2px solid var(--border-color);
            letter-spacing: -0.01em;
        }

        table.dataTable {
            border-collapse: collapse !important;
            margin-top: 1rem !important;
            margin-bottom: 1rem !important;
            width: 100% !important;
        }

        table.dataTable thead th {
            background-color: #f9fafb;
            color: var(--text-muted);
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 1rem;
            border-bottom: 2px solid var(--border-color) !important;
            border-top: none !important;
        }

        table.dataTable tbody td {
            vertical-align: middle;
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            font-size: 0.9rem;
            transition: background-color 0.15s ease;
        }

        table.dataTable tbody tr:hover td {
            background-color: #f9fafb;
        }

        /* Modernes Suchfeld */
        .dt-search input {
            border: 1px solid var(--border-color);
            border-radius: 8px;
            padding: 0.5rem 1rem;
            font-family: inherit;
            font-size: 0.875rem;
            outline: none;
            transition: all 0.2s ease;
        }
        .dt-search input:focus {
            border-color: #9ca3af;
            box-shadow: 0 0 0 3px rgba(156, 163, 175, 0.15);
        }

        .color-blue { color: #2563eb; font-weight: 500; }
        .color-red { color: #dc2626; font-weight: 500; }
        .color-green { color: #16a34a; font-weight: 500; }
        .text-main { color: var(--text-main); font-weight: 500; }
        .red-text { color: var(--danger); font-weight: 600; }
        .dt-left { text-align: left !important; }

        /* Responsive Design */
        @media (max-width: 1200px) {
            .main-content-wrapper {
                grid-template-columns: 300px 1fr;
                gap: 1rem;
            }
        }
        @media (max-width: 1024px) {
            .main-content-wrapper {
                grid-template-columns: 1fr;
            }
            .kpi-sidebar {
                flex-direction: row;
                flex-wrap: wrap;
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            }
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <a href="index.php" class="nav-back">
            <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="19" y1="12" x2="5" y2="12"></line>
                <polyline points="12 19 5 12 12 5"></polyline>
            </svg>
            Zurück zum Dashboard
        </a>

        <div class="header">
            <div class="header-title">
                <h1>Paket- und Versandinfos</h1>
            </div>
        </div>

        <div class="main-content-wrapper">
            <aside class="kpi-sidebar">
                <div class="card">
                    <div class="card-header-flex">
                        <div class="card-title">Offene Stationen</div>
                    </div>
                    <table class="kpi-table">
                        <?php if(empty($open_pkgs)): ?>
                            <tr><td colspan="2" style="text-align: center; color: var(--text-muted);">Keine offenen Lieferungen</td></tr>
                        <?php else: ?>
                            <?php foreach($open_pkgs as $row): ?>
                                <tr><td><?= htmlspecialchars($row['titel']) ?></td><td><?= $row['Anzahl'] ?></td></tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </table>
                </div>

                <div class="card">
                    <div class="card-header-flex">
                        <div class="card-title">Tagesübersicht</div>
                    </div>
                    <div class="date-nav mb-3" style="margin-bottom: 1.25rem;">
                        <a href="?datum=<?= $datum_gestern ?>">&laquo;</a>
                        <span><?= htmlspecialchars($datum) ?></span>
                        <a href="?datum=<?= $datum_morgen ?>">&raquo;</a>
                    </div>
                    <table class="kpi-table">
                        <?php if(empty($day_pkgs)): ?>
                            <tr><td colspan="2" style="text-align: center; color: var(--text-muted);">Keine Daten für diesen Tag</td></tr>
                        <?php else: ?>
                            <?php foreach($day_pkgs as $row): ?>
                                <?php $v = empty($row['versandedit']) ? "amazon FBA" : $row['versandedit']; ?>
                                <tr><td><?= htmlspecialchars($v) ?></td><td><?= $row['Pakete'] ?></td></tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </table>
                </div>

                <div class="card">
                    <div class="card-header-flex">
                        <div class="card-title">Monatsübersicht (<?= date("F Y") ?>)</div>
                    </div>
                    <table class="kpi-table">
                        <?php if(empty($month_pkgs)): ?>
                            <tr><td colspan="2" style="text-align: center; color: var(--text-muted);">Keine Daten für diesen Monat</td></tr>
                        <?php else: ?>
                            <?php foreach($month_pkgs as $row): ?>
                                <?php $v = empty($row['versandedit']) ? "amazon FBA" : $row['versandedit']; ?>
                                <tr><td><?= htmlspecialchars($v) ?></td><td><?= $row['Pakete'] ?></td></tr>
                            <?php endforeach; ?>
                            <tr style="border-top: 2px solid var(--border-color);">
                                <td><strong>Summe</strong></td>
                                <td><strong><?= $month_sum ?></strong></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </aside>

            <main class="tables-content">
                <div class="table-wrapper">
                    <table id="resultTable" class="display" style="width:100%">
                        <caption class="data-table-title" style="caption-side: top; text-align: left;">Positionen (Regulär)</caption>
                        <thead>
                            <tr>
                                <th>Anzahl</th>
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
                                    <td><?= $row['anzahl'] ?></td>
                                    <td><span class="<?= $row['titleStyle'] ?>"><?= $row['titel'] ?></span></td>
                                    <td><a href="http://192.168.3.191:888/?ArtNr=<?= urlencode($row['artnr']) ?>" target="_blank" style="color: var(--primary); font-weight:600; text-decoration:none;"><?= htmlspecialchars($row['artnr']) ?></a></td>
                                    <td><?= $row['orders'][0] ?></td>
                                    <td><?= $row['orders'][1] ?></td>
                                    <td><?= $row['orders'][2] ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="table-wrapper">
                    <table id="vendorTable" class="display" style="width:100%">
                        <caption class="data-table-title" style="caption-side: top; text-align: left;">Vendor (Spezial)</caption>
                        <thead>
                            <tr>
                                <th>Anzahl</th>
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
                                    <td><?= $row['anzahl'] ?></td>
                                    <td><span class="<?= $row['titleStyle'] ?>"><?= $row['titel'] ?></span></td>
                                    <td><a href="http://192.168.3.191:888/?ArtNr=<?= urlencode($row['artnr']) ?>" target="_blank" style="color: var(--primary); font-weight:600; text-decoration:none;"><?= htmlspecialchars($row['artnr']) ?></a></td>
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
                scrollY: '600px',
                scrollCollapse: true,
                info: false,
                order: [[0, 'desc']],
                language: {
                    search: "Suchen:"
                },
                columnDefs: [
                    { targets: [3, 4, 5], className: 'dt-left' }
                ],
                // Call checkAndColorRows after DataTables draws to handle dynamic changes
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