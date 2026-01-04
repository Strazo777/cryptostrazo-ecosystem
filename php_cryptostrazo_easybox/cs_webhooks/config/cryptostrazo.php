<?php
declare(strict_types=1);

/**
 * CryptoStrazo Webhook Client config (merchant-side).
 * Fill ONLY:
 *  - public_key 
 *  - secret     
 *  - db settings
 */
return [
    // Optional: used only for diagnostics / matching (not required for signature itself)
    'public_key' => 'public_key',

    // Required: HMAC secret used by CryptoStrazo to sign webhooks
    'secret' => 'secret',

    // Anti-replay window (seconds). Default 300 = 5 minutes.
    'max_drift_seconds' => 300,

    // Headers (must match the sender)
    'delivery_header'   => 'X-STRZ-Delivery-Id',
    'timestamp_header'  => 'X-STRZ-Timestamp',
    'signature_header'  => 'X-STRZ-Signature',
    'event_header'      => 'X-STRZ-Event',

    // Signature base format: "{timestamp}.{rawBody}"
    'signature_base_format' => '{timestamp}.{body}',

    // Database configuration (PDO)
    // driver: 'auto' | 'mysql' | 'sqlite'
    // - auto: uses mysql if host+name+user set, otherwise sqlite.
    'db' => [
        'driver'  => 'mysql',

        // MySQL / MariaDB (recommended on shared hosting)
        'host'    => 'localhost',
        'name'    => 'db_name',
        'user'    => 'user_name',
        'pass'    => 'your_pass',
        'charset' => 'utf8mb4',

        // SQLite (fallback / quick deploy)
        'sqlite_path' => __DIR__ . '/../storage/strz.sqlite',
    ],

    // Debug UI: show last verified payload at GET /webhooks/cryptostrazo/last
    'debug_ui' => false,
    'debug_token' => '', // set token and use /last?token=...
];
