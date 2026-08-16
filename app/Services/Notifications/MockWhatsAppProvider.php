<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\WhatsAppProvider;
use App\Data\ProviderDeliveryResult;
use App\Data\WhatsAppNotification;
use App\Exceptions\NotificationDeliveryException;
use Illuminate\Support\Facades\Log;

class MockWhatsAppProvider implements WhatsAppProvider
{
    public function send(WhatsAppNotification $notification): ProviderDeliveryResult
    {
        if (config('notifications.whatsapp.mock_failure')) {
            throw new NotificationDeliveryException('Mock WhatsApp provider failure.');
        }

        Log::channel(config('notifications.whatsapp.mock_log_channel', 'whatsapp_mock'))->info(
            'Mock WhatsApp registration confirmation.',
            [
                'recipient' => $notification->confirmation->whatsapp,
                'participant_name' => $notification->confirmation->participantName,
                'registration_number' => $notification->confirmation->registrationNumber,
                'ticket_url' => $notification->confirmation->ticketUrl,
                'message' => $notification->body,
            ],
        );

        return new ProviderDeliveryResult(
            provider: 'mock-whatsapp',
            messageId: 'mock-whatsapp-'.substr(hash('sha256', $notification->idempotencyKey), 0, 24),
        );
    }
}
