<?php

namespace App\Models;

use App\Enums\RegistrationSource;
use App\Enums\RegistrationStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Registration extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = [
        'qr_token_hash',
        'qr_token_encrypted',
        'ticket_access_token_hash',
        'ticket_access_token_encrypted',
        'idempotency_key',
        'request_fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'registered_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'registration_source' => RegistrationSource::class,
            'status' => RegistrationStatus::class,
            'talkshow_selection_result' => 'array',
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function talkshowSelections(): HasMany
    {
        return $this->hasMany(RegistrationTalkshow::class);
    }

    public function dailyCheckins(): HasMany
    {
        return $this->hasMany(DailyEventCheckin::class);
    }

    public function ticketDeliveries(): HasMany
    {
        return $this->hasMany(TicketDelivery::class);
    }

    public function talkshowAttendances(): HasMany
    {
        return $this->hasMany(TalkshowAttendance::class);
    }
}
