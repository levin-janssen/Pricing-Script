<?php
require_once 'db_connection.php';
ini_set('error_log', 'error.log');
//require 'vendor/autoload.php';
//use SellingPartnerApi\LWAAuthorizationCredentials; 

function getInfoByEAN($ean, $info)
{
    try {
        $identifiers = $ean;
        $identifiersType = "EAN";
        $marketplaceIds = "A1PA6795UKMFR9";

        $requestParams = [
            "identifiers" => $identifiers,
            "identifiersType" => $identifiersType,
            "marketplaceIds" => $marketplaceIds,
        ];

        $query_string = http_build_query($requestParams);

        $end_point = "https://sellingpartnerapi-eu.amazon.com";
        $uri_path = "/catalog/2022-04-01/items";

        $uri = "$end_point$uri_path?$query_string";

        $headers = array(
            "x-amz-access-token: " . getAccessToken(),
        );

        $ch = curl_init($uri);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($info == "asin") {
            return $data['items'][0]['asin'];
        } else if ($info == "artikelname") {
            return $data['items'][0]['summaries'][0]['itemName'];
        }
        return null;
    } catch (Exception $err) {
        echo $err->getMessage();
        return null;
    }
}


function getLowestPriceAboveMin($data, $minPrice, $abstand_unten)
{
    $validPrices = [];

    foreach ($data['payload']['Offers'] as $offer) {
        if ($offer['SellerId'] !== "A6F5BRV91OMPP") {
            $landedPrice = $offer['ListingPrice']['Amount'] + $offer['Shipping']['Amount'];
            if ($landedPrice >= ($minPrice + $abstand_unten)) {
                $validPrices[] = $landedPrice;
            }
        }
    }

    return !empty($validPrices) ? min($validPrices) : null;
}

function getHighestPriceUnderMax($data, $minPrice, $abstand_oben)
{
    $validPrices = [];

    foreach ($data['payload']['Offers'] as $offer) {
        if ($offer['SellerId'] !== "A6F5BRV91OMPP") {
            $landedPrice = $offer['ListingPrice']['Amount'] + $offer['Shipping']['Amount'];
            if ($landedPrice <= ($minPrice + $abstand_oben)) {
                $validPrices[] = $landedPrice;
            }
        }
    }

    return !empty($validPrices) ? max($validPrices) : null;
}

function getInfoByASIN($data, $info)
{
    if ($info == "buyboxpreis") {
        if (isset($data['payload']['Summary']['BuyBoxPrices'][0]['LandedPrice']['Amount'])) {
            return $data['payload']['Summary']['BuyBoxPrices'][0]['LandedPrice']['Amount'];
        }
    } else if ($info == "bestellrang") {
        if (isset($data['payload']['Summary']['SalesRankings'][0]['Rank'])) {
            return $data['payload']['Summary']['SalesRankings'][0]['Rank'];
        }
    } else if ($info == "offers") {
        if (isset($data['payload']['Offers'])) {
            return $data['payload']['Offers'];
        }
    }
    return null;
}

function sellerOfASIN($data)
{
    $data = getInfoByASIN($data, "offers");
    if (!empty($data)) {
        foreach ($data as $offer) {
            if (isset($offer['SellerId']) && $offer['SellerId'] == "A6F5BRV91OMPP") {
                return True;
            }
        }
    }
    return False;
}

function IsBuyBoxWinnerAPI($data)
{
    $data = getInfoByASIN($data, "offers");
    if (!empty($data)) {
        foreach ($data as $offer) {
            if (isset($offer['SellerId']) && $offer['SellerId'] == "A6F5BRV91OMPP") {
                if (isset($offer['IsBuyBoxWinner']) && $offer['IsBuyBoxWinner'] == "1") {
                    return True;
                }
            }
        }
    }
    return False;
}

function getLowestPrice($data)
{
    $lowestPrice = PHP_FLOAT_MAX;
    if (!isset($data['payload']['Offers'])) {
        error_log("Fehler in getLowestPrice: \n \$data:" . json_encode($data, JSON_PRETTY_PRINT));
        return null;
    }

    foreach ($data['payload']['Offers'] as $offer) {
        if ($offer['SellerId'] !== 'A6F5BRV91OMPP') {
            $landedPrice = $offer['ListingPrice']['Amount'] + $offer['Shipping']['Amount'];
            if ($landedPrice < $lowestPrice) {
                $lowestPrice = $landedPrice;
            }
        }
    }

    $lowestPrice = ($lowestPrice === PHP_FLOAT_MAX) ? null : $lowestPrice;
    return $lowestPrice;
}

