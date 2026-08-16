<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\EmailProvider;
use App\Data\EmailNotification;
use App\Data\ProviderDeliveryResult;
use App\Exceptions\NotificationDeliveryException;
use App\Mail\RegistrationConfirmationMail;
use Illuminate\Support\Facades\Mail;
use Throwable;

class LaravelMailEmailProvider implements EmailProvider
{
    public function send(EmailNotification $notification): ProviderDeliveryResult
    {
        try {
            $sentMessage = Mail::to($notification->confirmation->email)
                ->send(new RegistrationConfirmationMail($notification));

            return new ProviderDeliveryResult(
                provider: 'laravel-mail:'.config('mail.default', 'unknown'),
                messageId: $sentMessage?->getMessageId(),
            );
        } catch (Throwable $exception) {
            throw new NotificationDeliveryException(
                'Provider email tidak dapat mengirim pesan.',
                true,
                $exception,
            );
        }
    }
}
