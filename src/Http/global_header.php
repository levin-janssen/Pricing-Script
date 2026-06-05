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
        max-width: 1400px; 
        padding: 0 20px;
        display: flex;
        align-items: center;
    }
    
    .global-nav-brand {
        color: #0f172a !important;
        font-weight: 700 !important;
        font-size: 1.05rem !important;
        padding-left: 0 !important;
        display: flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
        margin-right: 12px;
    }

    .global-nav-brand:hover {
        color: #2563eb !important;
    }

    .global-nav-links {
        display: flex;
        align-items: center;
        gap: 6px;
        flex: 1;
    }

    .global-nav-links a {
        color: #64748b; 
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

    .global-nav-links a:hover {
        color: #0f172a; 
        background: rgba(15, 23, 42, 0.04);
    }

    .global-nav-links a.active {
        color: #2563eb; 
        background: rgba(37, 99, 235, 0.08); 
    }

    .global-nav-divider {
        width: 1px;
        height: 24px;
        background: #e2e8f0; 
        margin: 0 12px;
    }

    .mobile-menu-btn {
        display: none;
        background: transparent;
        border: none;
        color: #0f172a;
        cursor: pointer;
        padding: 8px;
        border-radius: 8px;
        margin-left: auto;
    }

    .mobile-menu-btn:hover {
        background: rgba(15, 23, 42, 0.04);
    }

    /* Mobile Responsiveness */
    @media (max-width: 768px) {
        .global-nav-inner {
            justify-content: space-between;
        }

        .mobile-menu-btn {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .global-nav-divider {
            display: none;
        }

        .global-nav-links {
            display: none; /* Versteckt auf Mobile, bis geklickt wird */
            flex-direction: column;
            position: absolute;
            top: 64px;
            left: 0;
            width: 100%;
            background: rgba(255, 255, 255, 0.98);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid rgba(15, 23, 42, 0.06);
            padding: 12px 20px 24px 20px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
            gap: 4px;
        }

        .global-nav-links.show {
            display: flex;
        }

        .global-nav-links a {
            width: 100%;
            padding: 12px 16px;
        }
    }
</style>

<div class="global-nav-header">
    <div class="global-nav-inner">
        <a href="index.php" class="global-nav-brand" title="Dashboard">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="20" height="20"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
            Pricing Manager
        </a>
        
        <button class="mobile-menu-btn" id="mobileMenuToggle" aria-label="Menu öffnen">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" width="24" height="24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path></svg>
        </button>

        <div class="global-nav-links" id="globalNavLinks">
    <div class="global-nav-divider"></div>
    <a href="search.php?country=DE" class="<?= ($current_page == 'search.php') ? 'active' : '' ?>">Artikel & Preise</a>
    <a href="fba_restock.php" class="<?= ($current_page == 'fba_restock.php') ? 'active' : '' ?>">FBA-Nachschub</a>
    <a href="bestandsabweichungen.php" class="<?= ($current_page == 'bestandsabweichungen.php') ? 'active' : '' ?>">Bestandsabgleich</a>
    <a href="bestandsabweichungen_historie.php" class="<?= ($current_page == 'bestandsabweichungen_historie.php') ? 'active' : '' ?>">Bestandshistorie</a>
    <a href="report.php" class="<?= ($current_page == 'report.php') ? 'active' : '' ?>">Umsätze</a>
    <a href="pakete_infos.php" class="<?= ($current_page == 'pakete_infos.php') ? 'active' : '' ?>">Paket-Tracking</a>
</div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', () => {
        const toggleBtn = document.getElementById('mobileMenuToggle');
        const navLinks = document.getElementById('globalNavLinks');
        
        if(toggleBtn && navLinks) {
            toggleBtn.addEventListener('click', () => {
                navLinks.classList.toggle('show');
            });
        }
    });
</script>