function IsBuyBoxWinner($data)
{
    if (!empty($data)) {
        foreach ($data as $offer) {
            if (isset($offer['SellerId']) && $offer['SellerId'] == "A6F5BRV91OMPP") {
                if (isset($offer['IsBuyBoxWinner']) && $offer['IsBuyBoxWinner'] == "1") {
                    return True;
                }
            }
        }
    }
    return False;
}


function getFeaturedOfferExpectedPriceBySKU($sku, $marketplaceId = "A1PA6795UKMFR9", $condition = 'NEW')
{
    // Get access token from existing function
    $accessToken = getAccessToken();

    // API endpoint
    $endpoint = '/batches/products/pricing/2022-05-01/offer/featuredOfferExpectedPrice';

    // Base URL for SP API - replace with your appropriate endpoint
    $baseUrl = 'https://sellingpartnerapi-eu.amazon.com'; // Change region as needed

    // Prepare request body
    $requestBody = [
        'requests' => []
    ];

    $requestBody['requests'][] = [
        'marketplaceId' => $marketplaceId,
        'method' => "GET",
        'sku' => $sku,
        'uri' => "/products/pricing/2022-05-01/offer/featuredOfferExpectedPrice",
        "body" => [],
        "headers" => [
            "reprehenderit_c2" => "<string>",
            "ad2e0" => "<string>"
        ]

    ];


    // Convert request body to JSON
    $jsonBody = json_encode($requestBody);

    // Set up cURL request
    $ch = curl_init($baseUrl . $endpoint);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Accept: application/json',
        'x-amz-access-token: ' . $accessToken,
        'Content-Length: ' . strlen($jsonBody)
    ]);

    // Execute the request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    // Check for errors
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception("cURL Error: $error");
    }

    curl_close($ch);

    // Handle response based on HTTP code
    if ($httpCode >= 200 && $httpCode < 300) {
        $data = json_decode($response, true);
        if (isset($data['responses'][0]['body']['featuredOfferExpectedPriceResults'][0]['featuredOfferExpectedPrice']['listingPrice']['amount'])) {
            // Return the price as a float
            return (float) $data['responses'][0]['body']['featuredOfferExpectedPriceResults'][0]['featuredOfferExpectedPrice']['listingPrice']['amount'];
        } else {
            return $data;
        }
    } else {
        throw new Exception("API Error: HTTP code $httpCode, Response: $response");
    }
}

function getOwnPriceByASIN($asin, $marketplaceId = "A1PA6795UKMFR9")
{
    try {
        $identifiers = $asin;
        $identifiersType = "Asin";

        $requestParams = [
            "MarketplaceId" => $marketplaceId,
            "Asins" => $identifiers,
            "ItemType" => $identifiersType,
        ];

        $query_string = http_build_query($requestParams);

        $end_point = "https://sellingpartnerapi-eu.amazon.com";
        $uri_path = "/products/pricing/v0/price";

        $uri = "$end_point$uri_path?$query_string";

        $headers = array(
            "x-amz-access-token: " . getAccessToken(),
        );

        $ch = curl_init($uri);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if (!empty($data["payload"][0]["Product"]["Offers"][0]["BuyingPrice"]["ListingPrice"]["Amount"])) {
            return $data["payload"][0]["Product"]["Offers"][0]["BuyingPrice"]["ListingPrice"]["Amount"];
        }
        return null;
    } catch (Exception $err) {
        $msg = $err->getMessage();
        echo $msg;
        return null;
    }
}

function getOwnPriceBySKU($sku, $marketplaceId = "A1PA6795UKMFR9")
{
    try {
        $identifiers = $sku;
        $identifiersType = "Sku";

        $requestParams = [
            "MarketplaceId" => $marketplaceId,
            "Skus" => $identifiers,
            "ItemType" => $identifiersType,
        ];

        $query_string = http_build_query($requestParams);

        $end_point = "https://sellingpartnerapi-eu.amazon.com";
        $uri_path = "/products/pricing/v0/price";

        $uri = "$end_point$uri_path?$query_string";

        $headers = array(
            "x-amz-access-token: " . getAccessToken(),
        );

        $ch = curl_init($uri);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        if (!empty($data["payload"][0]["Product"]["Offers"][0]["BuyingPrice"]["ListingPrice"]["Amount"])) {
            return $data["payload"][0]["Product"]["Offers"][0]["BuyingPrice"]["ListingPrice"]["Amount"];
        }
        return null;
    } catch (Exception $err) {
        $msg = $err->getMessage();
        echo $msg;
        return null;
    }
}

