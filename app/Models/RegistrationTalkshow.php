<?php

namespace App\Models;

use App\Enums\TalkshowSelectionStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RegistrationTalkshow extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status' => TalkshowSelectionStatus::class,
            'requested_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'waitlisted_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'promoted_at' => 'datetime',
        ];
    }

    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    public function talkshow(): BelongsTo
    {
        return $this->belongsTo(Talkshow::class);
    }
}
