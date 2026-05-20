<?php
header('Content-Type: text/html; charset=utf-8');

$marketplaces = [
    'DE' => [
        'name' => 'Deutschland',
        'url' => 'DE/index.php',
        'img' => 'img/DE.png',
    ],
    'IT' => [
        'name' => 'Italien',
        'url' => 'IT/index.php',
        'img' => 'img/IT.png',
    ],
    'ES' => [
        'name' => 'Spanien',
        'url' => 'ES/index.php',
        'img' => 'img/ES.png',
    ],
    'FR' => [
        'name' => 'Frankreich',
        'url' => 'FR/index.php',
        'img' => 'img/FR.png',
    ],
    'UK' => [
        'name' => 'Vereinigtes Königreich',
        'url' => 'UK/index.php',
        'img' => 'img/UK.png',
    ],
    'BE' => [
        'name' => 'Belgien',
        'url' => 'BE/index.php',
        'img' => 'img/BE.png',
    ],
    'NL' => [
        'name' => 'Niederlande',
        'url' => 'NL/index.php',
        'img' => 'img/NL.png',
    ],
    'SE' => [
        'name' => 'Schweden',
        'url' => 'SE/index.php',
        'img' => 'img/SE.png',
    ],
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <title>Pricing Tool Übersicht</title>
    <link rel="icon" type="image/x-icon" href="img/price.ico" sizes="32x32">
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, sans-serif;
            margin: 0;
            background: #f5f7fa;
            color: #333;
        }

        header {
            background: linear-gradient(135deg, #4f46e5, #3b82f6);
            color: white;
            padding: 2rem;
            text-align: center;
            box-shadow: 0 4px 8px rgba(0,0,0,0.2);
        }

        h1 {
            margin: 0;
            font-size: 2.5rem;
        }

        main {
            max-width: 1200px;
            margin: 2rem auto;
            padding: 0 1rem;
        }

        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
        }

        .card {
            background: white;
            border-radius: 1rem;
            padding: 1.5rem;
            text-align: center;
            box-shadow: 0 6px 12px rgba(0,0,0,0.08);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .card:hover {
            transform: translateY(-5px);
            box-shadow: 0 12px 20px rgba(0,0,0,0.15);
        }

        .card img {
            width: 50px;
            height: auto;
            margin-bottom: 1rem;
        }

        .card h2 {
            margin: 0.5rem 0;
            font-size: 1.25rem;
            color: #111;
        }

        .card a {
            display: inline-block;
            margin-top: 0.5rem;
            padding: 0.5rem 1rem;
            background: #3b82f6;
            color: white;
            border-radius: 0.5rem;
            text-decoration: none;
            font-size: 0.9rem;
            transition: background 0.2s;
        }

        .card a:hover {
            background: #2563eb;
        }

        .extra-tools {
            margin-top: 3rem;
        }

        .extra-tools h2 {
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }

        .extra-links {
            display: flex;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .extra-links a {
            flex: 1;
            min-width: 200px;
            padding: 1rem;
            text-align: center;
            background: #f9fafb;
            border: 2px solid #e5e7eb;
            border-radius: 0.75rem;
            text-decoration: none;
            color: #111;
            transition: all 0.2s;
        }

        .extra-links a:hover {
            border-color: #3b82f6;
            color: #3b82f6;
            background: #eff6ff;
        }

        footer {
            text-align: center;
            padding: 1.5rem;
            background: #f3f4f6;
            margin-top: 3rem;
            font-size: 0.9rem;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <header>
        <h1>Pricing Tool Übersicht</h1>
    </header>

    <main>
        <section class="grid">
            <?php foreach ($marketplaces as $m): ?>
                <div class="card">
                    <img src="<?php echo $m['img']; ?>" alt="<?php echo $m['name']; ?>">
                    <h2><?php echo $m['name']; ?></h2>
                    <a href="<?php echo $m['url']; ?>" target="_blank">Zum Tool</a>
                </div>
            <?php endforeach; ?>
        </section>

        <section class="extra-tools">
            <h2>Zusatz-Tools</h2>
            <div class="extra-links">
                <a href="report.php" target="_blank">📊 Report (alle Marktplätze)</a>
                <a href="error_report.php" target="_blank">⚠️ Error Report (ManoMano)</a>
                <a href="../tric4calc.php" target="_blank">📦 Produktdatenbank</a>
            </div>
        </section>
    </main>

</body>
</html>
