<?php

namespace App\Services\Registrations;

use App\Data\RegistrationOutcome;
use App\Enums\RegistrationSource;
use App\Enums\TalkshowSelectionStatus;
use App\Exceptions\DuplicateRegistrationException;
use App\Exceptions\IdempotencyConflictException;
use App\Models\Event;
use App\Models\EventRegistrationSequence;
use App\Models\OutboxMessage;
use App\Models\Participant;
use App\Models\Registration;
use App\Models\RegistrationTalkshow;
use App\Models\Talkshow;
use App\Services\Notifications\CreateRegistrationNotifications;
use App\Services\Tickets\ETicketAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterParticipant
{
    public function __construct(
        private readonly ETicketAccessService $ticketAccessService,
        private readonly CreateRegistrationNotifications $createRegistrationNotifications,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function handle(Event $event, array $data, ?string $idempotencyKey = null): RegistrationOutcome
    {
        if (! $event->isRegistrationOpen()) {
            throw ValidationException::withMessages([
                'event' => ['Registration for this event is not currently open.'],
            ]);
        }

        $talkshowIds = collect($data['talkshow_ids'] ?? [])->unique()->values();
        $fingerprint = $this->fingerprint($data, $talkshowIds->all());

        if ($idempotencyKey) {
            $existing = Registration::query()
                ->where('event_id', $event->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                return $this->replay($existing, $fingerprint);
            }
        }

        try {
            return DB::transaction(function () use ($event, $data, $talkshowIds, $idempotencyKey, $fingerprint) {
                if ($idempotencyKey) {
                    $existing = Registration::query()
                        ->where('event_id', $event->id)
                        ->where('idempotency_key', $idempotencyKey)
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        return $this->replay($existing, $fingerprint);
                    }
                }

                $duplicate = Registration::query()
                    ->where('event_id', $event->id)
                    ->where('whatsapp_e164', $data['whatsapp'])
                    ->lockForUpdate()
                    ->exists();

                if ($duplicate) {
                    throw new DuplicateRegistrationException;
                }

                $talkshows = Talkshow::query()
                    ->where('event_id', $event->id)
                    ->whereIn('id', $talkshowIds)
                    ->orderBy('starts_at')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                if ($talkshows->count() !== $talkshowIds->count()) {
                    throw ValidationException::withMessages([
                        'talkshow_ids' => ['One or more selected talkshows do not belong to this event.'],
                    ]);
                }

                $participant = Participant::create([
                    'full_name' => $data['full_name'],
                    'whatsapp_e164' => $data['whatsapp'],
                    'email' => $data['email'],
                    'organization' => $data['organization'] ?? null,
                    'job_title' => $data['job_title'] ?? null,
                    'city' => $data['city'] ?? null,
                    'address' => $data['address'] ?? null,
                ]);

                $registrationNumber = $this->nextRegistrationNumber($event);
                $ticketIdentity = $this->ticketAccessService->issue();

                $registration = Registration::create([
                    'event_id' => $event->id,
                    'participant_id' => $participant->id,
                    'registration_number' => $registrationNumber,
                    'whatsapp_e164' => $data['whatsapp'],
                    'email' => $data['email'],
                    'registration_source' => RegistrationSource::Public,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                    'qr_token_hash' => $ticketIdentity['hash'],
                    'qr_token_encrypted' => $ticketIdentity['encrypted'],
                    'ticket_access_token_hash' => $ticketIdentity['hash'],
                    'ticket_access_token_encrypted' => $ticketIdentity['encrypted'],
                    'registered_at' => now(),
                ]);

                $results = [];

                foreach ($talkshowIds as $talkshowId) {
                    /** @var Talkshow $talkshow */
                    $talkshow = $talkshows->get($talkshowId);
                    $results[] = $this->allocateTalkshow($registration, $talkshow);
                }

                $registration->update(['talkshow_selection_result' => $results]);

                OutboxMessage::create([
                    'event_type' => 'registration.created',
                    'aggregate_id' => $registration->id,
                    'payload' => [
                        'registration_id' => $registration->id,
                        'event_id' => $event->id,
                    ],
                    'available_at' => now(),
                ]);

                $this->createRegistrationNotifications->handle($registration);

                return new RegistrationOutcome(
                    $registration->fresh(['participant', 'event']),
                    $results,
                );
            }, 3);
        } catch (QueryException $exception) {
            if ($this->isWhatsAppUniquenessViolation($exception)) {
                throw new DuplicateRegistrationException;
            }

            if ($idempotencyKey && $this->isIdempotencyUniquenessViolation($exception)) {
                $existing = Registration::query()
                    ->where('event_id', $event->id)
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

                if ($existing) {
                    return $this->replay($existing, $fingerprint);
                }
            }

            throw $exception;
        }
    }

    private function nextRegistrationNumber(Event $event): string
    {
        EventRegistrationSequence::query()->insertOrIgnore([
            'event_id' => $event->id,
            'next_number' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = EventRegistrationSequence::query()
            ->where('event_id', $event->id)
            ->lockForUpdate()
            ->firstOrFail();

        $number = $sequence->next_number;
        $sequence->update(['next_number' => $number + 1]);

        return sprintf('%s-%06d', $event->registration_prefix, $number);
    }

    /**
     * @return array<string, mixed>
     */
    private function allocateTalkshow(Registration $registration, Talkshow $talkshow): array
    {
        $base = [
            'talkshow_id' => $talkshow->id,
            'code' => $talkshow->code,
            'title' => $talkshow->title,
        ];

        if (! $talkshow->isRegistrationOpen()) {
            RegistrationTalkshow::create([
                'registration_id' => $registration->id,
                'talkshow_id' => $talkshow->id,
                'status' => TalkshowSelectionStatus::Unavailable,
                'requested_at' => now(),
                'resolution_reason' => 'registration_closed',
            ]);

            return $base + [
                'status' => TalkshowSelectionStatus::Unavailable->value,
                'reason' => 'registration_closed',
            ];
        }

        $confirmedCount = RegistrationTalkshow::query()
            ->where('talkshow_id', $talkshow->id)
            ->where('status', TalkshowSelectionStatus::Confirmed->value)
            ->count();

        if ($talkshow->capacity === null || $confirmedCount < $talkshow->capacity) {
            RegistrationTalkshow::create([
                'registration_id' => $registration->id,
                'talkshow_id' => $talkshow->id,
                'status' => TalkshowSelectionStatus::Confirmed,
                'requested_at' => now(),
                'confirmed_at' => now(),
            ]);

            return $base + [
                'status' => TalkshowSelectionStatus::Confirmed->value,
                'reason' => null,
            ];
        }

        if ($talkshow->waitlist_enabled) {
            $selection = RegistrationTalkshow::create([
                'registration_id' => $registration->id,
                'talkshow_id' => $talkshow->id,
                'status' => TalkshowSelectionStatus::Waitlisted,
                'requested_at' => now(),
                'waitlisted_at' => now(),
                'resolution_reason' => 'capacity_full',
            ]);

            $position = RegistrationTalkshow::query()
                ->where('talkshow_id', $talkshow->id)
                ->where('status', TalkshowSelectionStatus::Waitlisted->value)
                ->where(function ($query) use ($selection) {
                    $query->where('requested_at', '<', $selection->requested_at)
                        ->orWhere(function ($sameTime) use ($selection) {
                            $sameTime->where('requested_at', $selection->requested_at)
                                ->where('id', '<=', $selection->id);
                        });
                })
                ->count();

            return $base + [
                'status' => TalkshowSelectionStatus::Waitlisted->value,
                'reason' => 'capacity_full',
                'waitlist_position' => max(1, $position),
            ];
        }

        RegistrationTalkshow::create([
            'registration_id' => $registration->id,
            'talkshow_id' => $talkshow->id,
            'status' => TalkshowSelectionStatus::Unavailable,
            'requested_at' => now(),
            'resolution_reason' => 'capacity_full',
        ]);

        return $base + [
            'status' => TalkshowSelectionStatus::Unavailable->value,
            'reason' => 'capacity_full',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, string>  $talkshowIds
     */
    private function fingerprint(array $data, array $talkshowIds): string
    {
        sort($talkshowIds);

        $payload = Arr::only($data, [
            'full_name',
            'whatsapp',
            'email',
            'organization',
            'job_title',
            'city',
            'address',
        ]);
        $payload['talkshow_ids'] = $talkshowIds;

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function replay(Registration $registration, string $fingerprint): RegistrationOutcome
    {
        if (! hash_equals((string) $registration->request_fingerprint, $fingerprint)) {
            throw new IdempotencyConflictException;
        }

        return new RegistrationOutcome(
            $registration->loadMissing(['participant', 'event']),
            $registration->talkshow_selection_result ?? [],
            true,
        );
    }

    private function isWhatsAppUniquenessViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'registrations_event_whatsapp_unique')
            || (str_contains($message, 'registrations.event_id')
                && str_contains($message, 'registrations.whatsapp_e164'));
    }

    private function isIdempotencyUniquenessViolation(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'registrations_event_idempotency_unique')
            || (str_contains($message, 'registrations.event_id')
                && str_contains($message, 'registrations.idempotency_key'));
    }
}
