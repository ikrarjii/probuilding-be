<?php

namespace App\Services\Tickets;

use App\Exceptions\TicketGenerationException;
use App\Models\Registration;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Log;
use Throwable;

class ETicketPdfService
{
    public function __construct(
        private readonly ETicketDataService $ticketDataService,
    ) {}

    public function render(Registration $registration): string
    {
        try {
            $ticket = $this->ticketDataService->build($registration);
            $html = view('tickets.e-ticket', compact('ticket'))->render();

            $options = new Options;
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('isRemoteEnabled', false);
            $options->set('isPhpEnabled', false);

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $pdf = $dompdf->output();

            if (! str_starts_with($pdf, '%PDF-')) {
                throw new TicketGenerationException('Dokumen PDF e-ticket tidak valid.');
            }

            return $pdf;
        } catch (TicketGenerationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            Log::error('E-ticket PDF rendering failed.', [
                'registration_id' => $registration->id,
                'exception_class' => $exception::class,
            ]);

            throw new TicketGenerationException('PDF e-ticket belum dapat dibuat. Silakan coba kembali.', $exception);
        }
    }
}
