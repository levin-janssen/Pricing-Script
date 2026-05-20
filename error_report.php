<?php
require_once 'db_connection.php';

$apiKey = "cxkiqBhdGZUBLpWPOyyPDMPs67iZvMJp";

$statement = $dbConnectionTric4Calc->prepare("
    SELECT DISTINCT ASIN 
    FROM tric4calc.Preisgrenzen 
    WHERE min_preis IS NOT NULL AND min_preis != '' AND Land = 'DE'
");
$statement->execute();
$asins = $statement->fetchAll(PDO::FETCH_ASSOC);

$skus = [];
$skuAsinMap = []; // store SKU → ASIN mapping

foreach ($asins as $asin) {
  $asinValue = $asin['ASIN'];
  $statement = $dbConnectionTric4Calc->prepare("SELECT sku FROM tric4calc.Artikel WHERE ASIN = :asin");
  $statement->execute([":asin" => $asinValue]);
  $result = $statement->fetch(PDO::FETCH_ASSOC);

  if ($result && isset($result["sku"])) {
    $sku = $result["sku"];
    $skus[] = $sku;
    $skuAsinMap[$sku] = $asinValue;
  }
}

$curl = curl_init();
$skus = implode(",", $skus);

curl_setopt_array($curl, array(
  CURLOPT_URL => 'https://partnersapi.manomano.com/api/v1/offer-information/offers?seller_contract_id=7877481&skus=' . $skus . '&limit=1000',
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_ENCODING => '',
  CURLOPT_MAXREDIRS => 10,
  CURLOPT_TIMEOUT => 0,
  CURLOPT_FOLLOWLOCATION => true,
  CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
  CURLOPT_CUSTOMREQUEST => 'GET',
  CURLOPT_HTTPHEADER => array(
    'x-api-key: ' . $apiKey,
  ),
));

$response = curl_exec($curl);
curl_close($curl);

$data = json_decode($response);
?>

<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8">
  <title>SKU Error Report - ManoMano</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
  <style>
    body {
      font-family: 'Inter', sans-serif;
      background: #f5f7fa;
      color: #333;
      margin: 0;
      padding: 20px;
    }

    .container {
      max-width: 1000px;
      margin: auto;
      background: #fff;
      border-radius: 16px;
      box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
      padding: 24px;
    }

    h1 {
      text-align: center;
      font-size: 24px;
      margin-bottom: 20px;
      color: #2563eb;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      overflow: hidden;
      border-radius: 12px;
    }

    th,
    td {
      padding: 14px 16px;
      text-align: left;
    }

    th {
      background: #2563eb;
      color: #fff;
      font-weight: 600;
    }

    tr:nth-child(even) {
      background: #f8f9fc;
    }

    tr:hover {
      background: #eef3f9;
      transition: background 0.3s ease;
    }

    td {
      border-bottom: 1px solid #e0e6ed;
    }

    .hinweis {
    font-size: 0.9em;
    color: #666;
    margin-top: 15px;
    background-color: #e9e9e9;
    padding: 10px;
    border-radius: 4px;
}

.hinweis strong {
    color: #2563eb;
}
  </style>
</head>

<body>
  <div class="container">
    <h1>SKU Error Report - ManoMano</h1>
    <table>
      <tr>
        <th>ASIN</th>
        <th>SKU</th>
        <th>Error</th>
      </tr>
      <?php
      // Loop through each item in the 'content' array
      foreach ($data->content as $item) {
        if ($item->status === 'ERROR') {
          $skuRaw = $item->sku;
          $sku = htmlspecialchars($skuRaw);
          $asin = isset($skuAsinMap[$skuRaw]) ? htmlspecialchars($skuAsinMap[$skuRaw]) : "N/A";

          // Build report link
          $reportLink = "report.php?sku=" . urlencode($skuRaw) . "&time_period=7&source=all";

          echo "<tr>";
          echo "<td>" . $asin . "</td>";
          echo "<td><a href='" . $reportLink . "' target='_blank' style='color:#2c3e50; text-decoration:none; font-weight:600;'>" . $sku . "</a></td>";
          echo "<td>" . htmlspecialchars($item->errors[0]) . "</td>";
          echo "</tr>";
        }
      }
      ?>

    </table>
    <div class="hinweis">
            <strong>Hinweis:</strong> Produkte mir Fehlermeldung werden nicht automatisch im Preis aktualisiert!
        </div>
  </div>
</body>

</html>