function getSkusByASIN($asin)
{
    try {
        $Asins = $asin;
        $ItemType = "Asin";
        $marketplaceId = "A1PA6795UKMFR9";

        $requestParams = [
            "MarketplaceId" => $marketplaceId,
            "ItemType" => $ItemType,
            "Asins" => $Asins,
        ];

        $query_string = http_build_query($requestParams);

        $end_point = "https://sellingpartnerapi-eu.amazon.com";
        $uri_path = "/products/pricing/v0/price";

        $uri = "$end_point$uri_path?$query_string";

        $headers = array(
            "x-amz-access-token: " . getAccessToken(),
        );

        $ch = curl_init($uri);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($response, true);
        $skus = [];

        if (isset($data["payload"][0]["Product"]["Offers"]) && is_array($data["payload"][0]["Product"]["Offers"])) {
            foreach ($data["payload"][0]["Product"]["Offers"] as $offer) {
                if (!empty($offer["SellerSKU"])) {
                    $skus[] = $offer["SellerSKU"];
                }
            }
        }

        return $skus;

    } catch (Exception $err) {
        $msg = $err->getMessage();
        echo $msg;
        return null;
    }
}

/**
 * Holt die EAN für eine gegebene ASIN über die Catalog Items API.
 *
 * @param string $asin Die ASIN des Produkts.
 * @return string|null Die gefundene EAN oder null bei Fehler/nicht gefunden.
 */
function getEANByASIN($asin)
{
    try {
        $includedData = "identifiers"; // Nur Identifiers anfordern
        $marketplaceIds = "A1PA6795UKMFR9"; // DE Marktplatz

        $requestParams = [
            "marketplaceIds" => $marketplaceIds,
            "includedData" => $includedData,
        ];
        $query_string = http_build_query($requestParams);

        $end_point = "https://sellingpartnerapi-eu.amazon.com";
        $uri_path = "/catalog/2022-04-01/items/" . rawurlencode($asin); // ASIN URL-kodieren

        $uri = "$end_point$uri_path?$query_string";

        $headers = ["x-amz-access-token: " . getAccessToken()];

        $ch = curl_init($uri);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Timeout hinzufügen
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);       // Timeout hinzufügen

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            error_log("cURL Error in getEANByASIN for ASIN $asin: " . $curl_error);
            return null;
        }
        if ($http_code !== 200) {
            error_log("SP-API HTTP Error in getEANByASIN for ASIN $asin (Status: $http_code): Response: " . $response);
            return null;
        }

        $data = json_decode($response, true);

        if (isset($data["identifiers"]) && is_array($data["identifiers"])) {
            $marketplace_identifiers = $data["identifiers"][0] ?? null;
            if (isset($marketplace_identifiers["identifiers"]) && is_array($marketplace_identifiers["identifiers"])) {
                foreach ($marketplace_identifiers["identifiers"] as $identifier_info) {
                    if (isset($identifier_info["identifierType"]) && $identifier_info["identifierType"] == "EAN" && !empty($identifier_info["identifier"])) {
                        return $identifier_info["identifier"]; // Erste gefundene EAN zurückgeben
                    }
                }
            }
        }

        // Keine EAN gefunden oder unerwartete Struktur
        error_log("Info: Keine EAN gefunden für ASIN $asin in API Response.");
        return null;

    } catch (Exception $err) {
        // --- KORREKTUR: Fehler loggen statt ausgeben ---
        error_log("Exception in getEANByASIN for ASIN $asin: " . $err->getMessage());
        return null;
    }
}


/**
 * Holt den deutschen Produktnamen für eine gegebene ASIN über die Catalog Items API.
 *
 * @param string $asin Die ASIN des Produkts.
 * @return string|null Der gefundene Name oder null bei Fehler/nicht gefunden.
 */
