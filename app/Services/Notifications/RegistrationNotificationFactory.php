<?php

namespace App\Services\Notifications;

use App\Data\EmailNotification;
use App\Data\RegistrationConfirmation;
use App\Data\WhatsAppNotification;
use App\Models\Registration;
use App\Services\Tickets\ETicketDataService;
use App\Services\Tickets\ETicketPdfService;

class RegistrationNotificationFactory
{
    public function __construct(
        private readonly ETicketDataService $ticketDataService,
        private readonly ETicketPdfService $ticketPdfService,
    ) {}

    public function confirmation(Registration $registration): RegistrationConfirmation
    {
        $registration->loadMissing(['participant', 'event']);
        $ticket = $this->ticketDataService->build($registration);

        return new RegistrationConfirmation(
            registrationId: $registration->id,
            participantName: $ticket['participant']['full_name'],
            email: $registration->email,
            whatsapp: $registration->whatsapp_e164,
            eventName: $ticket['event']['name'],
            registrationNumber: $ticket['registration_number'],
            eventStartsOn: $ticket['event']['starts_on'],
            eventEndsOn: $ticket['event']['ends_on'],
            eventTimezone: $ticket['event']['timezone'],
            eventVenue: $ticket['event']['venue'],
            eventAddress: $ticket['event']['address'],
            confirmedTalkshows: $ticket['talkshows']['confirmed'],
            waitlistedTalkshows: $ticket['talkshows']['waitlisted'],
            ticketUrl: $ticket['qr_code']['url'],
            checkInInstructions: 'Tunjukkan QR Code pada e-ticket kepada panitia di meja check-in. Panitia akan memeriksa data sebelum menerima check-in.',
        );
    }

    public function email(Registration $registration, string $idempotencyKey): EmailNotification
    {
        $confirmation = $this->confirmation($registration);
        $attachPdf = (bool) config('notifications.email.attach_pdf', true);

        return new EmailNotification(
            confirmation: $confirmation,
            subject: "Konfirmasi Registrasi {$confirmation->eventName} — {$confirmation->registrationNumber}",
            idempotencyKey: $idempotencyKey,
            pdfContent: $attachPdf ? $this->ticketPdfService->render($registration) : null,
            pdfFilename: $attachPdf ? "e-ticket-{$confirmation->registrationNumber}.pdf" : null,
        );
    }

    public function whatsapp(Registration $registration, string $idempotencyKey): WhatsAppNotification
    {
        $confirmation = $this->confirmation($registration);

        return new WhatsAppNotification(
            confirmation: $confirmation,
            body: $this->whatsAppBody($confirmation),
            idempotencyKey: $idempotencyKey,
        );
    }

    private function whatsAppBody(RegistrationConfirmation $confirmation): string
    {
        $lines = [
            "Halo {$confirmation->participantName},",
            '',
            "Registrasi Anda untuk {$confirmation->eventName} telah berhasil.",
            '',
            'Nomor Registrasi:',
            $confirmation->registrationNumber,
        ];

        $lines = $this->appendTalkshows(
            $lines,
            'Talkshow terkonfirmasi:',
            $confirmation->confirmedTalkshows
        );
        $lines = $this->appendTalkshows(
            $lines,
            'Talkshow dalam waitlist:',
            $confirmation->waitlistedTalkshows
        );

        return implode("\n", [
            ...$lines,
            '',
            'E-Ticket:',
            $confirmation->ticketUrl,
            '',
            $confirmation->checkInInstructions,
        ]);
    }

    /**
     * @param  array<int, string>  $lines
     * @param  array<int, array<string, mixed>>  $talkshows
     * @return array<int, string>
     */
    private function appendTalkshows(array $lines, string $heading, array $talkshows): array
    {
        if ($talkshows === []) {
            return $lines;
        }

        $lines[] = '';
        $lines[] = $heading;

        foreach ($talkshows as $talkshow) {
            $lines[] = "• {$talkshow['title']}";
        }

        return $lines;
    }
}
