<?php

namespace App\Services\Notifications;

use App\Contracts\Notifications\WhatsAppProvider;
use App\Data\ProviderDeliveryResult;
use App\Data\WhatsAppNotification;
use App\Exceptions\NotificationDeliveryException;

class NullWhatsAppProvider implements WhatsAppProvider
{
    public function send(WhatsAppNotification $notification): ProviderDeliveryResult
    {
        throw new NotificationDeliveryException('Provider WhatsApp belum dikonfigurasi.', false);
    }
}
