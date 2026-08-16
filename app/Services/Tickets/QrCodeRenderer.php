<?php

namespace App\Services\Tickets;

use App\Exceptions\TicketGenerationException;
use App\Models\Registration;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\SvgWriter;
use Throwable;

class QrCodeRenderer
{
    public function __construct(private readonly ETicketUrlGenerator $ticketUrlGenerator) {}

    public function payload(Registration $registration): string
    {
        return $this->ticketUrlGenerator->forRegistration($registration);
    }

    /**
     * @return array{payload_url: string, format: string, mime_type: string, data_uri: string, svg: string}
     */
    public function render(Registration $registration): array
    {
        try {
            $payloadUrl = $this->payload($registration);
            $qrCode = new QrCode(
                data: $payloadUrl,
                encoding: new Encoding('ISO-8859-1'),
                errorCorrectionLevel: ErrorCorrectionLevel::Medium,
                size: 360,
                margin: 24,
                roundBlockSizeMode: RoundBlockSizeMode::Margin,
            );

            $result = (new SvgWriter)->write($qrCode);

            return [
                'payload_url' => $payloadUrl,
                'format' => 'svg',
                'mime_type' => $result->getMimeType(),
                'data_uri' => $result->getDataUri(),
                'svg' => $result->getString(),
            ];
        } catch (TicketGenerationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new TicketGenerationException('QR Code belum dapat dibuat. Silakan coba kembali.', $exception);
        }
    }
}
