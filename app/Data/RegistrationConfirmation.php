<?php

namespace App\Data;

final readonly class RegistrationConfirmation
{
    /**
     * @param  array<int, array<string, mixed>>  $confirmedTalkshows
     * @param  array<int, array<string, mixed>>  $waitlistedTalkshows
     */
    public function __construct(
        public string $registrationId,
        public string $participantName,
        public string $email,
        public string $whatsapp,
        public string $eventName,
        public string $registrationNumber,
        public string $eventStartsOn,
        public string $eventEndsOn,
        public string $eventTimezone,
        public string $eventVenue,
        public ?string $eventAddress,
        public array $confirmedTalkshows,
        public array $waitlistedTalkshows,
        public string $ticketUrl,
        public string $checkInInstructions,
    ) {}
}
