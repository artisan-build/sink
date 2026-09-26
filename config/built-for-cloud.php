<?php

declare(strict_types=1);

use App\Http\Controllers\DashboardController;

return [
    'manifest' => [
        'name' => 'Sink',
        'slug' => 'sink',
        'description' => 'Self-hosted, unmetered staging and test mail capture for Laravel.',
        'icon' => 'https://scalpels.app/img/products/transparent/sink.png',
        'product_url' => 'https://scalpels.app/products/sink',
    ],

    'dashboard' => DashboardController::class,
    'livewire_layout' => false,

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
        'installation_credentials' => true,
        'session_management' => true,
        'managed_transitions' => true,
        'credential_purposes' => ['sink.ingest', 'sink.mcp'],
    ],
];
