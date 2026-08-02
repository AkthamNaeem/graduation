<?php

return [
    'stream' => [
        'duration_seconds' => (int) env('REALTIME_STREAM_DURATION_SECONDS', 25),
        'poll_interval_milliseconds' => (int) env('REALTIME_STREAM_POLL_INTERVAL_MS', 1000),
        'batch_size' => (int) env('REALTIME_STREAM_BATCH_SIZE', 50),
    ],

    'push' => [
        'enabled' => (bool) env('FCM_ENABLED', false),
        'project_id' => env('FCM_PROJECT_ID'),
        'service_account_json' => env('FCM_SERVICE_ACCOUNT_JSON'),
        'service_account_path' => env('FCM_SERVICE_ACCOUNT_PATH'),
        'timeout_seconds' => (int) env('FCM_TIMEOUT_SECONDS', 10),
        'connect_timeout_seconds' => (int) env('FCM_CONNECT_TIMEOUT_SECONDS', 5),
    ],
];
