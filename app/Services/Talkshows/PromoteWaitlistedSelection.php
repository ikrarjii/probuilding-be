<?php

namespace App\Services\Talkshows;

use App\Enums\TalkshowSelectionStatus;
use App\Models\AuditLog;
use App\Models\RegistrationTalkshow;
use App\Models\User;
use App\Services\Access\EventStaffAuthorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PromoteWaitlistedSelection
{
    public function __construct(private readonly EventStaffAuthorizer $eventStaffAuthorizer) {}

    public function handle(RegistrationTalkshow $selection, User $actor, string $reason): RegistrationTalkshow
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => ['A promotion reason is required for the audit trail.'],
            ]);
        }

        return DB::transaction(function () use ($selection, $actor, $reason) {
            $selection = RegistrationTalkshow::query()
                ->with(['registration', 'talkshow'])
                ->lockForUpdate()
                ->findOrFail($selection->id);

            $eventId = $selection->registration->event_id;
            $this->eventStaffAuthorizer->authorize($actor, $eventId, ['panitia']);

            if ($selection->status !== TalkshowSelectionStatus::Waitlisted) {
                throw ValidationException::withMessages([
                    'selection' => ['Only a waitlisted selection can be promoted.'],
                ]);
            }

            $talkshow = $selection->talkshow()->lockForUpdate()->firstOrFail();
            $confirmed = $talkshow->confirmedSelections()->count();

            if ($talkshow->capacity !== null && $confirmed >= $talkshow->capacity) {
                throw ValidationException::withMessages([
                    'selection' => ['The talkshow is still at capacity.'],
                ]);
            }

            $selection->update([
                'status' => TalkshowSelectionStatus::Confirmed,
                'confirmed_at' => now(),
                'promoted_at' => now(),
                'promoted_by_user_id' => $actor->id,
                'promotion_reason' => trim($reason),
            ]);

            AuditLog::create([
                'actor_user_id' => $actor->id,
                'event_id' => $eventId,
                'action' => 'talkshow.waitlist.promoted',
                'subject_type' => RegistrationTalkshow::class,
                'subject_id' => $selection->id,
                'metadata' => [
                    'participant_id' => $selection->registration->participant_id,
                    'registration_id' => $selection->registration_id,
                    'talkshow_id' => $selection->talkshow_id,
                    'reason' => trim($reason),
                ],
                'created_at' => now(),
            ]);

            return $selection->fresh();
        });
    }
}