function getNameByASIN($asin)
{
    try {
        $includedData = "attributes"; // Nur Attribute anfordern
        $marketplaceIds = "A1PA6795UKMFR9";

        $requestParams = [
            "marketplaceIds" => $marketplaceIds,
            "includedData" => $includedData,
            "locale" => "de_DE", // Spezifisch deutsches Locale anfordern
        ];
        $query_string = http_build_query($requestParams);

        $end_point = "https://sellingpartnerapi-eu.amazon.com";
        // --- KORREKTUR: ASIN muss korrekt in den Pfad eingefügt werden ---
        $uri_path = "/catalog/2022-04-01/items/" . rawurlencode($asin); // ASIN URL-kodieren

        $uri = "$end_point$uri_path?$query_string";
        $headers = ["x-amz-access-token: " . getAccessToken()];

        $ch = curl_init($uri);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            error_log("cURL Error in getNameByASIN for ASIN $asin: " . $curl_error);
            return null;
        }
        if ($http_code !== 200) {
            error_log("SP-API HTTP Error in getNameByASIN for ASIN $asin (Status: $http_code): Response: " . $response);
            return null;
        }

        $data = json_decode($response, true);

        if (isset($data["attributes"]["item_name"]) && is_array($data["attributes"]["item_name"])) {
            foreach ($data["attributes"]["item_name"] as $name_info) {
                if (isset($name_info["locale"]) && $name_info["locale"] == "de_DE" && !empty($name_info["value"])) {
                    return $name_info["value"];
                }
                // Fallback, falls locale nicht gesetzt ist, aber language_tag
                else if (isset($name_info["language_tag"]) && $name_info["language_tag"] == "de_DE" && !empty($name_info["value"])) {
                    return $name_info["value"];
                }
            }
        }

        error_log("Info: Keinen deutschen Namen gefunden für ASIN $asin in API Response.");
        return null;

    } catch (Exception $err) {
        error_log("Exception in getNameByASIN for ASIN $asin: " . $err->getMessage());
        return null;
    }
}

function callItemsAPI($asin, $marketplaceId = "A1PA6795UKMFR9")
{
    try {
        $ItemCondition = "New";

        $requestParams = [
            "MarketplaceId" => $marketplaceId,
            "ItemCondition" => $ItemCondition,
        ];

        $query_string = http_build_query($requestParams);

        $end_point = "https://sellingpartnerapi-eu.amazon.com";
        $uri_path = "/products/pricing/v0/items/$asin/offers";

        $uri = "$end_point$uri_path?$query_string";

        $headers = array(
            "x-amz-access-token: " . getAccessToken(),
        );

        $ch = curl_init($uri);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        curl_close($ch);

        return json_decode($response, true);


    } catch (Exception $err) {
        echo $err->getMessage();
        return null;
    }
}

