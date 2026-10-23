<?php

return [

    'max_attempts' => (int) env('NOTIFICATION_MAX_ATTEMPTS', 8),

    'backoff' => [
        'base_seconds' => (int) env('NOTIFICATION_BACKOFF_BASE_SECONDS', 30),
        'max_seconds' => (int) env('NOTIFICATION_BACKOFF_MAX_SECONDS', 300),
        'jitter_seconds' => (int) env('NOTIFICATION_BACKOFF_JITTER_SECONDS', 10),
    ],

    'recovery_minutes' => (int) env('NOTIFICATION_RECOVERY_MINUTES', 15),

    'push' => [
        'driver' => env('NOTIFICATION_PUSH_DRIVER', 'log'),

        'fcm' => [
            'project_id' => env('FCM_PROJECT_ID'),
            'credentials_path' => env('FCM_CREDENTIALS_PATH'),
            'endpoint' => env('FCM_ENDPOINT', 'https://fcm.googleapis.com/v1'),
            'token_uri' => env('FCM_TOKEN_URI', 'https://oauth2.googleapis.com/token'),
        ],
    ],

];
