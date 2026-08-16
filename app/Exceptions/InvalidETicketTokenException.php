<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class InvalidETicketTokenException extends RuntimeException implements ShouldntReport {}
