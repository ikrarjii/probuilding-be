<?php

namespace App\Services\Notifications;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use App\Models\OutboxMessage;
use App\Models\Registration;
use App\Models\TicketDelivery;

class CreateRegistrationNotifications
{
    /**
     * @return array<int, TicketDelivery>
     */
    public function handle(Registration $registration): array
    {
        return array_map(
            fn (NotificationChannel $channel) => $this->create($registration, $channel),
            NotificationChannel::cases(),
        );
    }

    private function create(Registration $registration, NotificationChannel $channel): TicketDelivery
    {
        $type = NotificationType::RegistrationConfirmation;
        $idempotencyKey = hash('sha256', "{$type->value}|{$channel->value}|{$registration->id}");

        $delivery = TicketDelivery::firstOrCreate(
            [
                'registration_id' => $registration->id,
                'channel' => $channel->value,
                'notification_type' => $type->value,
            ],
            [
                'idempotency_key' => $idempotencyKey,
                'status' => DeliveryStatus::Pending->value,
                'next_attempt_at' => now(),
            ],
        );

        OutboxMessage::firstOrCreate(
            ['deduplication_key' => hash('sha256', "notification.delivery_requested|{$delivery->id}")],
            [
                'event_type' => 'notification.delivery_requested',
                'aggregate_id' => $delivery->id,
                'payload' => ['ticket_delivery_id' => $delivery->id],
                'available_at' => now(),
            ],
        );

        return $delivery;
    }
}
