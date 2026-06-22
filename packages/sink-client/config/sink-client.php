<?php

declare(strict_types=1);

return [
    'url' => env('SINK_URL'),
    'token' => env('SINK_TOKEN'),
    'stream' => env('SINK_STREAM'),
    'allow_production' => (bool) env('SINK_ALLOW_PRODUCTION', false),
    'retry_attempts' => (int) env('SINK_RETRY_ATTEMPTS', 3),
    'retry_base_ms' => (int) env('SINK_RETRY_BASE_MS', 200),
    'timeout' => (float) env('SINK_TIMEOUT', 15),
    'max_message_bytes' => (int) env('SINK_MAX_MESSAGE_BYTES', 10485760),
];
