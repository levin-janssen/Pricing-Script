<!DOCTYPE HTML>
<html><head>
<meta name="robots" content="noindex,nofollow">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<link rel="stylesheet" type="text/css" href="pakete_infos.css"/>
<link rel="stylesheet" href="https://cdn.datatables.net/2.2.2/css/dataTables.dataTables.css" />
<script src="https://cdn.datatables.net/2.2.2/js/dataTables.js"></script>
</head>
<body>

<?php
 error_reporting(E_ALL);
 ini_set('default_charset', 'UTF-8');
 mb_internal_encoding("UTF-8");
 
// $pdo = new PDO('mysql:host=localhost;dbname=tric', 'root', '***REMOVED***');
require_once APP_ROOT . '/config/db_connection.php';
$pdo = $dbConnectionTric;

if(!isset($_GET['datum'])) $_GET['datum']=date("d.m.Y");

if($_GET["datum"])
 $datum=$_GET["datum"];

if($_GET["datum"]=="")
$datum=date("d.m.Y");

error_reporting(1024);

$today = date("d.m.Y");
$uhrzeit = date("H:i");
$today." - ".$uhrzeit." Uhr";


$sql = "SELECT titel,count(*) as Anzahl FROM `lieferungen` JOIN scanstation on scanstation_ID=scanstation.id WHERE versandart='' GROUP BY scanstation_ID";
echo "<div id='sidebar_container'> <div id='stillOpen_container' class='container_div'>";

echo "<h2>noch offen:</h2>\n";
echo "<table>\n";
 foreach ($pdo->query($sql) as $row) {
   echo "<tr><td>".$row['titel']."</td><td> ".$row['Anzahl']."</td></tr>\n";
}

echo "</table> </div>\n <div id='daytotal_container' class='container_div'>";

$datum_morgen=date("d.m.Y", strtotime($datum) + (3600 * 24));
$datum_gestern=date("d.m.Y", strtotime($datum) - (3600 * 24));
$datum_sieben=date("d.m.Y", strtotime($datum) - (3600 * 24*7));
$sql = "SELECT scanstation_ID,versandart,versandedit,count(*) as Pakete,versanddatum FROM `lieferungen` WHERE versandart='ja' AND SUBSTRING(versanddatum,1,10) = '".(DateTime::createFromFormat('d.m.Y', $datum)->format('Y-m-d'))."' GROUP BY versandedit ORDER BY `Pakete` DESC";
echo "
<table><tr>
<td><a href=\"pakete_infos.php?datum=".$datum_gestern."\"><h2> < </h2> </a> </td><td><h2>"
.$datum.
"</h2></td><td> <a href=\"pakete_infos.php?datum=".$datum_morgen."\"> <h2> > </h2> </a></td>
</h2> </tr></table>
";
echo "<table>";
 foreach ($pdo->query($sql) as $row) {
      if($row['versandedit']=="") $row['versandedit']="amazon FBA";
      echo "<tr><td>".$row['versandedit']."</td><td> ".$row['Pakete']."</td></tr>";
}
echo "</table> </div>\n <div id='monthtotal_container' class='container_div'>";


$sql = "SELECT scanstation_ID,versandart,versandedit,count(*) as Pakete,versanddatum FROM `lieferungen` WHERE versandart='ja' AND versanddatum LIKE '".date("Y-m-")."%' GROUP BY versandedit ORDER BY `Pakete` DESC";

echo "<h2>".date("F Y")."<br></h2>\n";
echo "<table>\n";
$i=0;
$summe=0;
 foreach ($pdo->query($sql) as $row) {
   if($row['versandedit']=="") $row['versandedit']="amazon FBA";
   echo "<tr><td>".$i."</td><td>".$row['versandedit']."</td><td> ".$row['Pakete']."</td></tr>\n";
	$summe=$summe+$row['Pakete'];	
	  $i++;
}
echo "<tr><td colspan=\"2\">Summe:</td><td>".$summe."</td></tr>";
echo "</table>\n </div></div>";

$mainSql = "
    SELECT 
        SUM(lp.anzahl) as anzahl, 
        pfw.wert1 as artnr,
        p.titel,
        p.keywords 
    FROM lieferungen_positionen lp
    JOIN lieferungen l ON l.ID = lp.lieferungsid 
    JOIN produkte p ON p.ID = lp.produktid 
    JOIN produkte_felder_werte pfw ON p.ID = pfw.produktid 
    WHERE 
        l.versandart = '' 
        AND l.kundennummer NOT IN ('126948', '451641', '451642') 
        AND pfw.feldid = '44' 
        AND l.lieferart NOT IN ('16', '17') 
    GROUP BY lp.produktid 
    ORDER BY SUM(lp.anzahl) DESC
