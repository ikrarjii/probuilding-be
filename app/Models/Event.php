<?php

namespace App\Models;

use App\Enums\EventStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'registration_starts_at' => 'datetime',
            'registration_ends_at' => 'datetime',
            'status' => EventStatus::class,
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function days(): HasMany
    {
        return $this->hasMany(EventDay::class)->orderBy('sort_order');
    }

    public function talkshows(): HasMany
    {
        return $this->hasMany(Talkshow::class)->orderBy('starts_at');
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(EventUserAssignment::class);
    }

    public function isRegistrationOpen(): bool
    {
        $now = now();

        return $this->status === EventStatus::Published
            && (! $this->registration_starts_at || $now->greaterThanOrEqualTo($this->registration_starts_at))
            && (! $this->registration_ends_at || $now->lessThanOrEqualTo($this->registration_ends_at));
    }
}
