<?php

namespace App\Exceptions;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

class TicketGenerationException extends RuntimeException implements ShouldntReport
{
    public function __construct(
        string $message = 'E-ticket belum dapat dibuat. Silakan coba kembali.',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