";

$vendorSql = "
    SELECT 
        SUM(lp.anzahl) as anzahl, 
        pfw.wert1 as artnr,
        p.titel,
        p.keywords 
    FROM lieferungen_positionen lp
    JOIN lieferungen l ON l.ID = lp.lieferungsid 
    JOIN produkte p ON p.ID = lp.produktid 
    JOIN produkte_felder_werte pfw ON p.ID = pfw.produktid 
    WHERE 
        l.versandart = '' 
        AND l.kundennummer IN ('126948', '451641', '451642') 
        AND pfw.feldid = '44' 
        AND l.lieferart NOT IN ('16', '17') 
    GROUP BY lp.produktid 
    ORDER BY SUM(lp.anzahl) DESC
";

echo "<div id='result_container'> <div class='table_container container_div'>";
echo "<h1>Positionen:<br></h1>\n";
echo "<table id='resultTable' class='display'>";
echo "<thead><tr><th>Anzahl</th><th>Artikel</th><th>Link</th><th>Order 1</th><th>Order 2</th><th>Order 3</th></tr></thead><tbody>";

$replacements = [
    '(Otto)' => '<img src="img/otto.png" alt="Otto" width="35" height="16">',
    '(Amazon)' => '<img src="img/amazon.png" alt="Amazon" width="16" height="16">',
    '(manomano)' => '<img src="img/manomano.png" alt="Manomano" width="16" height="16">',
    '(eBay)' => '<img src="img/ebay.png" alt="eBay" width="40" height="16">'
];

// Prepare the recent orders query once
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

$i = 0;
$ordersHtmlMail = '';
foreach ($pdo->query($mainSql) as $row) {
    $artnr = $row['artnr'];
    $titel = empty($row['titel']) ? $row['keywords'] : $row['titel'];
    
    // Execute the prepared statement with bound parameter
    $orderStmt->bindParam(':artnr', $artnr, PDO::PARAM_STR);
    $orderStmt->execute();
    $result = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process order data
    $ordersHtml = '';
    
    foreach ($result as $item) {
        $price = number_format((float)$item["einzelpreis"]*1.19, 2, ',', '') . "€ (" . $item["titel"] . ")";
        $ordersHtml .= "<td>" . str_replace(array_keys($replacements), array_values($replacements), $price) . "</td>";
    }
    
    // Fill empty order cells if less than 3 orders
    $emptyOrderCells = str_repeat("<td></td>", 3 - count($result));
    $ordersHtml .= $emptyOrderCells;
    
    // Format title with proper encoding
    $formattedTitle = mb_convert_encoding($titel, 'UTF-8', 
    mb_detect_encoding($titel, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true));
    
    // Determine row color based on article number
    $titleStyle = '';
    if (substr($artnr, 0, 4) == "8-21") {
        $titleStyle = "color='blue'";
    } elseif (substr($artnr, 0, 3) == "10-") {
        $titleStyle = "color='red'";
    } elseif (substr($artnr, 0, 5) == "14-15") {
        $titleStyle = "color='green'";
    }
    
    // Output the row
    echo "<tr>
            <td valign=\"top\">{$row['anzahl']}</td>
            <td valign=\"top\" width=\"50%\"><font $titleStyle>$formattedTitle</font></td>
            <td><a href=\"http://192.168.3.191:888/?ArtNr=$artnr\" target=\"_blank\">$artnr</a></td>
            $ordersHtml
          </tr>\n";
    $i++;
$ordersHtmlMail .= $ordersHtml;

}

echo "</tbody></table>\n";
echo "</div><div class='table_container container_div'>";
echo "<h1>Vendor:<br></h1>\n";
echo "<table id='vendorTable' class='display'>";
echo "<thead><tr><th>Anzahl</th><th>Artikel</th><th>Link</th><th>Order 1</th><th>Order 2</th><th>Order 3</th></tr></thead><tbody>";

$replacements = [
    '(Otto)' => '<img src="img/otto.png" alt="Otto" width="35" height="16">',
    '(Amazon)' => '<img src="img/amazon.png" alt="Amazon" width="16" height="16">',
    '(manomano)' => '<img src="img/manomano.png" alt="Manomano" width="16" height="16">',
    '(eBay)' => '<img src="img/ebay.png" alt="eBay" width="40" height="16">'
];

