<?php

namespace App\Services\Attendance;

use App\Enums\TalkshowSelectionStatus;
use App\Models\AuditLog;
use App\Models\DailyEventCheckin;
use App\Models\Registration;
use App\Models\Talkshow;
use App\Models\TalkshowAttendance;
use App\Models\User;
use App\Services\Access\EventStaffAuthorizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordTalkshowAttendance
{
    public function __construct(private readonly EventStaffAuthorizer $eventStaffAuthorizer) {}

    public function handle(
        Registration $registration,
        Talkshow $talkshow,
        User $actor,
        bool $overridePrerequisite = false,
        ?string $overrideReason = null,
    ): TalkshowAttendance {
        return DB::transaction(function () use (
            $registration,
            $talkshow,
            $actor,
            $overridePrerequisite,
            $overrideReason
        ) {
            if ($registration->event_id !== $talkshow->event_id || ! $talkshow->event_day_id) {
                throw ValidationException::withMessages([
                    'talkshow' => ['The registration and talkshow must belong to the same event day.'],
                ]);
            }

            $this->eventStaffAuthorizer->authorize($actor, $registration->event_id, ['panitia']);

            $hasConfirmedSelection = $registration->talkshowSelections()
                ->where('talkshow_id', $talkshow->id)
                ->where('status', TalkshowSelectionStatus::Confirmed->value)
                ->exists();

            if (! $hasConfirmedSelection) {
                throw ValidationException::withMessages([
                    'talkshow' => ['The participant does not have a confirmed talkshow selection.'],
                ]);
            }

            $hasDailyCheckin = DailyEventCheckin::query()
                ->where('registration_id', $registration->id)
                ->where('event_day_id', $talkshow->event_day_id)
                ->exists();

            if (! $hasDailyCheckin) {
                if (! $overridePrerequisite) {
                    throw ValidationException::withMessages([
                        'check_in' => ['A main event check-in is required for this event day.'],
                    ]);
                }

                if (! $actor->hasRole('super_admin')) {
                    throw new AuthorizationException('Only a Super Admin can override the daily check-in prerequisite.');
                }

                if (trim((string) $overrideReason) === '') {
                    throw ValidationException::withMessages([
                        'override_reason' => ['An override reason is required.'],
                    ]);
                }
            }

            $attendance = TalkshowAttendance::firstOrCreate(
                [
                    'registration_id' => $registration->id,
                    'talkshow_id' => $talkshow->id,
                ],
                [
                    'event_day_id' => $talkshow->event_day_id,
                    'recorded_by_user_id' => $actor->id,
                    'attended_at' => now(),
                    'prerequisite_overridden' => ! $hasDailyCheckin,
                    'overridden_by_user_id' => $hasDailyCheckin ? null : $actor->id,
                    'override_reason' => $hasDailyCheckin ? null : trim((string) $overrideReason),
                ]
            );

            if (! $hasDailyCheckin && $attendance->wasRecentlyCreated) {
                AuditLog::create([
                    'actor_user_id' => $actor->id,
                    'event_id' => $registration->event_id,
                    'action' => 'talkshow.attendance.checkin_prerequisite_overridden',
                    'subject_type' => TalkshowAttendance::class,
                    'subject_id' => $attendance->id,
                    'metadata' => [
                        'participant_id' => $registration->participant_id,
                        'registration_id' => $registration->id,
                        'event_day_id' => $talkshow->event_day_id,
                        'talkshow_id' => $talkshow->id,
                        'admin_user_id' => $actor->id,
                        'reason' => trim((string) $overrideReason),
                    ],
                    'created_at' => now(),
                ]);
            }

            return $attendance;
        });
    }
}
