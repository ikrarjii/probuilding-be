<?php

namespace App\Services\Tickets;

use App\Exceptions\InvalidETicketTokenException;
use App\Exceptions\TicketGenerationException;
use App\Models\Registration;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

class ETicketAccessService
{
    /**
     * @return array{raw: string, hash: string, encrypted: string}
     */
    public function issue(): array
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $raw = bin2hex(random_bytes(32));
            $hash = hash('sha256', $raw);

            if (! Registration::query()
                ->where('ticket_access_token_hash', $hash)
                ->orWhere('qr_token_hash', $hash)
                ->exists()) {
                return [
                    'raw' => $raw,
                    'hash' => $hash,
                    'encrypted' => Crypt::encryptString($raw),
                ];
            }
        }

        throw new TicketGenerationException('Identitas akses e-ticket belum dapat dibuat. Silakan coba kembali.');
    }

    public function resolve(string $rawToken): Registration
    {
        if (! preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
            throw new InvalidETicketTokenException;
        }

        $registration = Registration::where(
            'ticket_access_token_hash',
            hash('sha256', $rawToken)
        )->first();

        if (! $registration) {
            throw new InvalidETicketTokenException;
        }

        return $registration;
    }

    public function rawToken(Registration $registration): string
    {
        if (! $registration->ticket_access_token_hash || ! $registration->ticket_access_token_encrypted) {
            throw new TicketGenerationException('Akses e-ticket untuk registrasi ini belum tersedia.');
        }

        try {
            $raw = Crypt::decryptString($registration->ticket_access_token_encrypted);
        } catch (DecryptException $exception) {
            throw new TicketGenerationException('Akses e-ticket tidak dapat dibaca.', $exception);
        }

        if (! hash_equals($registration->ticket_access_token_hash, hash('sha256', $raw))) {
            throw new TicketGenerationException('Identitas akses e-ticket tidak valid.');
        }

        if (! hash_equals((string) $registration->ticket_access_token_hash, $registration->qr_token_hash)) {
            throw new TicketGenerationException('Identitas QR Code dan e-ticket tidak konsisten.');
        }

        try {
            $qrRaw = Crypt::decryptString($registration->qr_token_encrypted);
        } catch (DecryptException $exception) {
            throw new TicketGenerationException('Identitas QR Code tidak dapat dibaca.', $exception);
        }

        if (! hash_equals($registration->qr_token_hash, hash('sha256', $qrRaw))
            || ! hash_equals($raw, $qrRaw)) {
            throw new TicketGenerationException('Identitas QR Code dan e-ticket tidak konsisten.');
        }

        return $raw;
    }
}
