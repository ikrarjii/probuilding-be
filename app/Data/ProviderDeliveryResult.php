<?php

namespace App\Data;

final readonly class ProviderDeliveryResult
{
    public function __construct(
        public string $provider,
        public ?string $messageId = null,
    ) {}
}
