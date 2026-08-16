<?php

return [
    'whatsapp' => [
        'driver' => env('REGISTRATION_WHATSAPP_DRIVER', 'mock'),
        'base_url' => env('REGISTRATION_WHATSAPP_BASE_URL'),
        'access_token' => env('REGISTRATION_WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('REGISTRATION_WHATSAPP_PHONE_NUMBER_ID'),
        'template_name' => env('REGISTRATION_WHATSAPP_TEMPLATE_NAME'),
        'mock_failure' => env('REGISTRATION_WHATSAPP_MOCK_FAILURE', false),
        'mock_log_channel' => env('REGISTRATION_WHATSAPP_MOCK_LOG_CHANNEL', 'whatsapp_mock'),
    ],

    'outbox' => [
        'max_attempts' => env('NOTIFICATION_MAX_ATTEMPTS', 5),
        'retry_base_minutes' => env('NOTIFICATION_RETRY_BASE_MINUTES', 5),
        'claim_timeout_minutes' => env('NOTIFICATION_CLAIM_TIMEOUT_MINUTES', 10),
    ],
];
