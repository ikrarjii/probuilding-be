<?php

namespace App\Enums;

enum TalkshowStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}