function getAccessToken()
{
    try {
        $baseUrl = "https://api.amazon.com/auth/O2/token";
        $payload = [
            "grant_type" => "refresh_token",
            "refresh_token" => refresh_token,
            "client_id" => lwa_app_id,
            "client_secret" => lwa_client_secret,
        ];

        $options = [
            "http" => [
                "method" => "POST",
                "header" => "Content-Type: application/x-www-form-urlencoded",
                "content" => http_build_query($payload),
            ],
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($baseUrl, false, $context);

        $data = json_decode($response, true);

        return $data["access_token"];
    } catch (Exception $err) {
        echo $err->getMessage();
        return null;
    }
}


function updateAmazonProductPrice(
    string $sku,
    float $newPrice,
    string $productType = 'PRODUCT',
    $marketplaceId = "A1PA6795UKMFR9",
    $currencyCode = 'EUR'
) {
    $accessToken = getAccessToken();

    $endpoint = 'https://sellingpartnerapi-eu.amazon.com';
    $sellerId = "A6F5BRV91OMPP";
    $encodedSku = urlencode($sku);
    $apiUrl = "{$endpoint}/listings/2021-08-01/items/{$sellerId}/{$encodedSku}?marketplaceIds=" . urlencode($marketplaceId);

    $patchOperations = [
        [
            'op' => 'replace',
            'path' => '/attributes/purchasable_offer',
            'value' => [
                [
                    "marketplace_id" => $marketplaceId,
                    "currency" => $currencyCode,
                    "audience" => "ALL",
                    "our_price" => [
                        [
                            "schedule" => [
                                [
                                    "value_with_tax" => (float) number_format($newPrice, 2, '.', '')
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]
    ];



    $requestBody = [
        'productType' => $productType,
        'patches' => $patchOperations,
    ];

    $jsonBody = json_encode($requestBody);

    $ch = curl_init();

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'x-amz-access-token: ' . $accessToken,
    ];

    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrorNum = curl_errno($ch);
    $curlError = curl_error($ch);

    curl_close($ch);

    if ($curlErrorNum > 0) {
        echo "cURL Error ({$curlErrorNum}): {$curlError}";
        return false;
    }

    $decodedResponse = json_decode($responseBody, true);

    if ($httpCode >= 200 && $httpCode < 300) {
        return $decodedResponse;
    } else {
        echo "SP-API Error (HTTP {$httpCode}): " . $responseBody;
        return false;
    }
}

function getListingAttributes(string $sku)
{
    $endpoint = 'https://sellingpartnerapi-eu.amazon.com';
    $sellerId = "A6F5BRV91OMPP";
    $marketplaceId = "A1PA6795UKMFR9";
    $accessToken = getAccessToken();
    if (!$accessToken) {
        return null;
    }

    $encodedSku = urlencode($sku);
    $apiUrl = "{$endpoint}/listings/2021-08-01/items/{$sellerId}/{$encodedSku}";
    $queryParams = http_build_query([
        'marketplaceIds' => $marketplaceId,
        'includedData' => 'attributes'
    ]);
    $apiUrl .= '?' . $queryParams;

    $ch = curl_init();
    $headers = [
        'Accept: application/json',
        'x-amz-access-token: ' . $accessToken
    ];

    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPGET, true); // Explicitly GET
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $responseBody = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return json_decode($responseBody, true); // Return the decoded attributes
    } else {
        error_log("Failed to get listing attributes (HTTP {$httpCode}): " . $responseBody);
        return null;
    }
}











/**
 * Helper: Sign requests with AWS Signature V4
 */
function signRequest($method, $uri, $queryString, $headers, $payload, $service = "execute-api")
{
    $t = gmdate("Ymd\THis\Z");
    $date = substr($t, 0, 8);

    $canonicalHeaders = "";
    foreach ($headers as $key => $value) {
        $canonicalHeaders .= strtolower($key) . ":" . trim($value) . "\n";
    }

    $signedHeaders = implode(";", array_map("strtolower", array_keys($headers)));

    $payloadHash = hash("sha256", $payload);
    $canonicalRequest = "$method\n$uri\n$queryString\n$canonicalHeaders\n$signedHeaders\n$payloadHash";

    $algorithm = "AWS4-HMAC-SHA256";
    $credentialScope = "$date/" . REGION . "/$service/aws4_request";
    $stringToSign = "$algorithm\n$t\n$credentialScope\n" . hash("sha256", $canonicalRequest);

    $kDate = hash_hmac("sha256", $date, "AWS4" . AWS_SECRET_KEY, true);
    $kRegion = hash_hmac("sha256", REGION, $kDate, true);
    $kService = hash_hmac("sha256", $service, $kRegion, true);
    $kSigning = hash_hmac("sha256", "aws4_request", $kService, true);
    $signature = hash_hmac("sha256", $stringToSign, $kSigning);

    $authorizationHeader = "$algorithm Credential=" . AWS_ACCESS_KEY . "/$credentialScope, SignedHeaders=$signedHeaders, Signature=$signature";

    return [$authorizationHeader, $t];
}

/**
 * Step 1: Create a feed document (Corrected)
 */
function createFeedDocument()
{
    $accessToken = getAccessToken();
    $uri = "/feeds/2021-06-30/documents";
    $url = SP_API_ENDPOINT . $uri;

    // 1. Define the request body first
    $body = json_encode(["contentType" => "application/json"]); 

    $headers = [
        "host" => parse_url(SP_API_ENDPOINT, PHP_URL_HOST),
        "content-type" => "application/json",
        "x-amz-access-token" => $accessToken,
    ];
    
    // 2. Use the same body variable for signing
    list($authHeader, $amzDate) = signRequest("POST", $uri, "", $headers, $body);

    $headers["Authorization"] = $authHeader;
    $headers["x-amz-date"] = $amzDate;

    $options = [
        "http" => [
            "method" => "POST",
            "header" => implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers)),
            // 3. And use it again for the request content
            "content" => $body, 
        ],
    ];

    $response = file_get_contents($url, false, stream_context_create($options));
    return json_decode($response, true);
}


/**
 * Step 2: Upload feed content to Amazon S3
 */
function uploadFeedDocument($url, $content)
{
    $options = [
        "http" => [
            "method" => "PUT",
            "header" => "Content-Type: application/json",
            "content" => $content,
        ],
    ];
    $response = file_get_contents($url, false, stream_context_create($options));
    return $response !== false;
}

/**
 * Step 3: Create the feed (tell Amazon to process the uploaded file)
 */
function createFeed($feedDocumentId, array $marketplaceIds) // <-- Now accepts an array
{
    if (empty($marketplaceIds)) {
        throw new Exception("Marketplace IDs array cannot be empty when creating a feed.");
    }

    $accessToken = getAccessToken();
    $uri = "/feeds/2021-06-30/feeds";
    $url = SP_API_ENDPOINT . $uri;

    $body = [
        "feedType" => "JSON_LISTINGS_FEED",
        "marketplaceIds" => $marketplaceIds, // <-- Pass the whole array directly
        "inputFeedDocumentId" => $feedDocumentId,
    ];

    $headers = [
        "host" => parse_url(SP_API_ENDPOINT, PHP_URL_HOST),
        "content-type" => "application/json",
        "x-amz-access-token" => $accessToken,
    ];

    list($authHeader, $amzDate) = signRequest("POST", $uri, "", $headers, json_encode($body));

    $headers["Authorization"] = $authHeader;
    $headers["x-amz-date"] = $amzDate;

    $options = [
        "http" => [
            "method" => "POST",
            "header" => implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers)),
            "content" => json_encode($body),
        ],
    ];

    $response = file_get_contents($url, false, stream_context_create($options));
    return json_decode($response, true);
}

