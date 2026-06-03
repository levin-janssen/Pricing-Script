<?php 
require_once __DIR__ . '/load_env.php';

$dbConnectionTric4Calc = new PDO(
    'mysql:dbname=' . getenv('DB_NAME_TRIC4CALC') . ';host=' . getenv('DB_HOST_TRIC4CALC') . ';', 
    getenv('DB_USER_TRIC4CALC'), 
    getenv('DB_PASS_TRIC4CALC')
);
$dbConnectionTric4Calc->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$dbConnectionTric4Calc->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);

$dbConnectionTric = new PDO(
    'mysql:dbname=' . getenv('DB_NAME_TRIC') . ';host=' . getenv('DB_HOST_TRIC') . ';', 
    getenv('DB_USER_TRIC'), 
    getenv('DB_PASS_TRIC')
);
$dbConnectionTric->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$dbConnectionTric->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);

define("refresh_token", getenv('AMZ_REFRESH_TOKEN'));
define("lwa_app_id", getenv('AMZ_APP_ID'));
define("lwa_client_secret", getenv('AMZ_CLIENT_SECRET'));

define("AWS_ACCESS_KEY", getenv('AWS_ACCESS_KEY'));
define("AWS_SECRET_KEY", getenv('AWS_SECRET_KEY'));
define("REGION", "eu-west-1");
define("SELLER_ID", "A6F5BRV91OMPP");
define("MARKETPLACE_ID", "A1PA6795UKMFR9"); 
define("SP_API_ENDPOINT", "https://sellingpartnerapi-eu.amazon.com");