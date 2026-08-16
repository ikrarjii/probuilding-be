<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Enums\RegistrationStatus;
use App\Http\Controllers\Controller;
use App\Models\DailyEventCheckin;
use App\Models\Event;
use App\Models\Talkshow;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class StatisticsController extends Controller
{
    public function show(Event $event): JsonResponse
    {
        Gate::authorize('viewStatistics', $event);

        $registrationTotals = $event->registrations()
            ->selectRaw('COUNT(*) AS total_registrations')
            ->selectRaw(
                'SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS confirmed_registrations',
                [RegistrationStatus::Confirmed->value]
            )
            ->first();

        $checkedInParticipants = DailyEventCheckin::query()
            ->whereHas('registration', fn ($query) => $query->where('event_id', $event->id))
            ->distinct('registration_id')
            ->count('registration_id');

        $attendanceByDay = $event->days()
            ->withCount('dailyCheckins')
            ->get(['id', 'event_id', 'label', 'event_date', 'sort_order'])
            ->map(fn ($day) => [
                'event_day_id' => $day->id,
                'label' => $day->label,
                'event_date' => $day->event_date->toDateString(),
                'checked_in_participants' => $day->daily_checkins_count,
            ]);

        $talkshows = Talkshow::query()
            ->where('event_id', $event->id)
            ->withCount(['confirmedSelections', 'attendances'])
            ->orderBy('starts_at')
            ->get(['id', 'event_day_id', 'code', 'title', 'capacity', 'starts_at'])
            ->map(fn (Talkshow $talkshow) => [
                'talkshow_id' => $talkshow->id,
                'event_day_id' => $talkshow->event_day_id,
                'code' => $talkshow->code,
                'title' => $talkshow->title,
                'capacity' => $talkshow->capacity,
                'confirmed_registrations' => $talkshow->confirmed_selections_count,
                'attendance' => $talkshow->attendances_count,
            ]);

        return response()->json(['data' => [
            'event' => [
                'id' => $event->id,
                'name' => $event->name,
                'slug' => $event->slug,
                'starts_on' => $event->starts_on->toDateString(),
                'ends_on' => $event->ends_on->toDateString(),
            ],
            'summary' => [
                'total_registrations' => (int) $registrationTotals->total_registrations,
                'confirmed_registrations' => (int) ($registrationTotals->confirmed_registrations ?? 0),
                'checked_in_participants' => $checkedInParticipants,
            ],
            'attendance_by_event_day' => $attendanceByDay,
            'talkshows' => $talkshows,
        ]]);
    }
}
