<?php

namespace App\Http\Controllers\Api\V1\Staff;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Models\Registration;
use App\Services\Audit\AuditLogger;
use App\Services\Tickets\ETicketPdfService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class RegistrationTicketController extends Controller
{
    public function download(
        Request $request,
        Event $event,
        Registration $registration,
        ETicketPdfService $ticketPdfService,
        AuditLogger $auditLogger,
    ): Response {
        Gate::authorize('viewOperations', $event);
        abort_unless($registration->event_id === $event->id, 404);

        $pdf = $ticketPdfService->render($registration);
        $auditLogger->record(
            'registration.ticket_downloaded',
            $request->user(),
            $registration,
            $event->id,
        );

        $filename = sprintf('e-ticket-%s.pdf', $registration->registration_number);

        return response($pdf, 200, [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }
}
