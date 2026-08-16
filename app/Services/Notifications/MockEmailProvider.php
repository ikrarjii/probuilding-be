<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\EmailProvider;
use App\Data\EmailNotification;
use App\Data\ProviderDeliveryResult;
use App\Exceptions\NotificationDeliveryException;

class MockEmailProvider implements EmailProvider
{
    public function send(EmailNotification $notification): ProviderDeliveryResult
    {
        if (config('notifications.email.mock_failure')) {
            throw new NotificationDeliveryException('Mock email provider failure.');
        }

        return new ProviderDeliveryResult(
            provider: 'mock-email',
            messageId: 'mock-email-'.substr(hash('sha256', $notification->idempotencyKey), 0, 24),
        );
    }
}
