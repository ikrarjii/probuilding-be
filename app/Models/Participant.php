<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Participant extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = [
        'whatsapp_e164',
        'email',
        'address',
    ];

    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }
}
