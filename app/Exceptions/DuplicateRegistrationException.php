<?php

namespace App\Exceptions;

use RuntimeException;

class DuplicateRegistrationException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This WhatsApp number is already registered for the event.');
    }
}
