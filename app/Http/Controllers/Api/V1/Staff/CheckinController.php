<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Exceptions\InvalidETicketTokenException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StaffTicketRequest;
use App\Models\DailyEventCheckin;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\Registration;
use App\Models\ScanLog;
use App\Services\Attendance\RecordDailyEventCheckin;
use App\Services\Tickets\ETicketAccessService;
use App\Services\Tickets\TicketTokenExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CheckinController extends Controller
{
    public function __construct(
        private readonly TicketTokenExtractor $tokenExtractor,
        private readonly ETicketAccessService $ticketAccessService,
        private readonly RecordDailyEventCheckin $recordCheckin,
    ) {}

    public function store(
        StaffTicketRequest $request,
        Event $event,
        EventDay $eventDay,
    ): JsonResponse {
        Gate::authorize('viewOperations', $event);
        abort_unless($eventDay->event_id === $event->id, 404);

        try {
            $rawToken = $this->tokenExtractor->extract($request->validated('ticket'));
            $registration = $this->ticketAccessService->resolve($rawToken);
        } catch (InvalidETicketTokenException) {
            $this->logScan($request, $event, null, 'invalid_ticket', null, [
                'operation' => 'event_day_checkin',
                'event_day_id' => $eventDay->id,
            ]);

            return response()->json(['message' => 'Invalid or unknown ticket.'], 404);
        }

        if ($registration->event_id !== $event->id) {
            $this->logScan($request, $event, $registration, 'wrong_event', $rawToken, [
                'operation' => 'event_day_checkin',
                'event_day_id' => $eventDay->id,
                'ticket_event_id' => $registration->event_id,
            ]);

            return response()->json(['message' => 'This ticket belongs to a different event.'], 422);
        }

        $checkin = $this->recordCheckin->handle($registration, $eventDay, $request->user());
        $result = $checkin->wasRecentlyCreated ? 'accepted' : 'duplicate';
        $this->logScan($request, $event, $registration, $result, $rawToken, [
            'operation' => 'event_day_checkin',
            'event_day_id' => $eventDay->id,
        ]);

        return $this->checkinResponse($checkin, $registration);
    }

    public function storeForRegistration(
        Request $request,
        Event $event,
        EventDay $eventDay,
        Registration $registration,
    ): JsonResponse {
        Gate::authorize('viewOperations', $event);
        abort_unless($eventDay->event_id === $event->id, 404);
        abort_unless($registration->event_id === $event->id, 404);

        $checkin = $this->recordCheckin->handle($registration, $eventDay, $request->user());

        return $this->checkinResponse($checkin, $registration);
    }

    private function checkinResponse(
        DailyEventCheckin $checkin,
        Registration $registration,
    ): JsonResponse {
        return response()->json(['data' => [
            'result' => $checkin->wasRecentlyCreated ? 'checked_in' : 'already_checked_in',
            'idempotent' => ! $checkin->wasRecentlyCreated,
            'checkin' => [
                'id' => $checkin->id,
                'registration_id' => $registration->id,
                'registration_number' => $registration->registration_number,
                'event_day_id' => $checkin->event_day_id,
                'checked_in_at' => $checkin->checked_in_at->toIso8601String(),
            ],
        ]], $checkin->wasRecentlyCreated ? 201 : 200);
    }

    /** @param array<string, mixed> $metadata */
    private function logScan(
        StaffTicketRequest $request,
        Event $event,
        ?Registration $registration,
        string $result,
        ?string $rawToken,
        array $metadata,
    ): void {
        ScanLog::create([
            'event_id' => $event->id,
            'registration_id' => $registration?->id,
            'scanned_by_user_id' => $request->user()->id,
            'result' => $result,
            'token_hash' => $rawToken ? hash('sha256', $rawToken) : null,
            'metadata' => $metadata,
            'scanned_at' => now(),
        ]);
    }
}
