<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\EmailProvider;
use App\Data\EmailNotification;
use App\Data\ProviderDeliveryResult;
use App\Exceptions\NotificationDeliveryException;

class NullEmailProvider implements EmailProvider
{
    public function send(EmailNotification $notification): ProviderDeliveryResult
    {
        throw new NotificationDeliveryException('Provider email belum dikonfigurasi.', false);
    }
}
