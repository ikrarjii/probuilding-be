<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\WhatsAppProvider;
use App\Data\ProviderDeliveryResult;
use App\Data\WhatsAppNotification;
use App\Exceptions\NotificationDeliveryException;

class MockWhatsAppProvider implements WhatsAppProvider
{
    public function send(WhatsAppNotification $notification): ProviderDeliveryResult
    {
        if (config('notifications.whatsapp.mock_failure')) {
            throw new NotificationDeliveryException('Mock WhatsApp provider failure.');
        }

        return new ProviderDeliveryResult(
            provider: 'mock-whatsapp',
            messageId: 'mock-whatsapp-'.substr(hash('sha256', $notification->idempotencyKey), 0, 24),
        );
    }
}
