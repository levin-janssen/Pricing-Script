<?php

class AmazonFeedBuilder
{
    // Use an associative array to group patches by SKU
    private $skuData = []; 
    private $messageId = 1;
    private $sellerId;
    private $version = "2.0";
    private $issueLocale = "de_DE";

    public function __construct($sellerId, $version = "2.0", $issueLocale = "de_DE")
    {
        $this->sellerId = $sellerId;
        $this->version = $version;
        $this->issueLocale = $issueLocale;
    }

    /**
     * Helper function to add a patch operation to the correct SKU message.
     */
    private function addPatch($sku, $patch)
    {
        // If this is the first time we see this SKU, create its base message structure.
        if (!isset($this->skuData[$sku])) {
            $this->skuData[$sku] = [
                "messageId" => $this->messageId++,
                "operationType" => "PATCH",
                "sku" => $sku,
                "productType" => "PRODUCT",
                "patches" => []
            ];
        }

        // Add the new patch to this SKU's list of patches.
        $this->skuData[$sku]['patches'][] = $patch;
    }

    public function addBusinessPrice($sku, $currency, $marketplaceId, $businessPrice)
    {
        $patch = [
            "op" => "replace",
            "path" => "/attributes/purchasable_offer",
            "value" => [
                [
                    "currency" => $currency,
                    "audience" => "B2B",
                    "marketplace_id" => $marketplaceId,
                    "our_price" => [
                        [
                            "schedule" => [
                                [
                                    "value_with_tax" => round((float)$businessPrice, 2)
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ];

        $this->addPatch($sku, $patch);
    }

    public function addHandlingTime($sku, $handlingDays, $quantity)
    {
        $fulfillmentChannel = (substr($sku, -4) === '_FBA') ? 'AMAZON_EU' : 'DEFAULT';
        
        $patch = [
            "op" => "replace",
            "path" => "/attributes/fulfillment_availability",
            "value" => [
                [
                    "fulfillment_channel_code" => $fulfillmentChannel,
                    "quantity" => (int)$quantity,
                    "lead_time_to_ship_max_days" => (int)$handlingDays,
                ]
            ]
        ];

        $this->addPatch($sku, $patch);
    }

    public function build()
    {
        return json_encode([
            "header" => [
                "sellerId" => $this->sellerId,
                "version" => $this->version,
                "issueLocale" => $this->issueLocale,
            ],
            // Use array_values to convert the associative array back to a simple indexed array for the final JSON.
            "messages" => array_values($this->skuData) 
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}