/**
 * Step 4: Poll feed status
 */
function getFeed($feedId)
{
    $accessToken = getAccessToken();
    $uri = "/feeds/2021-06-30/feeds/$feedId";
    $url = SP_API_ENDPOINT . $uri;

    $headers = [
        "host" => parse_url(SP_API_ENDPOINT, PHP_URL_HOST),
        "x-amz-access-token" => $accessToken,
    ];

    list($authHeader, $amzDate) = signRequest("GET", $uri, "", $headers, "");

    $headers["Authorization"] = $authHeader;
    $headers["x-amz-date"] = $amzDate;

    $options = [
        "http" => [
            "method" => "GET",
            "header" => implode("\r\n", array_map(fn($k, $v) => "$k: $v", array_keys($headers), $headers)),
        ],
    ];

    $response = file_get_contents($url, false, stream_context_create($options));
    return json_decode($response, true);
}


function getFeedDocument($feedDocumentId)
{
    $accessToken = getAccessToken();
    if (!$accessToken) {
        throw new Exception("Failed to get access token in getFeedDocument()");
    }

    $uri = "/feeds/2021-06-30/documents/$feedDocumentId";
    $url = SP_API_ENDPOINT . $uri;

    // Required headers before signing
    $headers = [
        "host" => parse_url(SP_API_ENDPOINT, PHP_URL_HOST),
        "x-amz-access-token" => $accessToken,
    ];

    // Sign the request (AWS Signature v4)
    list($authHeader, $amzDate) = signRequest("GET", $uri, "", $headers, "");
    $headers["Authorization"] = $authHeader;
    $headers["x-amz-date"] = $amzDate;

    // Format headers for cURL
    $curlHeaders = [];
    foreach ($headers as $key => $value) {
        $curlHeaders[] = "$key: $value";
    }

    // cURL request
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);
    curl_setopt($ch, CURLOPT_FAILONERROR, true); // Fail on HTTP error codes
    $response = curl_exec($ch);

    if (curl_errno($ch)) {
        $error = curl_error($ch);
        curl_close($ch);
        throw new Exception("cURL error while fetching feed document: $error");
    }

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 400) {
        throw new Exception("HTTP $httpCode returned while fetching feed document: $response");
    }

    $data = json_decode($response, true);

    if (isset($data['url'])) {
        // Download pre-signed document URL
        $documentContent = file_get_contents($data['url']);
        if ($documentContent === false) {
            throw new Exception("Failed to download pre-signed feed document URL");
        }
        return $documentContent;
    }

    throw new Exception("No URL found in feed document response");
}





















