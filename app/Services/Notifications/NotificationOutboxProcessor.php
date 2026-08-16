<?php

namespace App\Services\Notifications;

use App\Exceptions\NotificationDeliveryException;
use App\Models\OutboxMessage;
use App\Models\TicketDelivery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class NotificationOutboxProcessor
{
    public function __construct(private readonly NotificationDeliveryService $deliveryService) {}

    /**
     * @return array{processed: int, sent: int, failed: int}
     */
    public function processBatch(int $limit = 50): array
    {
        $result = ['processed' => 0, 'sent' => 0, 'failed' => 0];

        for ($index = 0; $index < max(1, $limit); $index++) {
            $outbox = $this->claimNext();

            if (! $outbox) {
                break;
            }

            $result['processed']++;

            try {
                $delivery = TicketDelivery::find($outbox->payload['ticket_delivery_id'] ?? null);

                if (! $delivery) {
                    throw new NotificationDeliveryException('Data delivery tidak ditemukan.', false);
                }

                $this->deliveryService->send($delivery);
                $this->markProcessed($outbox);
                $result['sent']++;
            } catch (Throwable $exception) {
                $this->markFailed($outbox, $exception);
                $result['failed']++;
            }
        }

        return $result;
    }

    private function claimNext(): ?OutboxMessage
    {
        return DB::transaction(function () {
            $claimTimeout = max(1, (int) config('notifications.outbox.claim_timeout_minutes', 10));
            $maxAttempts = max(1, (int) config('notifications.outbox.max_attempts', 5));

            $outbox = OutboxMessage::query()
                ->where('event_type', 'notification.delivery_requested')
                ->whereNull('processed_at')
                ->where('available_at', '<=', now())
                ->where('attempts', '<', $maxAttempts)
                ->where(function ($query) use ($claimTimeout) {
                    $query->whereNull('reserved_at')
                        ->orWhere('reserved_at', '<=', now()->subMinutes($claimTimeout));
                })
                ->orderBy('available_at')
                ->orderBy('created_at')
                ->lockForUpdate()
                ->first();

            if (! $outbox) {
                return null;
            }

            $outbox->forceFill([
                'attempts' => $outbox->attempts + 1,
                'reserved_at' => now(),
                'reservation_token' => (string) Str::uuid(),
            ])->save();

            return $outbox->fresh();
        }, 3);
    }

    private function markProcessed(OutboxMessage $outbox): void
    {
        $outbox->forceFill([
            'processed_at' => now(),
            'reserved_at' => null,
            'reservation_token' => null,
            'last_error' => null,
        ])->save();
    }

    private function markFailed(OutboxMessage $outbox, Throwable $exception): void
    {
        $maxAttempts = max(1, (int) config('notifications.outbox.max_attempts', 5));
        $retryable = ! $exception instanceof NotificationDeliveryException || $exception->retryable;
        $exhausted = $outbox->attempts >= $maxAttempts;
        $safeError = $exception instanceof NotificationDeliveryException
            ? $exception->getMessage()
            : 'Pemrosesan notifikasi mengalami kegagalan internal.';
        $delay = min(
            1440,
            max(1, (int) config('notifications.outbox.retry_base_minutes', 5)) * (2 ** max(0, $outbox->attempts - 1))
        );

        $outbox->forceFill([
            'processed_at' => (! $retryable || $exhausted) ? now() : null,
            'available_at' => now()->addMinutes($delay),
            'reserved_at' => null,
            'reservation_token' => null,
            'last_error' => mb_substr($safeError, 0, 1000),
        ])->save();

        if (($deliveryId = $outbox->payload['ticket_delivery_id'] ?? null) !== null) {
            TicketDelivery::whereKey($deliveryId)->update([
                'next_attempt_at' => (! $retryable || $exhausted) ? null : $outbox->available_at,
            ]);
        }
    }
}
