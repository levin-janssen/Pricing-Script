<?php
ini_set('default_charset',  'UTF-8');
ini_set('display_errors', 1); // For debugging
error_reporting(E_ALL);

require_once '../marketplaces.php';
require_once '../db_connection.php';
$dbConnection = $dbConnectionTric4Calc;

$asins_for_country = [];
$db_error = '';

// --- Determine current country from directory path ---
$currentDir = basename(__DIR__);
$current_marketplace_code = strtoupper($currentDir);

if (!isset($marketplaces[$current_marketplace_code])) {
    $db_error = "Fehler: Unbekannter Marketplace-Code '" . htmlspecialchars($current_marketplace_code) . "' aus Verzeichnispfad.";
} else {
    // Fetch ASINs that are configured in Preisgrenzen for the current country
    try {
        $stmt = $dbConnection->prepare(
            "SELECT DISTINCT pg.ASIN, a.artikelname
             FROM Preisgrenzen pg
             JOIN Artikel a ON pg.ASIN = a.asin
             WHERE pg.Land = :land
             ORDER BY a.artikelname ASC"
        );
        $stmt->bindParam(':land', $current_marketplace_code, PDO::PARAM_STR);
        $stmt->execute();
        $asins_for_country = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\PDOException $e) {
        error_log("DB Fehler in index.php für Land $current_marketplace_code: " . $e->getMessage());
        $db_error = "Fehler beim Abrufen der ASIN-Liste für Land " . htmlspecialchars($current_marketplace_code) . ".";
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ASIN Produktsuche - <?= htmlspecialchars($current_marketplace_code) ?></title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../landingpage.css">
    <link rel="icon" type="image/x-icon" href="../img/tag.ico" sizes="32x32">
    <style>
        .marketplace-select-wrapper {
            position: absolute; top: 20px; left: 20px; display: flex; align-items: center;
        }
        .marketplace-select-wrapper img {
            height: 1em; margin-right: 5px; vertical-align: middle; box-shadow: -0.75px 0.75px 3px rgba(0, 0, 0, 0.2);
        }
        #marketplaceSelect {
            padding: 5px 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9rem; background-color: #f9f9f9; cursor: pointer;
        }
    </style>
</head>
<body>

    <div class="marketplace-select-wrapper">
         <img id="currentMarketplaceFlag" src="<?= isset($marketplaces[$current_marketplace_code]['img']) ? htmlspecialchars($marketplaces[$current_marketplace_code]['img']) : '../img/default.png' ?>" alt="<?= htmlspecialchars($current_marketplace_code) ?> Flag">
        <select id="marketplaceSelect">
            <?php foreach ($marketplaces as $code => $details): ?>
                <option value="<?= htmlspecialchars($details['url']) ?>" <?= ($code === $current_marketplace_code) ? 'selected' : '' ?>>
                     <?= htmlspecialchars($details['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <a href="../index.php" style="text-decoration: none !important;">
    <h1>
        <img src="<?= isset($marketplaces[$current_marketplace_code]['img']) ? htmlspecialchars($marketplaces[$current_marketplace_code]['img']) : '../img/default.png' ?>" alt="Flag <?= htmlspecialchars($current_marketplace_code) ?>" style="height:1.2em; vertical-align:middle;">
        <span>Dynamic Pricing Tool</span>
    </h1>
    </a>

    <a href="addNew.php" style="position: absolute; top: 20px; right: 20px;">
        <button id="addproductBtn">
            + Produkt hinzufügen 
        </button>
    </a>

    <?php if ($db_error): ?>
        <p class="message error" style="text-align:center;"><?= htmlspecialchars($db_error) ?></p>
    <?php else: ?>
        <form action="results.php" method="GET" id="asin-search-form">
            <label for="asin-input">ASIN für Land <?= htmlspecialchars($current_marketplace_code) ?> eingeben oder auswählen:</label>
            <input type="text"
                   id="asin-input"
                   name="asin"
                   list="asin-list-country"
                   placeholder="ASIN hier eingeben..."
                   required
                   autocomplete="off"
                   pattern="^[A-Z0-9]{10}$"
                   title="Gültige 10-stellige ASIN (Großbuchstaben/Ziffern).">

                   <datalist id="asin-list-country">
                    <?php foreach ($asins_for_country as $item): ?>
                        <option value="<?= htmlspecialchars($item['ASIN']) ?>">
                            <?= htmlspecialchars($item['artikelname']) ?> (<?= htmlspecialchars($item['ASIN']) ?>)
                        </option>
                    <?php endforeach; ?>
                    </datalist>
            <button type="submit">Suchen</button>
        </form>

        <div class="hinweis">
            <strong>Hinweis:</strong> Es werden nur ASINs angezeigt/gesucht, die für das Land <strong><?= htmlspecialchars($current_marketplace_code) ?></strong> Preisgrenzen hinterlegt haben.
            <?php if (empty($asins_for_country)): ?>
                <br><em>Aktuell sind keine Produkte für <?= htmlspecialchars($current_marketplace_code) ?> konfiguriert. Fügen Sie welche über "+ Produkt konfigurieren" hinzu.</em>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <script>
        const marketplaceSelect = document.getElementById('marketplaceSelect');
        const currentMarketplaceFlag = document.getElementById('currentMarketplaceFlag');
        const marketplacesData = <?php echo json_encode($marketplaces); ?>;

        marketplaceSelect.addEventListener('change', function() {
            const selectedUrl = this.value;
            // Find the marketplace code from the URL to update the flag before navigating
            let selectedCode = '';
            for (const code in marketplacesData) {
                 if (marketplacesData[code].url === selectedUrl) {
                    selectedCode = code;
                    break;
                 }
            }
            if (selectedCode && marketplacesData[selectedCode] && marketplacesData[selectedCode].img) {
                currentMarketplaceFlag.src = marketplacesData[selectedCode].img;
                currentMarketplaceFlag.alt = selectedCode + " Flag";
            }
            // Navigate after a very short delay to allow flag update (optional)
            // setTimeout(() => { location.href = selectedUrl; }, 50);
            location.href = selectedUrl; // Direct navigation
        });
    </script>
</body>
</html>