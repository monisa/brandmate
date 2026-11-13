<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:4200')],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => ['X-Request-Id'],
    'max_age' => 3600,
    // Bearer tokens are used, so cookies are never sent cross-origin.
    'supports_credentials' => false,
];
