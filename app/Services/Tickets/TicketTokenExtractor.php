<?php

namespace App\Services\Tickets;

use App\Exceptions\InvalidETicketTokenException;

class TicketTokenExtractor
{
    public function extract(string $ticket): string
    {
        $ticket = trim($ticket);

        if (preg_match('/^[a-f0-9]{64}$/', $ticket)) {
            return $ticket;
        }

        $configuredBase = parse_url((string) config('tickets.public_web_url'));
        $candidate = parse_url($ticket);

        if (! is_array($configuredBase) || ! is_array($candidate)) {
            throw new InvalidETicketTokenException;
        }

        if (($candidate['scheme'] ?? null) !== ($configuredBase['scheme'] ?? null)
            || strcasecmp((string) ($candidate['host'] ?? ''), (string) ($configuredBase['host'] ?? '')) !== 0
            || (int) ($candidate['port'] ?? 0) !== (int) ($configuredBase['port'] ?? 0)
            || isset($candidate['user'])
            || isset($candidate['pass'])
            || isset($candidate['query'])
            || isset($candidate['fragment'])) {
            throw new InvalidETicketTokenException;
        }

        $publicPath = preg_quote(trim((string) config('tickets.public_path', 'ticket'), '/'), '/');
        $path = trim((string) ($candidate['path'] ?? ''), '/');

        if (! preg_match("/^{$publicPath}\/([a-f0-9]{64})$/", $path, $matches)) {
            throw new InvalidETicketTokenException;
        }

        return $matches[1];
    }
}
