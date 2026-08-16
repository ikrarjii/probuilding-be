<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DailyEventCheckin extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['checked_in_at' => 'datetime'];
    }
}
