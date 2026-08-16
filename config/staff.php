<?php

return [
    'access_token_ttl_minutes' => (int) env('STAFF_ACCESS_TOKEN_TTL_MINUTES', 480),
    'max_active_tokens_per_user' => (int) env('STAFF_MAX_ACTIVE_TOKENS_PER_USER', 5),
];
