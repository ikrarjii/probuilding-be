<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EventRegistrationSequence extends Model
{
    protected $guarded = [];

    protected $primaryKey = 'event_id';

    public $incrementing = false;

    protected $keyType = 'string';
}
