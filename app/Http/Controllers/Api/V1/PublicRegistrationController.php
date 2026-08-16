<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\PublicRegistrationRequest;
use App\Models\Event;
use App\Services\Registrations\RegisterParticipant;
use App\Services\Tickets\ETicketAccessService;
use App\Services\Tickets\ETicketUrlGenerator;
use Illuminate\Http\JsonResponse;

class PublicRegistrationController extends Controller
{
    public function store(
        PublicRegistrationRequest $request,
        Event $event,
        RegisterParticipant $registerParticipant,
        ETicketAccessService $ticketAccessService,
        ETicketUrlGenerator $ticketUrlGenerator,
    ): JsonResponse {
        $validated = $request->validated();
        $idempotencyKey = $validated['idempotency_key'];
        unset($validated['idempotency_key']);

        $outcome = $registerParticipant->handle($event, $validated, $idempotencyKey);
        $registration = $outcome->registration;

        return response()->json([
            'data' => [
                'registration' => [
                    'id' => $registration->id,
                    'registration_number' => $registration->registration_number,
                    'registered_at' => $registration->registered_at->toIso8601String(),
                    'participant' => [
                        'full_name' => $registration->participant->full_name,
                        'email' => $registration->email,
                        'whatsapp' => $registration->whatsapp_e164,
                    ],
                    'event' => [
                        'id' => $registration->event->id,
                        'name' => $registration->event->name,
                        'slug' => $registration->event->slug,
                        'starts_on' => $registration->event->starts_on->toDateString(),
                        'ends_on' => $registration->event->ends_on->toDateString(),
                        'venue' => $registration->event->venue,
                    ],
                    'talkshows' => $outcome->talkshows,
                    'e_ticket' => [
                        'access_token' => $ticketAccessService->rawToken($registration),
                        'url' => $ticketUrlGenerator->forRegistration($registration),
                    ],
                ],
                'idempotent_replay' => $outcome->idempotentReplay,
            ],
        ], $outcome->idempotentReplay ? 200 : 201);
    }
}
