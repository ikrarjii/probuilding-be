<?php

namespace App\Services\Notifications;

use App\Enums\DeliveryStatus;
use App\Models\OutboxMessage;
use App\Models\TicketDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RetryTicketDelivery
{
    public function handle(TicketDelivery $delivery): TicketDelivery
    {
        return DB::transaction(function () use ($delivery) {
            $delivery = TicketDelivery::query()->lockForUpdate()->findOrFail($delivery->id);

            if ($delivery->status === DeliveryStatus::Sent) {
                throw ValidationException::withMessages([
                    'delivery' => ['Notifikasi yang sudah terkirim tidak dapat dikirim ulang sebagai retry.'],
                ]);
            }

            $delivery->forceFill([
                'status' => DeliveryStatus::Pending,
                'next_attempt_at' => now(),
                'last_error' => null,
            ])->save();

            $outbox = OutboxMessage::firstOrCreate(
                ['deduplication_key' => hash('sha256', "notification.delivery_requested|{$delivery->id}")],
                [
                    'event_type' => 'notification.delivery_requested',
                    'aggregate_id' => $delivery->id,
                    'payload' => ['ticket_delivery_id' => $delivery->id],
                    'available_at' => now(),
                ],
            );

            $outbox->forceFill([
                'processed_at' => null,
                'reserved_at' => null,
                'reservation_token' => null,
                'attempts' => 0,
                'available_at' => now(),
                'last_error' => null,
            ])->save();

            return $delivery->fresh();
        }, 3);
    }
}
