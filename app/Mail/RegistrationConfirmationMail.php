<?php

namespace App\Mail;

use App\Data\EmailNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Symfony\Component\Mime\Email;

class RegistrationConfirmationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly EmailNotification $notification) {}

    public function build(): static
    {
        $mail = $this
            ->subject($this->notification->subject)
            ->view('emails.registration-confirmation-html', [
                'confirmation' => $this->notification->confirmation,
            ])
            ->text('emails.registration-confirmation-text', [
                'confirmation' => $this->notification->confirmation,
            ]);

        if ($fromAddress = config('notifications.email.from_address')) {
            $mail->from($fromAddress, config('notifications.email.from_name'));
        }

        $mail->withSymfonyMessage(function (Email $message): void {
            $message->getHeaders()->addTextHeader(
                'X-ProBuild-Idempotency-Key',
                $this->notification->idempotencyKey,
            );
        });

        if ($this->notification->pdfContent && $this->notification->pdfFilename) {
            $mail->attachData(
                $this->notification->pdfContent,
                $this->notification->pdfFilename,
                ['mime' => 'application/pdf'],
            );
        }

        return $mail;
    }
}
