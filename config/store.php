<?php

return [
    'url' => rtrim(env('APP_URL', 'http://localhost:8000'), '/'),
    'currency' => 'USD',
    'reservation_minutes' => 30,
    'link_minutes' => 60,
    'guest_access_minutes' => 30,
    'states' => explode(',', 'AL,AK,AZ,AR,CA,CO,CT,DE,DC,FL,GA,HI,ID,IL,IN,IA,KS,KY,LA,ME,MD,MA,MI,MN,MS,MO,MT,NE,NV,NH,NJ,NM,NY,NC,ND,OH,OK,OR,PA,RI,SC,SD,TN,TX,UT,VT,VA,WA,WV,WI,WY'),
    'paypal' => [
        'mode' => env('PAYPAL_MODE', 'sandbox'),
        'return_url' => env('PAYPAL_RETURN_URL', rtrim(env('APP_URL', 'http://localhost:8000'), '/').'/api/paypal/return'),
        'cancel_url' => env('PAYPAL_CANCEL_URL', rtrim(env('APP_URL', 'http://localhost:8000'), '/').'/api/paypal/cancel'),
        'callback_minutes' => 120,
        'webhook_base_url' => rtrim(env('PAYPAL_WEBHOOK_BASE_URL', env('APP_URL', 'http://localhost:8000')), '/'),
        'sandbox' => [
            'client_id' => env('PAYPAL_SANDBOX_CLIENT_ID'),
            'client_secret' => env('PAYPAL_SANDBOX_CLIENT_SECRET'),
            'merchant_id' => env('PAYPAL_SANDBOX_MERCHANT_ID'),
            'webhook_id' => env('PAYPAL_SANDBOX_WEBHOOK_ID'),
        ],
        'live' => [
            'client_id' => env('PAYPAL_LIVE_CLIENT_ID'),
            'client_secret' => env('PAYPAL_LIVE_CLIENT_SECRET'),
            'merchant_id' => env('PAYPAL_LIVE_MERCHANT_ID'),
            'webhook_id' => env('PAYPAL_LIVE_WEBHOOK_ID'),
        ],
    ],
];
