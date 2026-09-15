<?php

declare(strict_types=1);

return [
    'manifest' => [
        'name' => 'Sink',
        'slug' => 'sink',
        'description' => 'Self-hosted, unmetered staging and test mail capture for Laravel.',
        'icon' => 'https://scalpels.app/products/sink/icon.svg',
        'product_url' => 'https://scalpels.app/products/sink',
    ],

    'credentials' => [
        'guard' => env('BUILT_FOR_CLOUD_CREDENTIAL_GUARD', 'bfc'),
        'declaration' => null,
        'session_guard' => null,
        'app_purposes' => [
            'sink.ingest' => 'consumption',
            'sink.mcp' => 'mcp',
        ],
    ],

    'ui' => [
        'landing_page' => true,
        'member_management' => true,
        'personal_credentials' => false,
        'installation_credentials' => false,
        'session_management' => true,
        'managed_transitions' => true,
        'credential_purposes' => ['sink.ingest', 'sink.mcp'],
    ],
];
