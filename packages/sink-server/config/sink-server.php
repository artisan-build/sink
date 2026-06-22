<?php

declare(strict_types=1);

return [
    'route_prefix' => env('SINK_ROUTE_PREFIX', ''),
    'queue' => env('SINK_QUEUE_CONNECTION'),
    'disk' => env('SINK_DISK', env('FILESYSTEM_DISK', 'local')),
    'database' => [
        'connection' => 'sink',
        'host' => env('SINK_DB_HOST'),
        'port' => env('SINK_DB_PORT'),
        'database' => env('SINK_DB_DATABASE'),
        'username' => env('SINK_DB_USERNAME'),
        'password' => env('SINK_DB_PASSWORD'),
    ],
    'retention' => [
        'days' => (int) env('SINK_RETENTION_DAYS', 7),
        'max_messages' => env('SINK_MAX_MESSAGES') === null ? null : (int) env('SINK_MAX_MESSAGES'),
        'max_total_bytes' => env('SINK_MAX_TOTAL_BYTES') === null ? null : (int) env('SINK_MAX_TOTAL_BYTES'),
    ],
    'mcp' => [
        'path' => env('SINK_MCP_PATH', '/mcp'),
        'local_name' => env('SINK_MCP_LOCAL_NAME', 'sink'),
    ],
];