function callSpApi($method, $endpoint, $query = [], $payload = null, $region = "eu-west-1")
{
    $accessToken = getAccessToken();
    if (!$accessToken) {
        throw new Exception("Unable to fetch access token");
    }

    // Your AWS IAM keys (the ones you registered with SP-API role)
    $awsAccessKey = AWS_ACCESS_KEY;
    $awsSecretKey = AWS_SECRET_KEY;
    $service = "execute-api";
    $host = "sellingpartnerapi-eu.amazon.com"; // change region if needed
    $uri = $endpoint;
    $algorithm = "AWS4-HMAC-SHA256";

    $t = gmdate("Ymd\THis\Z");
    $dateStamp = gmdate("Ymd"); // Date w/o time

    $canonicalQuery = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    $canonicalHeaders = "host:$host\nx-amz-access-token:$accessToken\nx-amz-date:$t\n";
    $signedHeaders = "host;x-amz-access-token;x-amz-date";

    $payloadHash = hash("sha256", $payload ?? "");

    $canonicalRequest = "$method\n$uri\n$canonicalQuery\n$canonicalHeaders\n$signedHeaders\n$payloadHash";

    $credentialScope = "$dateStamp/$region/$service/aws4_request";
    $stringToSign = "$algorithm\n$t\n$credentialScope\n" . hash("sha256", $canonicalRequest);

    // Signing key
    $kDate = hash_hmac("sha256", $dateStamp, "AWS4" . $awsSecretKey, true);
    $kRegion = hash_hmac("sha256", $region, $kDate, true);
    $kService = hash_hmac("sha256", $service, $kRegion, true);
    $kSigning = hash_hmac("sha256", "aws4_request", $kService, true);

    $signature = hash_hmac("sha256", $stringToSign, $kSigning);

    $authorizationHeader = "$algorithm Credential=$awsAccessKey/$credentialScope, SignedHeaders=$signedHeaders, Signature=$signature";

    $headers = [
        "Content-Type: application/json",
        "Host: $host",
        "x-amz-access-token: $accessToken",
        "x-amz-date: $t",
        "Authorization: $authorizationHeader",
    ];

    $url = "https://$host$uri" . ($canonicalQuery ? "?$canonicalQuery" : "");

    $options = [
        "http" => [
            "method" => $method,
            "header" => implode("\r\n", $headers),
            "content" => $payload ?? "",
        ],
    ];

    $context = stream_context_create($options);
    $response = file_get_contents($url, false, $context);

    if ($response === false) {
        throw new Exception("Error calling SP-API endpoint");
    }

    return json_decode($response, true);
}

function getDefinitionsProductType($productType, $marketplaceIds = ["A1PA6795UKMFR9"])
{
    return callSpApi(
        "GET",
        "/definitions/2020-09-01/productTypes/" . urlencode($productType),
        ["marketplaceIds" => implode(",", $marketplaceIds)]
    );
}



function getFulfillmentChannelBySku($sku, $sellerId, $marketplaceId)
{
    // 1. Get the access token
    $accessToken = getAccessToken();
    if (is_null($accessToken)) {
        echo "Failed to retrieve access token.\n";
        return null;
    }

    // 2. Define the API endpoint and parameters
    $baseUrl = "https://sellingpartnerapi-eu.amazon.com"; // Change for other regions
    $endpoint = "/listings/2021-08-01/items/{$sellerId}/{$sku}";
    $queryParams = http_build_query([
        'marketplaceIds' => $marketplaceId,
        'includedData' => 'fulfillmentAvailability'
    ]);
    
    $url = $baseUrl . $endpoint . '?' . $queryParams;

    // 3. Set up the request headers
    $headers = [
        "x-amz-access-token: " . $accessToken,
        "Content-Type: application/json"
    ];

    // 4. Create the stream context for the GET request
    $options = [
        "http" => [
            "method" => "GET",
            "header" => implode("\r\n", $headers)
        ],
    ];

    $context = stream_context_create($options);

    try {
        // 5. Make the API call
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            // Check for a specific HTTP error response
            $error = error_get_last();
            if (isset($http_response_header)) {
                $statusLine = $http_response_header[0];
                if (strpos($statusLine, '404 Not Found') !== false) {
                    echo "Error: SKU '{$sku}' not found.\n";
                    return null;
                }
            }
            echo "Failed to retrieve data for SKU '{$sku}'. Error: " . ($error['message'] ?? 'Unknown error') . "\n";
            return null;
        }

        // 6. Decode the JSON response
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "Error decoding JSON response: " . json_last_error_msg() . "\n";
            return null;
        }
        
        // 7. Extract the fulfillment channel code
        // The API can return an array of offers. We'll check the first one.
        if (isset($data['fulfillmentAvailability'][0]['fulfillmentChannelCode'])) {
            $fulfillmentChannelCode = $data['fulfillmentAvailability'][0]['fulfillmentChannelCode'];
            return $fulfillmentChannelCode;
        } else {
            echo "Fulfillment channel code not found in the response for SKU '{$sku}'.\n";
            return null;
        }

    } catch (Exception $e) {
        echo "An unexpected error occurred: " . $e->getMessage() . "\n";
        return null;
    }
}

