<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Tickets\ETicketAccessService;
use App\Services\Tickets\ETicketDataService;
use App\Services\Tickets\ETicketPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PublicETicketController extends Controller
{
    public function show(
        string $ticketToken,
        ETicketAccessService $ticketAccessService,
        ETicketDataService $ticketDataService,
    ): JsonResponse {
        $registration = $ticketAccessService->resolve($ticketToken);

        return response()->json([
            'data' => $ticketDataService->build($registration),
        ])->withHeaders($this->privateHeaders());
    }

    public function download(
        string $ticketToken,
        ETicketAccessService $ticketAccessService,
        ETicketPdfService $ticketPdfService,
    ): Response {
        $registration = $ticketAccessService->resolve($ticketToken);
        $filename = sprintf('e-ticket-%s.pdf', $registration->registration_number);

        return response($ticketPdfService->render($registration), 200, [
            ...$this->privateHeaders(),
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ];
    }
}
