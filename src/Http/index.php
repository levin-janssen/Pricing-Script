<?php
if (isset($_GET['ajax_lookup'])) {
    // Fehlerunterdrückung für native HTML-Ausgaben, um das JSON nicht zu korrumpieren
    ini_set('display_errors', 0);
    error_reporting(0);
    
    require_once APP_ROOT . '/config/db_connection.php';
    require_once APP_ROOT . '/config/marketplaces.php';
    header('Content-Type: application/json; charset=utf-8');
    
    $type = $_GET['type'] ?? 'base';
    $term = trim(strip_tags((string)($_GET['term'] ?? '')));
    
    // Auf EXCEPTION-Modus zwingen für sauberes Debugging
    if (isset($dbConnectionTric4Calc) && $dbConnectionTric4Calc) {
        $dbConnectionTric4Calc->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    if (isset($dbConnectionTric) && $dbConnectionTric) {
        $dbConnectionTric->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    // 1. Basis-Suche (Lokale DB mit korrigiertem Tricoma-Fallback)
    if ($type === 'base') {
        if (empty($term)) {
            echo json_encode(['success' => false, 'message' => 'Bitte ASIN oder SKU eingeben.']);
            exit;
        }

        try {
            // Strategie A: In lokaler calc DB suchen
            $stmt = $dbConnectionTric4Calc->prepare("SELECT * FROM Artikel WHERE asin = :asin OR sku = :sku LIMIT 1");
            $stmt->execute(['asin' => $term, 'sku' => $term]);
            $artikel = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($artikel) {
                $artikelClean = array_change_key_case($artikel, CASE_LOWER);
                $asin = $artikelClean['asin'];
                
                $stmtPg = $dbConnectionTric4Calc->prepare("SELECT Land, min_preis, max_preis FROM Preisgrenzen WHERE ASIN = :asin ORDER BY Land ASC");
                $stmtPg->execute(['asin' => $asin]);
                $pgs = $stmtPg->fetchAll(PDO::FETCH_ASSOC);
                
                $pgsClean = [];
                foreach ($pgs as $pg) {
                    $pgsClean[] = array_change_key_case($pg, CASE_LOWER);
                }
                
                echo json_encode([
                    'success' => true, 
                    'data' => [
                        'artikel' => $artikelClean,
                        'marketplaces' => $pgsClean,
                        'not_in_pricing' => false
                    ]
                ]);
            } else {
                // Strategie B: Fallback auf Tricoma Hauptdatenbank
                $stmtTric = $dbConnectionTric->prepare("
                    SELECT p.ID as id, p.titel as artikelname,
                           (SELECT wert1 FROM produkte_felder_werte WHERE produktid = p.ID AND feldid = 44 AND wert1 != '' LIMIT 1) as sku,
                           (SELECT wert1 FROM produkte_felder_werte WHERE produktid = p.ID AND feldid = 57 AND wert1 != '' LIMIT 1) as asin
                    FROM produkte p
                    WHERE p.ID = (
                        SELECT produktid FROM produkte_felder_werte WHERE ((feldid = 44 AND wert1 = :term1) OR (feldid = 57 AND wert1 = :term2)) AND wert1 != '' LIMIT 1
                    )
                    LIMIT 1
                ");
                $stmtTric->execute(['term1' => $term, 'term2' => $term]);
                $tricArtikel = $stmtTric->fetch(PDO::FETCH_ASSOC);

                if ($tricArtikel) {
                    $artikelClean = array_change_key_case($tricArtikel, CASE_LOWER);
                    if (empty($artikelClean['sku'])) $artikelClean['sku'] = $term;
                    if (empty($artikelClean['asin'])) $artikelClean['asin'] = $term;

                    echo json_encode([
                        'success' => true,
                        'data' => [
                            'artikel' => $artikelClean,
                            'marketplaces' => [], 
                            'not_in_pricing' => true
                        ]
                    ]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Produkt weder in lokaler DB noch in Tricoma gefunden.']);
                }
            }
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Datenbankfehler: ' . $e->getMessage()]);
        }
        exit;
    }

    // 2. Asynchroner Bestands-Fetch (Alle 3 Bestandsarten)
    if ($type === 'stock') {
        $asin = trim(strip_tags((string)($_GET['asin'] ?? '')));
        try {
            $pure = 0; $open = 0;

            $stmtPure = $dbConnectionTric->prepare("
                SELECT SUM(l.menge) AS total_quantity
                FROM produkte_felder_werte pfw
                INNER JOIN lager l ON pfw.produktid = l.vk_ID
                WHERE pfw.feldid = 57 AND pfw.wert1 = :asin AND pfw.wert1 != ''
            ");
            $stmtPure->execute(['asin' => $asin]);
            $pure = (int)$stmtPure->fetchColumn();

            $stmtOpen = $dbConnectionTric->prepare("
                SELECT SUM(lp.anzahl) AS open_quantity
                FROM lieferungen_positionen lp
                INNER JOIN produkte_felder_werte pfw ON pfw.produktid = lp.produktid
                INNER JOIN lieferungen lief ON lp.lieferungsid = lief.ID
                WHERE pfw.feldid = 57 AND pfw.wert1 = :asin AND pfw.wert1 != '' AND lief.versandart = ''
            ");
            $stmtOpen->execute(['asin' => $asin]);
            $open = (int)$stmtOpen->fetchColumn();

            $real = max(0, $pure - $open);

            echo json_encode([
                'success' => true,
                'data' => ['pure' => $pure, 'open' => $open, 'real' => $real]
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 3. Asynchroner Bestell-Fetch (Mit richtigem Tabellen-Join auf bestelldatum)
    if ($type === 'orders') {
        $sku = trim(strip_tags((string)($_GET['sku'] ?? '')));
        $asin = trim(strip_tags((string)($_GET['asin'] ?? '')));
        try {
            $stmt = $dbConnectionTric->prepare("
                SELECT bp.einzelpreis, bp.steuer, bw.titel AS werbekennzeichen, bp.kundennummer, kfa.wert2 AS land, b.bestelldatum
                FROM bestellungen b
                JOIN bestellungen_positionen bp ON b.ID = bp.bestellungsid
                LEFT JOIN kunden_felder_werte kfw ON kfw.kundennummer = bp.kundennummer AND kfw.feldid = 48
                LEFT JOIN kunden_felder_auswahl kfa ON kfa.auswahlid = kfw.wert1 AND kfa.feldid = 48
                LEFT JOIN bestellungen_werbekennzeichen bw ON bw.ID = b.werbekennzeichen
                WHERE bp.produktid = (
                    SELECT produktid 
                    FROM produkte_felder_werte 
                    WHERE (feldid = 44 AND wert1 = :sku AND wert1 != '') 
                       OR (feldid = 57 AND wert1 = :asin AND wert1 != '')
                    LIMIT 1
                )
                ORDER BY b.bestelldatum DESC
                LIMIT 3
            ");
            $stmt->execute(['sku' => $sku, 'asin' => $asin]);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $formatted = [];
            foreach ($orders as $o) {
                $steuer = is_numeric($o['steuer']) ? (float)$o['steuer'] : 19.0;
                $brutto = (float)$o['einzelpreis'] * (1 + ($steuer / 100));
                
                $rawDate = $o['bestelldatum'];
                $ts = is_numeric($rawDate) ? (int)$rawDate : strtotime((string)$rawDate);
                $dateStr = $ts ? date('d.m.Y H:i', $ts) : '-';

                $formatted[] = [
                    'preis' => number_format($brutto, 2, ',', '.') . ' €',
                    'quelle' => $o['werbekennzeichen'] ?: 'Unbekannt',
                    'land' => $o['land'] ?: 'DE',
                    'datum' => $dateStr
                ];
            }
            echo json_encode(['success' => true, 'data' => $formatted]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 4. Asynchroner Absatz-Fetch (FIX: Genau wie in report.php über T2.bestelldatum filtern)
    // 4. Asynchroner Absatz-Fetch (Korrektur: Filterung wie in report.php)
    if ($type === 'sales') {
        $sku = trim(strip_tags((string)($_GET['sku'] ?? '')));
        $asin = trim(strip_tags((string)($_GET['asin'] ?? '')));
        try {
            // Wir berechnen das Startdatum vor 30 Tagen im SQL-freundlichen Format
            $date_start = (new DateTime())->modify("-30 days")->format('Y-m-d H:i:s');
            
            $stmt = $dbConnectionTric->prepare("
                SELECT SUM(T1.anzahl) AS total_quantity, 
                       SUM(T1.einzelpreis * T1.anzahl) AS total_revenue_pre_vat
                FROM bestellungen_positionen AS T1
                JOIN bestellungen AS T2 ON T2.id = T1.bestellungsid
                WHERE T2.bestelldatum > :date_start
                  AND T1.produktid = (
                    SELECT produktid 
                    FROM produkte_felder_werte 
                    WHERE (feldid = 44 AND wert1 = :sku AND wert1 != '') 
                       OR (feldid = 57 AND wert1 = :asin AND wert1 != '')
                    LIMIT 1
                )
            ");
            $stmt->execute([
                'date_start' => $date_start,
                'sku'      => $sku,
                'asin'     => $asin
            ]);
            $res = $stmt->fetch(PDO::FETCH_ASSOC);

            // Wenn Tricoma bestelldatum als Timestamp speichert, schlägt der String-Vergleich oben fehl.
            // Falls das Ergebnis 0 ist, versuchen wir einen Fallback auf Unix-Timestamp
            if ((int)($res['total_quantity'] ?? 0) === 0) {
                $ts_start = strtotime($date_start);
                $stmtFallback = $dbConnectionTric->prepare("
                    SELECT SUM(T1.anzahl) AS total_quantity, SUM(T1.einzelpreis * T1.anzahl) AS total_revenue_pre_vat
                    FROM bestellungen_positionen AS T1
                    JOIN bestellungen AS T2 ON T2.id = T1.bestellungsid
                    WHERE T2.bestelldatum > :ts_start
                      AND T1.produktid = (
                        SELECT produktid 
                        FROM produkte_felder_werte 
                        WHERE (feldid = 44 AND wert1 = :sku AND wert1 != '') 
                           OR (feldid = 57 AND wert1 = :asin AND wert1 != '')
                        LIMIT 1
                    )
                ");
                $stmtFallback->execute(['ts_start' => $ts_start, 'sku' => $sku, 'asin' => $asin]);
                $res = $stmtFallback->fetch(PDO::FETCH_ASSOC);
            }

            $qty = (int)($res['total_quantity'] ?? 0);
            $rev = (float)($res['total_revenue_pre_vat'] ?? 0) * 1.19;

            echo json_encode([
                'success' => true,
                'data' => [
                    'quantity' => $qty,
                    'revenue' => number_format($rev, 2, ',', '.') . ' €'
                ]
            ]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    // 5. Asynchroner Buybox-Fetch
    if ($type === 'buybox') {
        $productId = (int)($_GET['product_id'] ?? 0);
        try {
            $bbResults = [];
            if ($productId > 0) {
                foreach ($marketplaces as $code => $m) {
                    $table = $m['dbName'] ?? '';
                    if (empty($table)) continue;

                    try {
                        $stmt = $dbConnectionTric4Calc->prepare("
                            SELECT bb.isWinner, bb.eigenerpreis, bb.buyboxPreis
                            FROM $table bb
                            WHERE bb.produktid = :produktid
                            ORDER BY bb.datum DESC
                            LIMIT 1
                        ");
                        $stmt->execute(['produktid' => $productId]);
                        $row = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($row) {
                            $bbResults[] = [
                                'marketplace' => $m['name'] ?? $code,
                                'is_winner' => in_array(strtolower(trim((string)$row['isWinner'])), ['ja', '1', 'true'], true),
                                'own_price' => is_numeric($row['eigenerpreis']) ? number_format((float)$row['eigenerpreis'], 2, ',', '.') . ' €' : '-',
                                'bb_price' => is_numeric($row['buyboxPreis']) ? number_format((float)$row['buyboxPreis'], 2, ',', '.') . ' €' : '-'
                            ];
                        }
                    } catch (\Exception $subEx) {
                        // Überspringen falls Tabelle fehlt
                    }
                }
            }
            echo json_encode(['success' => true, 'data' => $bbResults]);
        } catch (\Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

header('Content-Type: text/html; charset=utf-8');
require_once APP_ROOT . '/config/marketplaces.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing Manager Übersicht</title>
    
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="img/price.ico" sizes="32x32">
    
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
            --danger: #ef4444;
            --danger-bg: #fef2f2;
            --primary-bg: #eff6ff;
            --ring: rgba(37, 99, 235, 0.25);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        [hidden] {
            display: none !important;
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

        .dashboard-container {
            max-width: 1280px;
            margin: 0 auto;
        }

        .hero {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 40px;
        }

        .hero .eyebrow {
            text-transform: uppercase;
            letter-spacing: 0.1em;
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--primary);
        }

        .hero h1 {
            font-size: 2.5rem;
            font-weight: 700;
            letter-spacing: -0.02em;
            color: var(--ink);
        }

        .hero .subtitle {
            color: var(--muted);
            font-size: 1.1rem;
            max-width: 600px;
            font-weight: 500;
        }

        .section {
            margin-bottom: 48px;
        }

        .section-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 20px;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 10px;
            padding-bottom: 12px;
            border-bottom: 2px solid var(--stroke);
        }

        .section-title svg {
            width: 24px;
            height: 24px;
            color: var(--primary);
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 24px;
        }

        /* --- Quick Search Panel --- */
        .quick-search-panel {
            background: var(--surface);
            padding: 24px;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            border: 1px solid var(--stroke);
            margin-bottom: 24px;
        }
        .search-bar-row {
            display: flex;
            gap: 12px;
        }
        .quick-search-input {
            flex: 1;
            height: 48px;
            padding: 0 16px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--stroke);
            font-family: inherit;
            font-size: 1.05rem;
            background: var(--surface-soft);
            color: var(--ink);
            transition: all 0.2s ease;
        }
        .quick-search-input:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px var(--ring);
            background: var(--surface);
        }
        
        .btn-primary {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            height: 48px;
            padding: 0 24px;
            border-radius: var(--radius-sm);
            background: var(--primary);
            color: white;
            font-family: inherit;
            font-weight: 600;
            font-size: 1rem;
            border: none;
            cursor: pointer;
            text-decoration: none;
            box-shadow: var(--shadow-sm);
            transition: all 0.2s ease;
        }
        .btn-primary:hover {
            background: #1d4ed8;
            transform: translateY(-1px);
            box-shadow: var(--shadow);
        }

        .quick-search-results {
            margin-top: 24px;
            padding-top: 24px;
            border-top: 1px solid var(--stroke);
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-4px); }
            to { opacity: 1; transform: translateY(0); }
        }
        @keyframes spin {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        .opacity-25 { opacity: 0.25; }
        .opacity-75 { opacity: 0.75; }

        .qs-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 20px;
        }
        .qs-card {
            background: var(--surface-soft);
            padding: 16px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--stroke);
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .qs-card h3 {
            font-size: 0.8rem;
            color: var(--muted);
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 6px;
            border-bottom: 1px solid var(--stroke);
            padding-bottom: 4px;
        }
        .qs-value {
            font-size: 1.1rem;
            font-weight: 600;
            color: var(--ink);
            word-break: break-word;
        }
        .qs-value.name {
            font-size: 1.25rem;
            color: var(--primary);
        }

        .qs-sub-row {
            display: flex;
            justify-content: space-between;
            font-size: 0.9rem;
            padding: 4px 0;
            border-bottom: 1px dashed var(--stroke);
        }
        .qs-sub-row:last-child {
            border-bottom: none;
        }
        .qs-loading-small {
            font-size: 0.85rem;
            color: var(--muted);
            font-style: italic;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .qs-mini-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            margin-top: 4px;
        }
        .qs-mini-table th, .qs-mini-table td {
            padding: 6px 8px;
            text-align: left;
            border-bottom: 1px solid var(--stroke);
        }
        .qs-mini-table th {
            background: #f1f5f9;
            color: var(--muted);
            font-weight: 600;
        }
        .qs-orders-list {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }
        .qs-order-item {
            background: var(--surface);
            padding: 8px 12px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--stroke);
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
        }

        .qs-links-area {
            margin-top: 24px;
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
        }
        .qs-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            background: var(--primary-bg);
            color: var(--primary);
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            border: 1px solid transparent;
        }
        .qs-link:hover {
            background: #dbeafe;
            border-color: #bfdbfe;
        }
        .qs-link.marketplace {
            background: #fff7ed;
            color: var(--accent);
        }
        .qs-link.marketplace:hover {
            background: #ffedd5;
            border-color: #fed7aa;
        }
        .qs-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 0.75rem;
            font-weight: 600;
            background: var(--stroke);
            color: var(--ink);
        }
        .qs-badge.active {
            background: #dcfce7;
            color: #166534;
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 2px 6px;
            border-radius: 4px;
            font-weight: 600;
            font-size: 0.75rem;
        }
        .badge-green { background: #dcfce7; color: #166534; }
        .badge-red { background: #fee2e2; color: #991b1b; }
        .badge-blue { background: #e0f2fe; color: #075985; }
        .badge-gray { background: #f1f5f9; color: #475569; }

        .qs-alert {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            font-size: 0.95rem;
            font-weight: 500;
            background: #fef2f2;
            color: #b91c1c;
            border: 1px solid #fecaca;
            margin-top: 16px;
        }
        .qs-loading {
            color: var(--muted);
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 16px;
        }

        /* --- Cards --- */
        .card {
            background: var(--surface);
            border: 1px solid var(--stroke);
            border-radius: var(--radius);
            padding: 24px;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s ease-in-out;
            display: flex;
            flex-direction: column;
            gap: 16px;
            position: relative;
            overflow: hidden;
            box-shadow: var(--shadow-sm);
        }

        .card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow);
            border-color: var(--primary);
        }
        
        .card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: var(--primary);
            opacity: 0;
            transition: opacity 0.2s;
        }

        .card:hover::before { opacity: 1; }
        .card.admin::before { background: var(--danger); }
        .card.admin:hover { border-color: var(--danger); }

        .card-header {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .icon-wrapper {
            width: 52px;
            height: 52px;
            border-radius: 12px;
            background: var(--primary-bg);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            flex-shrink: 0;
            transition: background 0.2s;
        }

        .card:hover .icon-wrapper { background: #dbeafe; }
        .card.admin .icon-wrapper { background: var(--danger-bg); color: var(--danger); }
        .card.admin:hover .icon-wrapper { background: #fecaca; }

        .icon-wrapper svg { width: 26px; height: 26px; }
        .card-title { font-size: 1.15rem; font-weight: 600; margin-bottom: 4px; color: var(--ink); }
        .card-desc { font-size: 0.9rem; color: var(--muted); line-height: 1.4; }
        .marketplace-img { width: 32px; height: 32px; object-fit: contain; border-radius: 4px; box-shadow: var(--shadow-sm); }

        @media (max-width: 768px) {
            /* General padding & typography adjustments */
            body { padding: 24px 12px 60px; }
            .hero h1 { font-size: 1.8rem; }
            .hero .subtitle { font-size: 0.95rem; }
            .section-title { font-size: 1.1rem; }
            
            /* Layout structural adjustments */
            .grid { grid-template-columns: 1fr; gap: 16px; }
            .search-bar-row { flex-direction: column; }
            .btn-primary { width: 100%; height: 54px; }
            .quick-search-input { height: 54px; font-size: 1rem; }
            
            /* Quick Search Adjustments */
            .quick-search-panel { padding: 16px; }
            .qs-grid { grid-template-columns: 1fr; gap: 16px; }
            .qs-card { padding: 12px; }
            
            /* Table Horizontal Scroll fix */
            .qs-buybox-details {
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                margin: 0 -12px; /* Pulls the scroll out to the edges */
                padding: 0 12px;
            }
            .qs-mini-table { min-width: 400px; } /* Forces scroll instead of squishing text */
            
            /* Stack orders and links naturally */
            .qs-order-item { 
                flex-direction: column; 
                align-items: flex-start; 
                gap: 8px; 
            }
            .qs-order-item > div:last-child {
                text-align: left; /* Aligns the date/badge left on mobile */
            }
            .qs-links-area { flex-direction: column; }
            .qs-link { 
                width: 100%; 
                justify-content: center; 
                text-align: center; 
                padding: 12px;
            }
            
            /* Dashboard Card adjustments */
            .card { padding: 16px; gap: 12px; }
            .card-header { gap: 12px; }
            .icon-wrapper { width: 44px; height: 44px; }
            .icon-wrapper svg { width: 22px; height: 22px; }
            .card-title { font-size: 1.05rem; }
        }
    </style>
</head>
<body>
    <?php include __DIR__ . '/global_header.php'; ?>
    <div class="dashboard-container">
        
        <header class="hero">
            <p class="eyebrow">Dashboard</p>
            <h1>Pricing Manager</h1>
            <p class="subtitle">Zentrale Übersicht aller verfügbaren Tools, Marktplätze und Systemkonfigurationen.</p>
        </header>

        <section class="section">
            <h2 class="section-title">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
                Schnellsuche
            </h2>
            <div class="quick-search-panel">
                <div class="search-bar-row">
                    <input type="text" id="qsInput" class="quick-search-input" placeholder="ASIN oder SKU eingeben..." autocomplete="off">
                    <button id="qsBtn" class="btn-primary">Suchen</button>
                </div>
                
                <div id="qsLoading" class="qs-loading" hidden>
                    <svg class="animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" style="width: 20px; height: 20px; animation: spin 1s linear infinite;">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                    </svg>
                    Produktsuche läuft...
                </div>

                <div id="qsError" class="qs-alert" hidden></div>

                <div id="qsResults" class="quick-search-results" hidden>
                    <div class="qs-grid">
                        <div class="qs-card" style="grid-column: 1 / -1;">
                            <h3>Produktname</h3>
                            <div class="qs-value name" id="qsName">-</div>
                        </div>
                        <div class="qs-card">
                            <h3>ASIN</h3>
                            <div class="qs-value" style="font-family: ui-monospace, monospace;" id="qsAsin">-</div>
                        </div>
                        <div class="qs-card">
                            <h3>SKU</h3>
                            <div class="qs-value" id="qsSku">-</div>
                        </div>

                        <div class="qs-card" id="qsStockSection">
                            <h3>Lagerbestände</h3>
                            <div class="qs-loading-small">Lade Bestände...</div>
                            <div class="qs-stock-details" hidden>
                                <div class="qs-sub-row"><span>Roher Bestand:</span> <strong id="stockPure">-</strong></div>
                                <div class="qs-sub-row"><span>Offene Lieferungen:</span> <strong id="stockOpen">-</strong></div>
                                <div class="qs-sub-row"><span>Verfügbar (Real):</span> <strong id="stockReal" style="color:var(--primary);">-</strong></div>
                            </div>
                        </div>

                        <div class="qs-card" id="qsSalesSection">
                            <h3>Absatz (Letzte 30 Tage)</h3>
                            <div class="qs-loading-small">Lade Umsätze...</div>
                            <div class="qs-sales-details" hidden>
                                <div class="qs-sub-row"><span>Menge verkauft:</span> <strong id="salesQty">-</strong></div>
                                <div class="qs-sub-row"><span>Umsatz (Brutto):</span> <strong id="salesRev">-</strong></div>
                            </div>
                        </div>

                        <div class="qs-card" style="grid-column: 1 / -1;" id="qsBuyboxSection">
                            <h3>Buybox Status & Eigener Preis</h3>
                            <div class="qs-loading-small">Prüfe Marktplatzkanäle...</div>
                            <div class="qs-buybox-details" hidden>
                                <table class="qs-mini-table">
                                    <thead>
                                        <tr>
                                            <th>Marktplatz</th>
                                            <th>In Buybox?</th>
                                            <th>Eigener Preis</th>
                                            <th>Buybox Preis</th>
                                        </tr>
                                    </thead>
                                    <tbody id="qsBuyboxTbody"></tbody>
                                </table>
                            </div>
                        </div>

                        <div class="qs-card" style="grid-column: 1 / -1;" id="qsOrdersSection">
                            <h3>Letzte 3 Bestellungen</h3>
                            <div class="qs-loading-small">Suche Bestell-Historie...</div>
                            <div id="qsOrdersList" class="qs-orders-list" hidden></div>
                        </div>
                    </div>

                    <div class="qs-links-area" id="qsLinks">
                        </div>
                </div>
            </div>
        </section>

        <section class="section">
            <h2 class="section-title">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                </svg>
                Hauptfunktionen
            </h2>
            <div class="grid">
                
                <a href="search.php?country=DE" class="card">
                    <div class="card-header">
                        <div class="icon-wrapper">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                        <div>
                            <div class="card-title">Produktübersicht</div>
                            <div class="card-desc">Suche, Details und Preisgrenzen für alle Produkte verwalten.</div>
                        </div>
                    </div>
                </a>

                <a href="fba_restock.php" class="card">
                    <div class="card-header">
                        <div class="icon-wrapper">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                            </svg>
                        </div>
                        <div>
                            <div class="card-title">FBA Restock Manager</div>
                            <div class="card-desc">Überwache den FBA-Bestand und identifiziere kritische Nachfüll-Artikel.</div>
                        </div>
                    </div>
                </a>

                <a href="bestandsabweichungen.php" class="card">
                    <div class="card-header">
                        <div class="icon-wrapper">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4" />
                            </svg>
                        </div>
                        <div>
                            <div class="card-title">Bestandsabweichungen</div>
                            <div class="card-desc">Aktuelle Änderungen und Diskrepanzen zwischen Plattformen prüfen.</div>
                        </div>
                    </div>
                </a>

                <a href="bestandsabweichungen_historie.php" class="card">
                    <div class="card-header">
                        <div class="icon-wrapper">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <div>
                            <div class="card-title">Bestands-Historie</div>
                            <div class="card-desc">Den genauen zeitlichen Verlauf eines einzelnen Produkts analysieren.</div>
                        </div>
                    </div>
                </a>

                <a href="report.php" class="card">
                    <div class="card-header">
                        <div class="icon-wrapper">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                            </svg>
                        </div>
                        <div>
                            <div class="card-title">Verkaufs-Report</div>
                            <div class="card-desc">Umfassende Übersicht vergangener Verkäufe und Erfolgskennzahlen.</div>
                        </div>
                    </div>
                </a>

                <a href="pakete_infos.php" class="card">
                    <div class="card-header">
                        <div class="icon-wrapper">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                            </svg>
                        </div>
                        <div>
                            <div class="card-title">Paket- & Versandinfos</div>
                            <div class="card-desc">Offene Lieferungen und detaillierte Versandstatistik einsehen.</div>
                        </div>
                    </div>
                </a>
            </div>
        </section>

        <section class="section">
            <h2 class="section-title">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
                Marktplätze & Tools
            </h2>
            <div class="grid">
                <?php if(!empty($marketplaces)): ?>
                    <?php foreach ($marketplaces as $m): ?>
                        <a href="<?php echo htmlspecialchars($m['url']); ?>" class="card" target="_blank">
                            <div class="card-header">
                                <div class="icon-wrapper" style="background: transparent;">
                                    <?php if(!empty($m['img'])): ?>
                                        <img src="<?php echo htmlspecialchars($m['img']); ?>" alt="<?php echo htmlspecialchars($m['name']); ?>" class="marketplace-img">
                                    <?php else: ?>
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z" />
                                        </svg>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="card-title"><?php echo htmlspecialchars($m['name']); ?></div>
                                    <div class="card-desc">Preis-Update Dashboard für diesen Marktplatz öffnen.</div>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="grid-column: 1 / -1; padding: 32px; text-align: center; color: var(--muted); background: var(--surface); border: 1px dashed var(--stroke); border-radius: var(--radius);">
                        Aktuell sind keine Marktplätze im System konfiguriert.
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="section">
            <h2 class="section-title">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31.237-2.37.996.608 2.296.07 2.572-1.065z" />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                Administration & Technik
            </h2>
            <div class="grid">
                
                <a href="log_viewer.php" class="card admin" target="_blank">
                    <div class="card-header">
                        <div class="icon-wrapper">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4" />
                            </svg>
                        </div>
                        <div>
                            <div class="card-title">Log Viewer</div>
                            <div class="card-desc">Systemprotokolle für technisches Debugging und Fehlersuche.</div>
                        </div>
                    </div>
                </a>

                <a href="error_report.php" class="card admin" target="_blank">
                    <div class="card-header">
                        <div class="icon-wrapper">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                            </svg>
                        </div>
                        <div>
                            <div class="card-title">Error Report</div>
                            <div class="card-desc">Ausführliche Berichte zu spezifischen API-Fehlern (z.B. ManoMano).</div>
                        </div>
                    </div>
                </a>

                <a href="../tric4calc.php" class="card admin" target="_blank">
                    <div class="card-header">
                        <div class="icon-wrapper">
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                        </div>
                        <div>
                            <div class="card-title">Produktdatenbank</div>
                            <div class="card-desc">Erweiterte technische Datenbankansicht (Calc).</div>
                        </div>
                    </div>
                </a>

            </div>
        </section>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const qsInput = document.getElementById('qsInput');
            const qsBtn = document.getElementById('qsBtn');
            const qsLoading = document.getElementById('qsLoading');
            const qsError = document.getElementById('qsError');
            const qsResults = document.getElementById('qsResults');
            
            const qsName = document.getElementById('qsName');
            const qsAsin = document.getElementById('qsAsin');
            const qsSku = document.getElementById('qsSku');
            const qsLinks = document.getElementById('qsLinks');

            const qsStockSection = document.getElementById('qsStockSection');
            const qsSalesSection = document.getElementById('qsSalesSection');
            const qsBuyboxSection = document.getElementById('qsBuyboxSection');
            const qsOrdersSection = document.getElementById('qsOrdersSection');

            async function performSearch() {
                const term = qsInput.value.trim();
                if (!term) return;

                qsError.hidden = true;
                qsResults.hidden = true;
                qsLoading.hidden = false;

                document.querySelectorAll('.qs-loading-small').forEach(el => el.hidden = false);
                qsStockSection.querySelector('.qs-stock-details').hidden = true;
                qsSalesSection.querySelector('.qs-sales-details').hidden = true;
                qsBuyboxSection.querySelector('.qs-buybox-details').hidden = true;
                document.getElementById('qsOrdersList').hidden = true;

                try {
                    // Hauptabfrage starten
                    const res = await fetch(`?ajax_lookup=1&type=base&term=${encodeURIComponent(term)}`);
                    const text = await res.text();
                    
                    let json;
                    try { json = JSON.parse(text); } catch(e) {
                        console.error("Layout JSON error payload:", text);
                        throw new Error("Server hat fehlerhafte Daten zurückgegeben.");
                    }

                    if (!json.success) {
                        throw new Error(json.message || 'Produkt nicht gefunden.');
                    }

                    const data = json.data;
                    const artikel = data.artikel;
                    const asin = artikel.asin;
                    const sku = artikel.sku;
                    const productId = data.not_in_pricing ? 0 : artikel.id; 

                    qsName.textContent = artikel.artikelname || '-';
                    qsAsin.textContent = asin || '-';
                    qsSku.textContent = sku || '-';

                    qsLinks.innerHTML = '';
                    
                    // Bestands-Historie Link
                    const histLink = document.createElement('a');
                    histLink.className = 'qs-link';
                    histLink.href = `bestandsabweichungen_historie.php?asin=${encodeURIComponent(asin)}`;
                    histLink.innerHTML = `
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:18px;height:18px;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        Bestands-Historie
                    `;
                    qsLinks.appendChild(histLink);

                    // Dynamischer Link zum Umsatz-Report (7 Tage, Quelle: Amazon)
                    const reportLink = document.createElement('a');
                    reportLink.className = 'qs-link';
                    reportLink.href = `report.php?sku=${encodeURIComponent(sku)}&time_period=7&source=amazon`;
                    reportLink.innerHTML = `
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:18px;height:18px;">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                        </svg>
                        Umsatz-Bericht (Amazon)
                    `;
                    qsLinks.appendChild(reportLink);

                    // Marktplatz-Preisgrenzen (falls vorhanden)
                    const mps = data.marketplaces || [];
                    mps.forEach(m => {
                        const priceLink = document.createElement('a');
                        priceLink.className = 'qs-link marketplace';
                        priceLink.href = `results.php?country=${encodeURIComponent(String(m.land).toUpperCase())}&asin=${encodeURIComponent(asin)}`;
                        priceLink.innerHTML = `
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="width:18px;height:18px;">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            Preisgrenzen (${String(m.land).toUpperCase()})
                        `;
                        qsLinks.appendChild(priceLink);
                    });

                    qsResults.hidden = false;
                    qsLoading.hidden = true;

                    // --- ASYNCHRONES HINTERGRUND-LADEN STARTEN ---
                    fetchStockData(asin);
                    fetchSalesData(sku, asin);
                    fetchBuyboxData(productId);
                    fetchOrdersData(sku, asin);

                } catch (err) {
                    qsError.textContent = err.message;
                    qsError.hidden = false;
                    qsLoading.hidden = true;
                }
            }

            function fetchStockData(asin) {
                fetch(`?ajax_lookup=1&type=stock&asin=${encodeURIComponent(asin)}`)
                    .then(r => r.json())
                    .then(json => {
                        if (json.success) {
                            qsStockSection.querySelector('.qs-loading-small').hidden = true;
                            const details = qsStockSection.querySelector('.qs-stock-details');
                            details.removeAttribute('hidden');
                            document.getElementById('stockPure').textContent = json.data.pure;
                            document.getElementById('stockOpen').textContent = json.data.open;
                            document.getElementById('stockReal').textContent = json.data.real;
                        }
                    }).catch(e => console.error("Stock fetch error:", e));
            }

            function fetchSalesData(sku, asin) {
                fetch(`?ajax_lookup=1&type=sales&sku=${encodeURIComponent(sku)}&asin=${encodeURIComponent(asin)}`)
                    .then(r => r.json())
                    .then(json => {
                        if (json.success) {
                            qsSalesSection.querySelector('.qs-loading-small').hidden = true;
                            const details = qsSalesSection.querySelector('.qs-sales-details');
                            details.removeAttribute('hidden');
                            document.getElementById('salesQty').textContent = json.data.quantity;
                            document.getElementById('salesRev').textContent = json.data.revenue;
                        }
                    }).catch(e => console.error("Sales fetch error:", e));
            }

            function fetchBuyboxData(productId) {
                fetch(`?ajax_lookup=1&type=buybox&product_id=${productId}`)
                    .then(r => r.json())
                    .then(json => {
                        if (json.success) {
                            qsBuyboxSection.querySelector('.qs-loading-small').hidden = true;
                            const details = qsBuyboxSection.querySelector('.qs-buybox-details');
                            details.removeAttribute('hidden');
                            const tbody = document.getElementById('qsBuyboxTbody');
                            tbody.innerHTML = '';
                            
                            if (json.data.length === 0) {
                                tbody.innerHTML = '<tr><td colspan="4" style="color:var(--muted);text-align:center;padding:12px 0;">Nicht im Pricing-Script aktiv (Keine Überwachung).</td></tr>';
                                return;
                            }

                            json.data.forEach(row => {
                                const tr = document.createElement('tr');
                                const bClass = row.is_winner ? 'badge-green' : 'badge-red';
                                const bText = row.is_winner ? 'Ja' : 'Nein';
                                tr.innerHTML = `
                                    <td><strong>${row.marketplace}</strong></td>
                                    <td><span class="badge ${bClass}">${bText}</span></td>
                                    <td><span class="badge badge-blue">${row.own_price}</span></td>
                                    <td><span class="badge badge-gray">${row.bb_price}</span></td>
                                `;
                                tbody.appendChild(tr);
                            });
                        }
                    }).catch(e => console.error("Buybox fetch error:", e));
            }

            function fetchOrdersData(sku, asin) {
                fetch(`?ajax_lookup=1&type=orders&sku=${encodeURIComponent(sku)}&asin=${encodeURIComponent(asin)}`)
                    .then(r => r.json())
                    .then(json => {
                        if (json.success) {
                            qsOrdersSection.querySelector('.qs-loading-small').hidden = true;
                            const list = document.getElementById('qsOrdersList');
                            list.removeAttribute('hidden');
                            list.innerHTML = '';

                            if (json.data.length === 0) {
                                list.innerHTML = '<div style="font-size:0.9rem;color:var(--muted);padding:4px 0;">Keine historischen Bestellungen gefunden.</div>';
                                return;
                            }

                            json.data.forEach(o => {
                                const item = document.createElement('div');
                                item.className = 'qs-order-item';
                                item.innerHTML = `
                                    <div>
                                        <strong>${o.preis}</strong> 
                                        <span style="font-size:0.85em;color:var(--muted);">(${o.quelle})</span>
                                    </div>
                                    <div style="font-size:0.85em;text-align:right;">
                                        <span class="qs-badge active" style="margin:0 6px 0 0;padding:1px 6px;">${o.land}</span>
                                        <span>${o.datum}</span>
                                    </div>
                                `;
                                list.appendChild(item);
                            });
                        }
                    }).catch(e => console.error("Orders fetch error:", e));
            }

            qsBtn.addEventListener('click', performSearch);
            qsInput.addEventListener('keypress', (e) => {
                if (e.key === 'Enter') {
                    performSearch();
                }
            });
        });
    </script>
</body>
</html>