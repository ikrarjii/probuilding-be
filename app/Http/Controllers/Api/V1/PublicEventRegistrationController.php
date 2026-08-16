<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TalkshowSelectionStatus;
use App\Enums\TalkshowStatus;
use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Talkshow;
use Illuminate\Http\JsonResponse;

class PublicEventRegistrationController extends Controller
{
    public function show(Event $event): JsonResponse
    {
        $talkshows = $event->talkshows()
            ->where('status', TalkshowStatus::Published->value)
            ->with('eventDay')
            ->withCount([
                'selections as confirmed_count' => fn ($query) => $query
                    ->where('status', TalkshowSelectionStatus::Confirmed->value),
            ])
            ->get()
            ->map(fn (Talkshow $talkshow) => $this->talkshowPayload($talkshow));

        return response()->json([
            'data' => [
                'event' => [
                    'id' => $event->id,
                    'name' => $event->name,
                    'slug' => $event->slug,
                    'timezone' => $event->timezone,
                    'starts_on' => $event->starts_on->toDateString(),
                    'ends_on' => $event->ends_on->toDateString(),
                    'venue' => $event->venue,
                    'address' => $event->address,
                    'registration_open' => $event->isRegistrationOpen(),
                ],
                'talkshows' => $talkshows,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function talkshowPayload(Talkshow $talkshow): array
    {
        $remaining = $talkshow->capacity === null
            ? null
            : max(0, $talkshow->capacity - $talkshow->confirmed_count);

        if (! $talkshow->isRegistrationOpen()) {
            $availability = 'closed';
        } elseif ($remaining === 0) {
            $availability = $talkshow->waitlist_enabled ? 'waitlist' : 'full';
        } else {
            $availability = 'available';
        }

        return [
            'id' => $talkshow->id,
            'code' => $talkshow->code,
            'title' => $talkshow->title,
            'description' => $talkshow->description,
            'room' => $talkshow->room,
            'starts_at' => $talkshow->starts_at->toIso8601String(),
            'ends_at' => $talkshow->ends_at->toIso8601String(),
            'event_day' => $talkshow->eventDay ? [
                'id' => $talkshow->eventDay->id,
                'label' => $talkshow->eventDay->label,
                'date' => $talkshow->eventDay->event_date->toDateString(),
            ] : null,
            'capacity' => $talkshow->capacity,
            'remaining_capacity' => $remaining,
            'waitlist_enabled' => $talkshow->waitlist_enabled,
            'registration_starts_at' => $talkshow->registration_starts_at?->toIso8601String(),
            'registration_ends_at' => $talkshow->registration_ends_at?->toIso8601String(),
            'availability' => $availability,
        ];
    }
}
