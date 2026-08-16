<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Exceptions\InvalidETicketTokenException;
use App\Http\Controllers\Controller;
use App\Http\Requests\TalkshowAttendanceRequest;
use App\Models\Event;
use App\Models\Registration;
use App\Models\ScanLog;
use App\Models\Talkshow;
use App\Services\Attendance\RecordTalkshowAttendance;
use App\Services\Tickets\ETicketAccessService;
use App\Services\Tickets\TicketTokenExtractor;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class TalkshowAttendanceController extends Controller
{
    public function __construct(
        private readonly TicketTokenExtractor $tokenExtractor,
        private readonly ETicketAccessService $ticketAccessService,
        private readonly RecordTalkshowAttendance $recordAttendance,
    ) {}

    public function store(
        TalkshowAttendanceRequest $request,
        Event $event,
        Talkshow $talkshow,
    ): JsonResponse {
        Gate::authorize('viewOperations', $event);
        abort_unless($talkshow->event_id === $event->id, 404);

        try {
            $rawToken = $this->tokenExtractor->extract($request->validated('ticket'));
            $registration = $this->ticketAccessService->resolve($rawToken);
        } catch (InvalidETicketTokenException) {
            $this->logScan($request, $event, null, 'invalid_ticket', null, $talkshow);

            return response()->json(['message' => 'Invalid or unknown ticket.'], 404);
        }

        if ($registration->event_id !== $event->id) {
            $this->logScan($request, $event, $registration, 'wrong_event', $rawToken, $talkshow);

            return response()->json(['message' => 'This ticket belongs to a different event.'], 422);
        }

        $attendance = $this->recordAttendance->handle(
            $registration,
            $talkshow,
            $request->user(),
            $request->boolean('override_prerequisite'),
            $request->validated('override_reason'),
        );
        $this->logScan(
            $request,
            $event,
            $registration,
            $attendance->wasRecentlyCreated ? 'accepted' : 'duplicate',
            $rawToken,
            $talkshow,
        );

        return response()->json(['data' => [
            'result' => $attendance->wasRecentlyCreated ? 'attendance_recorded' : 'already_recorded',
            'idempotent' => ! $attendance->wasRecentlyCreated,
            'attendance' => [
                'id' => $attendance->id,
                'registration_id' => $registration->id,
                'registration_number' => $registration->registration_number,
                'talkshow_id' => $talkshow->id,
                'attended_at' => $attendance->attended_at->toIso8601String(),
            ],
        ]], $attendance->wasRecentlyCreated ? 201 : 200);
    }

    private function logScan(
        TalkshowAttendanceRequest $request,
        Event $event,
        ?Registration $registration,
        string $result,
        ?string $rawToken,
        Talkshow $talkshow,
    ): void {
        ScanLog::create([
            'event_id' => $event->id,
            'registration_id' => $registration?->id,
            'scanned_by_user_id' => $request->user()->id,
            'result' => $result,
            'token_hash' => $rawToken ? hash('sha256', $rawToken) : null,
            'metadata' => [
                'operation' => 'talkshow_attendance',
                'event_day_id' => $talkshow->event_day_id,
                'talkshow_id' => $talkshow->id,
            ],
            'scanned_at' => now(),
        ]);
    }
}
