<?php

namespace App\Services\Tickets;

use App\Exceptions\TicketGenerationException;
use App\Models\Registration;

class ETicketUrlGenerator
{
    public function __construct(private readonly ETicketAccessService $ticketAccessService) {}

    public function forRegistration(Registration $registration): string
    {
        return $this->forToken($this->ticketAccessService->rawToken($registration));
    }

    public function forToken(string $token): string
    {
        $baseUrl = rtrim((string) config('tickets.public_web_url'), '/');
        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);

        if (! preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new TicketGenerationException('Identitas e-ticket tidak valid.');
        }

        if (! filter_var($baseUrl, FILTER_VALIDATE_URL)
            || ! in_array($scheme, ['http', 'https'], true)
            || parse_url($baseUrl, PHP_URL_USER) !== null
            || parse_url($baseUrl, PHP_URL_PASS) !== null
            || parse_url($baseUrl, PHP_URL_QUERY) !== null
            || parse_url($baseUrl, PHP_URL_FRAGMENT) !== null) {
            throw new TicketGenerationException('Alamat publik e-ticket belum dikonfigurasi dengan benar.');
        }

        if (app()->environment('production') && $scheme !== 'https') {
            throw new TicketGenerationException('Alamat publik e-ticket production wajib menggunakan HTTPS.');
        }

        $path = trim((string) config('tickets.public_path', 'ticket'), '/');

        return sprintf('%s/%s/%s', $baseUrl, $path, rawurlencode($token));
    }
}
