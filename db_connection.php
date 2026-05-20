<?php 

$dbConnectionTric4Calc = new PDO('mysql:dbname=tric4calc;host=127.0.0.1;', 'root', '***REMOVED***');
$dbConnectionTric4Calc->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$dbConnectionTric4Calc->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);

$dbConnectionTric = new PDO('mysql:dbname=***REMOVED***;host=***REMOVED***;', '***REMOVED***', '***REMOVED***');
$dbConnectionTric->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$dbConnectionTric->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);

define("refresh_token", "***REMOVED***");
define("lwa_app_id", "***REMOVED***");
#define("lwa_client_secret", "amzn1.oa2-cs.v1.70ec05fcdae1d61e0898f7775a5ca2fd61ab3c5b54df55bf4f90bb4010139fe3");
define("lwa_client_secret", "***REMOVED***");


define("AWS_ACCESS_KEY", "***REMOVED***");
define("AWS_SECRET_KEY", "***REMOVED***");
define("REGION", "eu-west-1");
define("SELLER_ID", "A6F5BRV91OMPP");
define("MARKETPLACE_ID", "A1PA6795UKMFR9"); 
define("SP_API_ENDPOINT", "https://sellingpartnerapi-eu.amazon.com"); 

?>
