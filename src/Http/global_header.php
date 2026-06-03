<?php
$current_page = basename($_SERVER['PHP_SELF']);
?>
<style>
    /* Überschreibt das Padding der Hauptseiten, damit der feste Header nichts verdeckt */
    body {
        padding-top: 88px !important; 
    }

    .global-nav-header {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        height: 64px;
        background: rgba(255, 255, 255, 0.75);
        backdrop-filter: blur(16px);
        -webkit-backdrop-filter: blur(16px);
        border-bottom: 1px solid rgba(15, 23, 42, 0.06);
        z-index: 1000;
        display: flex;
        justify-content: center;
        font-family: "Space Grotesk", system-ui, -apple-system, sans-serif;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
    }

    .global-nav-inner {
        width: 100%;
        max-width: 1400px; /* Passt sich der maximalen Breite deiner Seiten an */
        padding: 0 20px;
        display: flex;
        align-items: center;
        gap: 6px;
        overflow-x: auto;
        scrollbar-width: none; /* Versteckt Scrollbar, falls auf Mobile gescrollt wird */
    }
    
    .global-nav-inner::-webkit-scrollbar {
        display: none;
    }

    .global-nav-header a {
        color: #64748b; /* Muted Text */
        text-decoration: none;
        font-size: 0.95rem;
        font-weight: 600;
        white-space: nowrap;
        padding: 8px 16px;
        border-radius: 8px;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .global-nav-header a:hover {
        color: #0f172a; /* Ink Text */
        background: rgba(15, 23, 42, 0.04);
    }

    .global-nav-header a.active {
        color: #2563eb; /* Primary Blue */
        background: rgba(37, 99, 235, 0.08); 
    }

    .global-nav-header a svg {
        width: 18px;
        height: 18px;
    }

    .global-nav-divider {
        width: 1px;
        height: 24px;
        background: #e2e8f0; /* Stroke Color */
        margin: 0 12px;
    }

    .global-nav-brand {
        color: #0f172a !important;
        font-weight: 700 !important;
        font-size: 1.05rem !important;
        padding-left: 0 !important;
    }

    .global-nav-brand:hover {
        background: transparent !important;
        color: #2563eb !important;
    }
</style>

<div class="global-nav-header">
    <div class="global-nav-inner">
        <a href="index.php" class="global-nav-brand" title="Dashboard">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
            Pricing Manager
        </a>
        
        <div class="global-nav-divider"></div>
        
        <a href="search.php?country=DE" class="<?= ($current_page == 'search.php') ? 'active' : '' ?>">Produktübersicht</a>
        <a href="fba_restock.php" class="<?= ($current_page == 'fba_restock.php') ? 'active' : '' ?>">FBA Restock</a>
        <a href="bestandsabweichungen.php" class="<?= ($current_page == 'bestandsabweichungen.php') ? 'active' : '' ?>">Bestandsabweichungen</a>
        <a href="bestandsabweichungen_historie.php" class="<?= ($current_page == 'bestandsabweichungen_historie.php') ? 'active' : '' ?>">Bestands-Verlauf</a>
        <a href="report.php" class="<?= ($current_page == 'report.php') ? 'active' : '' ?>">Umsatz-Übersicht</a>
        <a href="pakete_infos.php" class="<?= ($current_page == 'pakete_infos.php') ? 'active' : '' ?>">Paket- & Versandinfos</a>
    </div>
</div>