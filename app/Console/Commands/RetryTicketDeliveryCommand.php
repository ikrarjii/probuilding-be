<?php

namespace App\Console\Commands;

use App\Models\TicketDelivery;
use App\Services\Notifications\RetryTicketDelivery;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class RetryTicketDeliveryCommand extends Command
{
    protected $signature = 'notifications:retry {delivery : Ticket delivery UUID}';

    protected $description = 'Make one failed notification delivery available for retry';

    public function handle(RetryTicketDelivery $retryTicketDelivery): int
    {
        $delivery = TicketDelivery::find($this->argument('delivery'));

        if (! $delivery) {
            $this->error('Delivery not found.');

            return self::FAILURE;
        }

        try {
            $retryTicketDelivery->handle($delivery);
        } catch (ValidationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Delivery is ready to be retried by notifications:process.');

        return self::SUCCESS;
    }
}
