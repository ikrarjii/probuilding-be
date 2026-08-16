<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Registration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ParticipantController extends Controller
{
    public function index(Request $request, Event $event): JsonResponse
    {
        Gate::authorize('viewOperations', $event);
        $data = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'checkin_status' => ['sometimes', Rule::in(['all', 'not_checked_in', 'checked_in'])],
            'event_day_id' => [
                'sometimes',
                'nullable',
                'uuid',
                Rule::exists('event_days', 'id')->where('event_id', $event->id),
            ],
        ]);
        $search = trim((string) ($data['search'] ?? ''));
        $checkinStatus = $data['checkin_status'] ?? 'all';
        $eventDayId = $data['event_day_id'] ?? null;

        if ($checkinStatus !== 'all' && ! $eventDayId) {
            throw ValidationException::withMessages([
                'event_day_id' => ['Hari event wajib dipilih untuk memfilter status check-in.'],
            ]);
        }

        $query = Registration::query()
            ->where('event_id', $event->id)
            ->with([
                'participant:id,full_name,whatsapp_e164,email,organization,job_title,city,address',
                'dailyCheckins:id,registration_id,event_day_id,checked_in_at',
                'talkshowSelections:id,registration_id,talkshow_id,status',
                'talkshowSelections.talkshow:id,code,title',
                'talkshowAttendances:id,registration_id,talkshow_id,attended_at',
            ])
            ->latest('registered_at');

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $phoneVariants = $this->phoneSearchVariants($search);
            $query->where(function ($match) use ($like, $phoneVariants, $search): void {
                $match->where('registration_number', $search)
                    ->orWhere('registration_number', 'like', $like)
                    ->orWhereHas('participant', function ($participant) use ($like, $phoneVariants, $search): void {
                        $participant->where('full_name', 'like', $like)
                            ->orWhere('email', $search)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('whatsapp_e164', 'like', $like);

                        foreach ($phoneVariants as $phone) {
                            $participant->orWhere(
                                'whatsapp_e164',
                                'like',
                                '%'.addcslashes($phone, '%_\\').'%',
                            );
                        }
                    });

                if (Str::isUuid($search)) {
                    $match->orWhere('id', $search)
                        ->orWhere('participant_id', $search);
                }
            });
        }

        if ($checkinStatus === 'checked_in') {
            $query->whereHas(
                'dailyCheckins',
                fn ($checkin) => $checkin->where('event_day_id', $eventDayId),
            );
        } elseif ($checkinStatus === 'not_checked_in') {
            $query->whereDoesntHave(
                'dailyCheckins',
                fn ($checkin) => $checkin->where('event_day_id', $eventDayId),
            );
        }

        $registrations = $query
            ->paginate($data['per_page'] ?? 25)
            ->through(fn (Registration $registration) => $this->registrationData($registration));

        return response()->json(['data' => $registrations]);
    }

    /** @return list<string> */
    private function phoneSearchVariants(string $search): array
    {
        $digits = preg_replace('/\D+/', '', $search) ?? '';

        if (strlen($digits) < 6) {
            return [];
        }

        $variants = [];

        if (str_starts_with($digits, '0')) {
            $variants[] = '+62'.substr($digits, 1);
        } elseif (str_starts_with($digits, '62')) {
            $variants[] = '+'.$digits;
        } elseif (str_starts_with($digits, '8')) {
            $variants[] = '+62'.$digits;
        }

        return array_values(array_unique($variants));
    }

    private function registrationData(Registration $registration): array
    {
        return [
            'id' => $registration->id,
            'registration_number' => $registration->registration_number,
            'status' => $registration->status->value,
            'registered_at' => $registration->registered_at->toIso8601String(),
            'confirmed_at' => $registration->confirmed_at?->toIso8601String(),
            'participant' => [
                'id' => $registration->participant->id,
                'full_name' => $registration->participant->full_name,
                'whatsapp' => $registration->participant->whatsapp_e164,
                'email' => $registration->participant->email,
                'organization' => $registration->participant->organization,
                'job_title' => $registration->participant->job_title,
                'city' => $registration->participant->city,
                'address' => $registration->participant->address,
            ],
            'daily_checkins' => $registration->dailyCheckins->map(fn ($checkin) => [
                'event_day_id' => $checkin->event_day_id,
                'checked_in_at' => $checkin->checked_in_at->toIso8601String(),
            ])->values(),
            'talkshows' => $registration->talkshowSelections->map(fn ($selection) => [
                'id' => $selection->talkshow_id,
                'code' => $selection->talkshow?->code,
                'title' => $selection->talkshow?->title,
                'status' => $selection->status->value,
                'attended_at' => $registration->talkshowAttendances
                    ->firstWhere('talkshow_id', $selection->talkshow_id)
                    ?->attended_at?->toIso8601String(),
            ])->values(),
        ];
    }
}
