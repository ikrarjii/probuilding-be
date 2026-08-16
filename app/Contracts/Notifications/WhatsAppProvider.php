<?php

namespace App\Contracts\Notifications;

use App\Data\ProviderDeliveryResult;
use App\Data\WhatsAppNotification;

interface WhatsAppProvider
{
    public function send(WhatsAppNotification $notification): ProviderDeliveryResult;
}
