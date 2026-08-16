<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $query = Event::query()
            ->select(['id', 'name', 'slug', 'timezone', 'starts_on', 'ends_on', 'venue', 'status'])
            ->with([
                'days:id,event_id,label,event_date,sort_order',
                'talkshows:id,event_id,event_day_id,code,title,starts_at,ends_at,capacity,status',
            ])
            ->orderByDesc('starts_on');

        if ($user->hasRole('panitia') && ! $user->hasRole('super_admin')) {
            $query->whereHas('staffAssignments', function ($assignment) use ($user): void {
                $assignment->where('user_id', $user->id)
                    ->where('is_active', true)
                    ->whereHas('role', fn ($role) => $role->where('slug', 'panitia'));
            });
        }

        $events = $query->get()->map(fn (Event $event) => [
            'id' => $event->id,
            'name' => $event->name,
            'slug' => $event->slug,
            'timezone' => $event->timezone,
            'starts_on' => $event->starts_on->toDateString(),
            'ends_on' => $event->ends_on->toDateString(),
            'venue' => $event->venue,
            'status' => $event->status->value,
            'days' => $event->days->map(fn ($day) => [
                'id' => $day->id,
                'label' => $day->label,
                'event_date' => $day->event_date->toDateString(),
                'sort_order' => $day->sort_order,
            ]),
            'talkshows' => $event->talkshows->map(fn ($talkshow) => [
                'id' => $talkshow->id,
                'event_day_id' => $talkshow->event_day_id,
                'code' => $talkshow->code,
                'title' => $talkshow->title,
                'starts_at' => $talkshow->starts_at->toIso8601String(),
                'ends_at' => $talkshow->ends_at->toIso8601String(),
                'capacity' => $talkshow->capacity,
                'status' => $talkshow->status->value,
            ]),
            'capabilities' => [
                'operations' => $user->can('viewOperations', $event),
                'statistics' => $user->can('viewStatistics', $event),
            ],
        ]);

        return response()->json(['data' => $events]);
    }
}
