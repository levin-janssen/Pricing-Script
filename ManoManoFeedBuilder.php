<?php

class ManoManoFeedBuilder
{
    private $apiKey;
    private $sellerContractId;
    private $offers = [];
    private $maxPayloadSize = 5242880; // 5 MB in bytes

    public function __construct($apiKey = "cxkiqBhdGZUBLpWPOyyPDMPs67iZvMJp", $sellerContractId = 7877481)
    {
        $this->apiKey = $apiKey;
        $this->sellerContractId = $sellerContractId;
    }

    /**
     * Add SKU/price pair to queue
     */
    public function addOffer($sku, $price)
    {
        $this->offers[] = [
            "sku" => $sku,
            "price" => [
                "price_vat_included" => (float) $price
            ]
        ];
    }

    /**
     * Send all queued offers in one PATCH request
     */
    public function send()
    {
        if (empty($this->offers)) {
            return "No offers to update.";
        }

        $chunks = $this->chunkOffers();

        $responses = [];
        foreach ($chunks as $chunk) {
            // Correctly build the full JSON payload structure
            $payload = json_encode([
                "content" => [
                    [
                        "seller_contract_id" => (int) $this->sellerContractId, // Ensure it's an integer
                        "items" => $chunk
                    ]
                ]
            ], JSON_UNESCAPED_SLASHES);

            $curl = curl_init();

            curl_setopt_array($curl, [
                CURLOPT_URL => 'https://partnersapi.manomano.com/api/v2/offer-information/offers',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'PATCH',
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => [
                    'x-api-key: ' . $this->apiKey,
                    'Content-Type: application/json'
                ],
            ]);

            $response = curl_exec($curl);

            if ($response === false) {
                $error = curl_error($curl);
                $responses[] = "cURL error: $error";
            } else {
                $responses[] = $response;
            }

            curl_close($curl);
        }

        $this->offers = [];
        return $responses;
    }

    private function chunkOffers()
    {
        $chunks = [];
        $currentChunk = [];

        foreach ($this->offers as $offer) {
            $testChunk = $currentChunk;
            $testChunk[] = $offer;

            $testPayload = json_encode([
                "seller_contract_id" => $this->sellerContractId,
                "items" => $testChunk
            ], JSON_UNESCAPED_SLASHES);

            if (strlen($testPayload) > $this->maxPayloadSize) {
                // save current chunk
                if (!empty($currentChunk)) {
                    $chunks[] = $currentChunk;
                }
                // start new chunk
                $currentChunk = [$offer];
            } else {
                $currentChunk[] = $offer;
            }
        }

        if (!empty($currentChunk)) {
            $chunks[] = $currentChunk;
        }

        return $chunks;
    }


}


