<?php

namespace App\Data;

final readonly class EmailNotification
{
    public function __construct(
        public RegistrationConfirmation $confirmation,
        public string $subject,
        public string $idempotencyKey,
        public ?string $pdfContent = null,
        public ?string $pdfFilename = null,
    ) {}
}
