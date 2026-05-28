<?php
header('Content-Type: text/html; charset=utf-8');

require_once APP_ROOT . '/config/marketplaces.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing Manager Übersicht</title>
    <!-- Modern font -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="img/price.ico" sizes="32x32">
    <style>
        :root {
            --bg-color: #f8fafc;
            --text-main: #0f172a;
            --text-muted: #64748b;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --card-bg: #ffffff;
            --border-color: #e2e8f0;
            --danger: #ef4444;
            --warning: #f59e0b;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-main);
            line-height: 1.5;
        }

        .dashboard-container {
            max-width: 1280px;
            margin: 0 auto;
            padding: 2rem;
        }

        .header {
            margin-bottom: 3rem;
            text-align: center;
        }

        .header h1 {
            font-size: 2.5rem;
            font-weight: 700;
            color: var(--text-main);
            margin-bottom: 0.5rem;
        }

        .header p {
            color: var(--text-muted);
            font-size: 1.125rem;
        }

        .section {
            margin-bottom: 3rem;
        }

        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 1.5rem;
            color: var(--text-main);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .section-title svg {
            width: 24px;
            height: 24px;
            color: var(--primary);
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 1.5rem;
            text-decoration: none;
            color: inherit;
            transition: all 0.2s ease-in-out;
            display: flex;
            flex-direction: column;
            gap: 1rem;
            position: relative;
            overflow: hidden;
        }

        .card:hover {
            transform: translateY(-4px);
            box-shadow: 0 12px 24px -10px rgba(0, 0, 0, 0.1);
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

        .card:hover::before {
            opacity: 1;
        }

        .card.admin::before {
            background: var(--danger);
        }
        .card.admin:hover {
            border-color: var(--danger);
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 1rem;
        }

        .icon-wrapper {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            background: #eff6ff;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            flex-shrink: 0;
        }

        .card.admin .icon-wrapper {
            background: #fef2f2;
            color: var(--danger);
        }

        .icon-wrapper svg {
            width: 24px;
            height: 24px;
        }

        .card-title {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
        }

        .card-desc {
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        
        .marketplace-img {
            width: 40px;
            height: 40px;
            object-fit: contain;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <header class="header">
            <h1>Pricing Manager</h1>
            <p>Zentrale Übersicht aller verfügbaren Tools</p>
        </header>

        <!-- HAUPTFUNKTIONEN -->
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
                            <div class="card-desc">Suchen und Details für alle Produkte</div>
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
                            <div class="card-desc">Aktuelle Änderungen und Diskrepanzen prüfen</div>
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
                            <div class="card-desc">Zeitlicher Verlauf eines einzelnen Produkts</div>
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
                            <div class="card-desc">Übersicht vergangener Verkäufe und Kennzahlen</div>
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
                            <div class="card-title">Paket- und Versandinfos</div>
                            <div class="card-desc">Offene Lieferungen und Versandstatistik einsehen</div>
                        </div>
                    </div>
                </a>
            </div>
        </section>

        <!-- MARKETS -->
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
                                    <div class="card-desc">Preis-Update Tool</div>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <p>Keine Marktplätze konfiguriert.</p>
                <?php endif; ?>
            </div>
        </section>

        <!-- ADMIN (Technisch versierte) -->
        <section class="section">
            <h2 class="section-title">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z" />
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
                            <div class="card-desc">Systemprotokolle für Debugging</div>
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
                            <div class="card-desc">Berichte zu ManoMano Fehlern</div>
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
                            <div class="card-desc">Erweiterte Calc Ansicht</div>
                        </div>
                    </div>
                </a>

            </div>
        </section>
    </div>
</body>
</html>