// Prepare the recent orders query once
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

$i = 0;
foreach ($pdo->query($vendorSql) as $row) {
    $artnr = $row['artnr'];
    $titel = empty($row['titel']) ? $row['keywords'] : $row['titel'];
    
    // Execute the prepared statement with bound parameter
    $orderStmt->bindParam(':artnr', $artnr, PDO::PARAM_STR);
    $orderStmt->execute();
    $result = $orderStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process order data
    $ordersHtml = '';
    foreach ($result as $item) {
        $price = number_format((float)$item["einzelpreis"]*1.19, 2, ',', '') . "€ (" . $item["titel"] . ")";
        $ordersHtml .= "<td>" . str_replace(array_keys($replacements), array_values($replacements), $price) . "</td>";
    }
    
    // Fill empty order cells if less than 3 orders
    $emptyOrderCells = str_repeat("<td></td>", 3 - count($result));
    $ordersHtml .= $emptyOrderCells;
    
    // Format title with proper encoding
    $formattedTitle = mb_convert_encoding($titel, 'UTF-8', 
    mb_detect_encoding($titel, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true));
    
    // Determine row color based on article number
    $titleStyle = '';
    if (substr($artnr, 0, 4) == "8-21") {
        $titleStyle = "color='blue'";
    } elseif (substr($artnr, 0, 3) == "10-") {
        $titleStyle = "color='red'";
    } elseif (substr($artnr, 0, 5) == "14-15") {
        $titleStyle = "color='green'";
    }
    
    // Output the row
    echo "<tr>
            <td valign=\"top\">{$row['anzahl']}</td>
            <td valign=\"top\" width=\"50%\"><font $titleStyle>$formattedTitle</font></td>
            <td><a href=\"http://192.168.3.191:888/?ArtNr=$artnr\" target=\"_blank\">$artnr</a></td>
            $ordersHtml
          </tr>\n";

    $i++;

}

echo "</tbody></table>\n";
echo "</div></div>";


//$header[] = 'To: cga@bauxxl.de';
//$header[] = 'MIME-Version: 1.0';
//$header[] = 'Content-type: text/html; charset=iso-8859-1';
//$header[] = 'From: MailRoboterbauXXL <mailRoboter@bauxxl.de>';

//mail("cga@bauxxl.de","heutige Verkaufsliste",$ordersHtmlMail,implode("\r\n", $header))



?>
<script>

function checkAndColorRows() {
        // Get the table
        const table = document.getElementById('resultTable');
        
        // Loop through each row (skip the header row)
        for (let i = 1; i < table.rows.length; i++) {
            const row = table.rows[i];
            const col3 = row.cells[3].textContent;
            const col4 = row.cells[4].textContent;
            const col5 = row.cells[5].textContent;

            // Compare the values of the last three columns
            if (col3 !== col4 || col3 !== col5 || col4 !== col5) {
                // If not the same, add the 'red-text' class
                row.cells[3].classList.add('red-text');
                row.cells[4].classList.add('red-text');
                row.cells[5].classList.add('red-text');
            }
        }
    }

    // Run the function when the page loads
    window.onload = checkAndColorRows;


	let table = new DataTable('#resultTable', {
		paging: false,
		scrollY: '87.1vh',
		"bInfo" : false,
		order: [[0, 'desc']],
		columnDefs: [
        { targets: [3, 4, 5], className: 'dt-left' }
    	]
	});

    let vendortable = new DataTable('#vendorTable', {
		paging: false,
		scrollY: '87.1vh',
		"bInfo" : false,
		order: [[0, 'desc']],
		columnDefs: [
        { targets: [3, 4, 5], className: 'dt-left' }
    	]
	});

	function preloadImages(array) {
		console.log("log: caching images");
		if (!preloadImages.list) {
			preloadImages.list = [];
		}
		var list = preloadImages.list;
		for (var i = 0; i < array.length; i++) {
			var img = new Image();
			img.onload = function() {
				var index = list.indexOf(this);
				if (index !== -1) {
					// remove image from the array once it's loaded
					// for memory consumption reasons
					list.splice(index, 1);
				}
			}
			list.push(img);
			img.src = array[i];
		}
	}

	preloadImages(["img/amazon.png", "img/otto.png", "img/manomano.png", "img/ebay.png"]);
</script>
</body>
</html>

