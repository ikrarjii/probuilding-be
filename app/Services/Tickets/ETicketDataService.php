<?php

namespace App\Services\Tickets;

use App\Enums\TalkshowSelectionStatus;
use App\Exceptions\TicketGenerationException;
use App\Models\Registration;
use App\Models\RegistrationTalkshow;
use Illuminate\Support\Collection;

class ETicketDataService
{
    public function __construct(private readonly QrCodeRenderer $qrCodeRenderer) {}

    /**
     * @return array<string, mixed>
     */
    public function build(Registration $registration): array
    {
        $registration->loadMissing([
            'participant',
            'event.days',
            'talkshowSelections.talkshow.eventDay',
            'dailyCheckins',
        ]);

        if (! $registration->participant || ! $registration->event) {
            throw new TicketGenerationException('Data peserta atau event tidak lengkap.');
        }

        if ($registration->event->days->isEmpty()) {
            throw new TicketGenerationException('Data hari event belum tersedia.');
        }

        $qrCode = $this->qrCodeRenderer->render($registration);
        $checkinsByDay = $registration->dailyCheckins->keyBy('event_day_id');
        $checkedInDays = $checkinsByDay->count();
        $totalDays = $registration->event->days->count();

        $overallStatus = match (true) {
            $checkedInDays === 0 => 'not_checked_in',
            $checkedInDays === $totalDays => 'checked_in',
            default => 'partially_checked_in',
        };

        $selections = $registration->talkshowSelections
            ->filter(fn (RegistrationTalkshow $selection) => in_array(
                $selection->status,
                [TalkshowSelectionStatus::Confirmed, TalkshowSelectionStatus::Waitlisted],
                true
            ))
            ->sortBy(fn (RegistrationTalkshow $selection) => $selection->talkshow?->starts_at);

        $missingTalkshow = $selections->first(fn (RegistrationTalkshow $selection) => ! $selection->talkshow);

        if ($missingTalkshow) {
            throw new TicketGenerationException('Data talkshow pada e-ticket tidak lengkap.');
        }

        return [
            'registration_number' => $registration->registration_number,
            'registered_at' => $registration->registered_at->toIso8601String(),
            'participant' => [
                'full_name' => $registration->participant->full_name,
            ],
            'event' => [
                'name' => $registration->event->name,
                'timezone' => $registration->event->timezone,
                'starts_on' => $registration->event->starts_on->toDateString(),
                'ends_on' => $registration->event->ends_on->toDateString(),
                'venue' => $registration->event->venue,
                'address' => $registration->event->address,
            ],
            'talkshows' => [
                'confirmed' => $this->selectionData(
                    $selections->where('status', TalkshowSelectionStatus::Confirmed)
                ),
                'waitlisted' => $this->selectionData(
                    $selections->where('status', TalkshowSelectionStatus::Waitlisted),
                    true
                ),
            ],
            'check_in' => [
                'overall_status' => $overallStatus,
                'event_days' => $registration->event->days->map(function ($eventDay) use ($checkinsByDay) {
                    $checkin = $checkinsByDay->get($eventDay->id);

                    return [
                        'label' => $eventDay->label,
                        'date' => $eventDay->event_date->toDateString(),
                        'status' => $checkin ? 'checked_in' : 'not_checked_in',
                        'checked_in_at' => $checkin?->checked_in_at?->toIso8601String(),
                    ];
                })->values()->all(),
            ],
            'qr_code' => [
                'url' => $qrCode['payload_url'],
                'format' => $qrCode['format'],
                'mime_type' => $qrCode['mime_type'],
                'data_uri' => $qrCode['data_uri'],
            ],
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, RegistrationTalkshow>  $selections
     * @return array<int, array<string, mixed>>
     */
    private function selectionData(Collection $selections, bool $includeWaitlistPosition = false): array
    {
        return $selections->map(function (RegistrationTalkshow $selection) use ($includeWaitlistPosition) {
            $talkshow = $selection->talkshow;
            $data = [
                'code' => $talkshow->code,
                'title' => $talkshow->title,
                'room' => $talkshow->room,
                'starts_at' => $talkshow->starts_at->toIso8601String(),
                'ends_at' => $talkshow->ends_at->toIso8601String(),
                'event_day' => $talkshow->eventDay ? [
                    'label' => $talkshow->eventDay->label,
                    'date' => $talkshow->eventDay->event_date->toDateString(),
                ] : null,
            ];

            if ($includeWaitlistPosition) {
                $data['waitlist_position'] = $this->waitlistPosition($selection);
            }

            return $data;
        })->values()->all();
    }

    private function waitlistPosition(RegistrationTalkshow $selection): int
    {
        return max(1, RegistrationTalkshow::query()
            ->where('talkshow_id', $selection->talkshow_id)
            ->where('status', TalkshowSelectionStatus::Waitlisted->value)
            ->where(function ($query) use ($selection) {
                $query->where('requested_at', '<', $selection->requested_at)
                    ->orWhere(function ($sameTime) use ($selection) {
                        $sameTime->where('requested_at', $selection->requested_at)
                            ->where('id', '<=', $selection->id);
                    });
            })
            ->count());
    }
}
