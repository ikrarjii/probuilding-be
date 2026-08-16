<?php

return [
    'email' => [
        'driver' => env('REGISTRATION_EMAIL_DRIVER', 'mock'),
        'api_key' => env('REGISTRATION_EMAIL_API_KEY'),
        'from_address' => env('REGISTRATION_EMAIL_FROM_ADDRESS'),
        'from_name' => env('REGISTRATION_EMAIL_FROM_NAME', 'ProBuild INTIM'),
        'attach_pdf' => env('REGISTRATION_EMAIL_ATTACH_PDF', true),
        'mock_failure' => env('REGISTRATION_EMAIL_MOCK_FAILURE', false),
    ],

    'whatsapp' => [
        'driver' => env('REGISTRATION_WHATSAPP_DRIVER', 'mock'),
        'base_url' => env('REGISTRATION_WHATSAPP_BASE_URL'),
        'access_token' => env('REGISTRATION_WHATSAPP_ACCESS_TOKEN'),
        'phone_number_id' => env('REGISTRATION_WHATSAPP_PHONE_NUMBER_ID'),
        'template_name' => env('REGISTRATION_WHATSAPP_TEMPLATE_NAME'),
        'mock_failure' => env('REGISTRATION_WHATSAPP_MOCK_FAILURE', false),
    ],

    'outbox' => [
        'max_attempts' => env('NOTIFICATION_MAX_ATTEMPTS', 5),
        'retry_base_minutes' => env('NOTIFICATION_RETRY_BASE_MINUTES', 5),
        'claim_timeout_minutes' => env('NOTIFICATION_CLAIM_TIMEOUT_MINUTES', 10),
    ],
];
