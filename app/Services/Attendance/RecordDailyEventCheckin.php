<?php

namespace App\Services\Attendance;

use App\Models\DailyEventCheckin;
use App\Models\EventDay;
use App\Models\Registration;
use App\Models\User;
use App\Services\Access\EventStaffAuthorizer;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RecordDailyEventCheckin
{
    public function __construct(
        private readonly EventStaffAuthorizer $eventStaffAuthorizer,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function handle(
        Registration $registration,
        EventDay $eventDay,
        User $actor,
    ): DailyEventCheckin {
        if ($registration->event_id !== $eventDay->event_id) {
            throw ValidationException::withMessages([
                'event_day' => ['The event day does not belong to this registration event.'],
            ]);
        }

        $this->eventStaffAuthorizer->authorize($actor, $registration->event_id, ['panitia']);

        return DB::transaction(function () use ($registration, $eventDay, $actor): DailyEventCheckin {
            $id = (string) Str::uuid();
            $created = DB::table('daily_event_checkins')->insertOrIgnore([
                'id' => $id,
                'registration_id' => $registration->id,
                'event_day_id' => $eventDay->id,
                'checked_in_by_user_id' => $actor->id,
                'checked_in_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]) === 1;

            $checkin = DailyEventCheckin::query()
                ->where('registration_id', $registration->id)
                ->where('event_day_id', $eventDay->id)
                ->firstOrFail();
            $checkin->wasRecentlyCreated = $created;

            if ($created) {
                $this->auditLogger->record(
                    'event.checkin_recorded',
                    $actor,
                    $checkin,
                    $registration->event_id,
                    [
                        'registration_id' => $registration->id,
                        'event_day_id' => $eventDay->id,
                    ],
                );
            }

            return $checkin;
        }, 3);
    }
}
