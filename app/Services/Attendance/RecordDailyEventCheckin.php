<?php

namespace App\Services\Attendance;

use App\Models\DailyEventCheckin;
use App\Models\EventDay;
use App\Models\Registration;
use App\Models\User;
use App\Services\Access\EventStaffAuthorizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RecordDailyEventCheckin
{
    public function __construct(private readonly EventStaffAuthorizer $eventStaffAuthorizer) {}

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

        return DB::transaction(fn () => DailyEventCheckin::firstOrCreate(
            [
                'registration_id' => $registration->id,
                'event_day_id' => $eventDay->id,
            ],
            [
                'checked_in_by_user_id' => $actor->id,
                'checked_in_at' => now(),
            ]
        ));
    }
}
