<?php

namespace App\Enums;

enum RegistrationSource: string
{
    case Public = 'public';
    case PanitiaWalkIn = 'panitia_walk_in';
    case Admin = 'admin';
}
