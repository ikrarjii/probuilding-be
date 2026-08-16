<?php

namespace App\Models;

use App\Enums\TalkshowSelectionStatus;
use App\Enums\TalkshowStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Talkshow extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'registration_starts_at' => 'datetime',
            'registration_ends_at' => 'datetime',
            'capacity' => 'integer',
            'waitlist_enabled' => 'boolean',
            'status' => TalkshowStatus::class,
        ];
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function eventDay(): BelongsTo
    {
        return $this->belongsTo(EventDay::class);
    }

    public function selections(): HasMany
    {
        return $this->hasMany(RegistrationTalkshow::class);
    }

    public function confirmedSelections(): HasMany
    {
        return $this->selections()->where('status', TalkshowSelectionStatus::Confirmed->value);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(TalkshowAttendance::class);
    }

    public function isRegistrationOpen(): bool
    {
        $now = now();

        return $this->status === TalkshowStatus::Published
            && (! $this->registration_starts_at || $now->greaterThanOrEqualTo($this->registration_starts_at))
            && (! $this->registration_ends_at || $now->lessThanOrEqualTo($this->registration_ends_at));
    }
}
