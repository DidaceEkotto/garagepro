<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Configuration SaSPay (Paiements Mobile Money / Carte)
    |--------------------------------------------------------------------------
    | Règle absolue : aucun secret en dur. Toujours lu depuis .env avec null
    | par défaut, jamais exposé au front, aux logs ou aux props Inertia.
    */
    'secret_key' => env('SASPAY_SECRET_KEY', null) ?: env('SASPAY_API_KEY', null) ?: env('SASPAY_WEBHOOK_SECRET', null),

    'base_url' => env('SASPAY_BASE_URL', 'https://api.saspay.me/api/v1'),

    'webhook_secret' => env('SASPAY_WEBHOOK_SECRET', null),

    'timeout' => 15,

    'retry_times' => 2,

    'retry_sleep_ms' => 300,
];

