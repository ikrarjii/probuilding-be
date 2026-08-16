<?php

namespace App\Services\Notifications;

use App\Data\RegistrationConfirmation;
use App\Data\WhatsAppNotification;
use App\Models\Registration;
use App\Services\Tickets\ETicketDataService;

class RegistrationNotificationFactory
{
    public function __construct(private readonly ETicketDataService $ticketDataService) {}

    public function confirmation(Registration $registration): RegistrationConfirmation
    {
        $registration->loadMissing(['participant', 'event']);
        $ticket = $this->ticketDataService->build($registration);

        return new RegistrationConfirmation(
            registrationId: $registration->id,
            participantName: $ticket['participant']['full_name'],
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
            "Registrasi Berhasil! \u{1F389}",
            '',
            "Halo, {$confirmation->participantName}.",
            '',
            "Anda berhasil terdaftar di {$confirmation->eventName}.",
            '',
            'Nomor Registrasi:',
            $confirmation->registrationNumber,
            '',
            'Silakan simpan nomor registrasi Anda dan tunjukkan QR Code/e-ticket saat melakukan check-in di lokasi.',
            '',
            "\u{1F39F}\u{FE0F} Lihat E-Ticket & QR Code:",
            $confirmation->ticketUrl,
            '',
            'Silakan cek e-ticket Anda melalui website kami.',
            '',
            'Terima kasih.',
        ];

        return implode("\n", $lines);
    }
}
