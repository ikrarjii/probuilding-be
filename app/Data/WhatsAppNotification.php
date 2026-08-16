<?php

namespace App\Data;

final readonly class WhatsAppNotification
{
    public function __construct(
        public RegistrationConfirmation $confirmation,
        public string $body,
        public string $idempotencyKey,
    ) {}
}
