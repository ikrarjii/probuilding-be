<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TalkshowAttendance extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attended_at' => 'datetime',
            'prerequisite_overridden' => 'boolean',
        ];
    }
}
