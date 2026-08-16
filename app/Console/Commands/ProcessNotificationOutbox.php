<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationOutboxProcessor;
use Illuminate\Console\Command;

class ProcessNotificationOutbox extends Command
{
    protected $signature = 'notifications:process {--limit=50 : Maximum number of messages to process}';

    protected $description = 'Process pending registration WhatsApp notifications';

    public function handle(NotificationOutboxProcessor $processor): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 500],
        ]);

        if ($limit === false) {
            $this->error('The --limit option must be between 1 and 500.');

            return self::INVALID;
        }

        $result = $processor->processBatch($limit);

        $this->info(sprintf(
            'Processed: %d, sent: %d, failed: %d.',
            $result['processed'],
            $result['sent'],
            $result['failed'],
        ));

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
