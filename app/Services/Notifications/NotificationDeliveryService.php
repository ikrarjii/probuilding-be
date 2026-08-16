<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\WhatsAppProvider;
use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Exceptions\NotificationDeliveryException;
use App\Models\TicketDelivery;
use Throwable;

class NotificationDeliveryService
{
    public function __construct(
        private readonly WhatsAppProvider $whatsAppProvider,
        private readonly RegistrationNotificationFactory $notificationFactory,
    ) {}

    public function send(TicketDelivery $delivery): void
    {
        if ($delivery->status === DeliveryStatus::Sent) {
            return;
        }

        $delivery->loadMissing('registration');

        if (! $delivery->registration || ! $delivery->idempotency_key) {
            throw new NotificationDeliveryException('Data delivery tidak lengkap.', false);
        }

        $delivery->forceFill([
            'status' => DeliveryStatus::Pending,
            'attempts' => $delivery->attempts + 1,
            'last_attempt_at' => now(),
            'last_error' => null,
        ])->save();

        try {
            $result = $this->whatsAppProvider->send(
                $this->notificationFactory->whatsapp($delivery->registration, $delivery->idempotency_key)
            );

            $delivery->forceFill([
                'provider' => $result->provider,
                'provider_message_id' => $result->messageId,
                'status' => DeliveryStatus::Sent,
                'sent_at' => now(),
                'next_attempt_at' => null,
                'last_error' => null,
            ])->save();
        } catch (Throwable $exception) {
            $deliveryException = $exception instanceof NotificationDeliveryException
                ? $exception
                : new NotificationDeliveryException(
                    'Layanan notifikasi belum dapat memproses pesan.',
                    true,
                    $exception,
                );

            $delivery->forceFill([
                'provider' => $this->configuredProviderName($delivery->channel),
                'status' => DeliveryStatus::Failed,
                'last_error' => mb_substr($deliveryException->getMessage(), 0, 1000),
            ])->save();

            throw $deliveryException;
        }
    }

    private function configuredProviderName(NotificationChannel $channel): string
    {
        $driver = config('notifications.whatsapp.driver', 'disabled');

        return mb_substr((string) $driver, 0, 80);
    }
}
