<?php

return [
    // This application intentionally uses Midtrans Sandbox only.
    'server_key' => env('MIDTRANS_SERVER_KEY'),
    'client_key' => env('MIDTRANS_CLIENT_KEY'),
    'snap_url' => 'https://app.sandbox.midtrans.com/snap/v1/transactions',
    'api_url' => 'https://api.sandbox.midtrans.com/v2',
    'script_url' => 'https://app.sandbox.midtrans.com/snap/snap.js',
    'expiry_hours' => 24,
    // Optional PEM CA bundle; Windows otherwise uses its trusted certificate store.
    'ca_bundle' => env('MIDTRANS_CA_BUNDLE'),
];
