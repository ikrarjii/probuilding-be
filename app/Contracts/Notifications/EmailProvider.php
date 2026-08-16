<?php

namespace App\Contracts\Notifications;

use App\Data\EmailNotification;
use App\Data\ProviderDeliveryResult;

interface EmailProvider
{
    public function send(EmailNotification $notification): ProviderDeliveryResult;
}