function getQuantityBySku($sku, $sellerId, $marketplaceId)
{
    // 1. Get the access token
    $accessToken = getAccessToken();
    if (is_null($accessToken)) {
        echo "Failed to retrieve access token.\n";
        return null;
    }

    // 2. Define the API endpoint and parameters
    $baseUrl = "https://sellingpartnerapi-eu.amazon.com"; // Change for other regions
    $endpoint = "/listings/2021-08-01/items/{$sellerId}/{$sku}";
    $queryParams = http_build_query([
        'marketplaceIds' => $marketplaceId,
        'includedData' => 'fulfillmentAvailability'
    ]);
    
    $url = $baseUrl . $endpoint . '?' . $queryParams;

    // 3. Set up the request headers
    $headers = [
        "x-amz-access-token: " . $accessToken,
        "Content-Type: application/json"
    ];

    // 4. Create the stream context for the GET request
    $options = [
        "http" => [
            "method" => "GET",
            "header" => implode("\r\n", $headers)
        ],
    ];

    $context = stream_context_create($options);

    try {
        // 5. Make the API call
        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            $error = error_get_last();
            if (isset($http_response_header)) {
                $statusLine = $http_response_header[0];
                if (strpos($statusLine, '404 Not Found') !== false) {
                    echo "Error: SKU '{$sku}' not found.\n";
                    return null;
                }
            }
            echo "Failed to retrieve data for SKU '{$sku}'. Error: " . ($error['message'] ?? 'Unknown error') . "\n";
            return null;
        }

        // 6. Decode the JSON response
        $data = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            echo "Error decoding JSON response: " . json_last_error_msg() . "\n";
            return null;
        }
        
        // 7. Extract the quantity
        // The API can return an array of offers. We'll check the first one.
        if (isset($data['fulfillmentAvailability'][0]['quantity'])) {
            $quantity = $data['fulfillmentAvailability'][0]['quantity'];
            return $quantity;
        } else {
            echo "Quantity not found in the response for SKU '{$sku}'.\n";
            return null;
        }

    } catch (Exception $e) {
        echo "An unexpected error occurred: " . $e->getMessage() . "\n";
        return null;
    }
}

function getProductTitleAndImage(string $asin, string $marketplaceId = 'A1PA6795UKMFR9'): ?array {
    $accessToken = getAccessToken();
    if (!$accessToken) {
        return ['error' => 'Unable to fetch access token'];
    }

    $url = "https://sellingpartnerapi-eu.amazon.com/catalog/2022-04-01/items/{$asin}?marketplaceIds={$marketplaceId}&includedData=attributes,images";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
             "x-amz-access-token: " . $accessToken,
            "Accept: application/json"
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$response) {
        return ['error' => "Failed to fetch product data (HTTP Code: $httpCode)"];
    }

    $json = json_decode($response, true);
    
    // Check if the required keys exist in the top-level JSON.
    if (!isset($json['attributes'], $json['images'])) {
        return ['error' => 'No attributes or images found for the given ASIN.'];
    }
    
    // Correctly access the title from the attributes array.
    $title = $json['attributes']['item_name'][0]['value'] ?? 'No title available';

    // Correctly access the image link from the images array.
    // We'll look for the 'MAIN' variant first.
    $mainImage = null;
    if (isset($json['images'][0]['images'])) {
        foreach ($json['images'][0]['images'] as $image) {
            if ($image['variant'] === 'MAIN') {
                $mainImage = $image['link'];
                break;
            }
        }
    }
    
    $image = $mainImage ?: ''; // Use the main image, or an empty string if not found.

    return [
        'title' => $title,
        'image' => $image
    ];
}

?>