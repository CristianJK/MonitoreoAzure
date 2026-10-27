<?php

return [

    /*
    |--------------------------------------------------------------------------
    | CORS
    |--------------------------------------------------------------------------
    |
    | La SPA usa la cookie de sesión de Sanctum, así que solo se acepta su
    | origen y con credenciales. Si SPA y API se publican en el mismo host
    | este archivo no tiene efecto.
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CORS_ALLOWED_ORIGINS', env('FRONTEND_URL', 'http://localhost:5173'))),
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => true,

];
