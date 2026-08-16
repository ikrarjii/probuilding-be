<?php

namespace App\Models;

use App\Enums\DeliveryStatus;
use App\Enums\NotificationChannel;
use App\Enums\NotificationType;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketDelivery extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = [
        'recipient_reference',
        'idempotency_key',
        'provider_message_id',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'notification_type' => NotificationType::class,
            'status' => DeliveryStatus::class,
            'last_attempt_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }
}
