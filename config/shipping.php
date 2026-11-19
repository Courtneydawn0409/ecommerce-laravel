<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Live-rate shipping provider
    |--------------------------------------------------------------------------
    | Which shipping provider (aggregator or direct carrier) to use for live
    | rates, label creation, tracking and address validation. Null / unset =
    | live rating off; checkout uses the flat DB shipping methods only.
    |
    | Registered providers include: 'easypost', 'shippo', 'shipengine',
    | 'ups', 'fedex', 'dhl', 'royalmail' (or any custom module registered
    | with ShippingProviderRegistryContract).
    */
    'carrier' => env('SHIPPING_CARRIER'),

    // Unit the Product.weight column is stored in ('oz', 'lb', 'kg', or 'g').
    // Providers convert the weight to the unit their API requires.
    'weight_unit' => env('SHIPPING_WEIGHT_UNIT', 'oz'),

    // Per-request timeout (seconds) for shipping provider API calls.
    'timeout' => env('SHIPPING_TIMEOUT', 15),

    // How long a fetched live rate stays selectable at checkout (minutes). After this
    // the buyer must re-fetch — carrier prices drift, so a stale quote is rejected.
    'quote_ttl' => env('SHIPPING_QUOTE_TTL', 30),

    /*
    | Ship-from (origin) address used for rate quotes and label creation.
    */
    'origin' => [
        'name' => env('SHIPPING_ORIGIN_NAME', ''),
        'street1' => env('SHIPPING_ORIGIN_STREET1', ''),
        'city' => env('SHIPPING_ORIGIN_CITY', ''),
        'state' => env('SHIPPING_ORIGIN_STATE', ''),
        'zip' => env('SHIPPING_ORIGIN_ZIP', ''),
        'country' => env('SHIPPING_ORIGIN_COUNTRY', 'US'),
    ],

    'easypost' => [
        'api_key' => env('EASYPOST_API_KEY', ''),
        'sandbox' => (bool) env('EASYPOST_SANDBOX', false),
        'webhook_secret' => env('EASYPOST_WEBHOOK_SECRET', ''),
    ],

    'shippo' => [
        'api_token' => env('SHIPPO_API_TOKEN', ''),
        'sandbox' => (bool) env('SHIPPO_SANDBOX', false),
        'webhook_secret' => env('SHIPPO_WEBHOOK_SECRET', ''),
    ],

    'shipengine' => [
        'api_key' => env('SHIPENGINE_API_KEY', ''),
        'carrier_ids' => array_values(array_filter(explode(',', (string) env('SHIPENGINE_CARRIER_IDS', '')))),
        'sandbox' => (bool) env('SHIPENGINE_SANDBOX', false),
        'webhook_secret' => env('SHIPENGINE_WEBHOOK_SECRET', ''),
    ],

    'ups' => [
        'client_id' => env('UPS_CLIENT_ID', ''),
        'client_secret' => env('UPS_CLIENT_SECRET', ''),
        'access_token' => env('UPS_ACCESS_TOKEN', ''),
        'account_number' => env('UPS_ACCOUNT_NUMBER', ''),
        'sandbox' => (bool) env('UPS_SANDBOX', false),
        'api_version' => env('UPS_API_VERSION', 'v2403'),
    ],

    'fedex' => [
        'client_id' => env('FEDEX_CLIENT_ID', ''),
        'client_secret' => env('FEDEX_CLIENT_SECRET', ''),
        'access_token' => env('FEDEX_ACCESS_TOKEN', ''),
        'account_number' => env('FEDEX_ACCOUNT_NUMBER', ''),
        'sandbox' => (bool) env('FEDEX_SANDBOX', false),
    ],

    'dhl' => [
        'api_key' => env('DHL_API_KEY', ''),
        'api_secret' => env('DHL_API_SECRET', ''),
        'account_number' => env('DHL_ACCOUNT_NUMBER', ''),
        'sandbox' => (bool) env('DHL_SANDBOX', false),
    ],

    'royalmail' => [
        'api_key' => env('ROYALMAIL_API_KEY', ''),
        'sandbox' => (bool) env('ROYALMAIL_SANDBOX', false),
    ],

    // Premium added to a flat method's cost for drop-shipped orders (was previously
    // read from a missing config file, silently always defaulting to 2.00).
    'drop_shipping_premium' => env('SHIPPING_DROP_PREMIUM', 2.00),
];
