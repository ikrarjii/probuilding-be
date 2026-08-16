<?php

namespace App\Data;

use App\Models\Registration;

readonly class RegistrationOutcome
{
    /**
     * @param  array<int, array<string, mixed>>  $talkshows
     */
    public function __construct(
        public Registration $registration,
        public array $talkshows,
        public bool $idempotentReplay = false,
    ) {}
}
