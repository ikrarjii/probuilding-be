<?php

namespace App\Enums;

enum TalkshowSelectionStatus: string
{
    case Confirmed = 'confirmed';
    case Waitlisted = 'waitlisted';
    case Unavailable = 'unavailable';
    case Cancelled = 'cancelled';
}
