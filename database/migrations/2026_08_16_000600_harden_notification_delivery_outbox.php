<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_deliveries', function (Blueprint $table) {
            $table->string('notification_type', 48)
                ->default('registration_confirmation')
                ->after('channel');
            $table->char('idempotency_key', 64)->nullable()->after('notification_type');
            $table->timestampTz('last_attempt_at')->nullable()->after('attempts');
            $table->timestampTz('next_attempt_at')->nullable()->after('last_attempt_at');
        });

        DB::table('ticket_deliveries')->orderBy('id')->chunk(100, function ($deliveries): void {
            foreach ($deliveries as $delivery) {
                DB::table('ticket_deliveries')
                    ->where('id', $delivery->id)
                    ->update([
                        'idempotency_key' => hash(
                            'sha256',
                            "registration_confirmation|{$delivery->channel}|{$delivery->registration_id}"
                        ),
                    ]);
            }
        });

        Schema::table('ticket_deliveries', function (Blueprint $table) {
            $table->unique('idempotency_key', 'ticket_deliveries_idempotency_unique');
            $table->unique(
                ['registration_id', 'channel', 'notification_type'],
                'ticket_deliveries_registration_channel_type_unique'
            );
            $table->index(['status', 'next_attempt_at'], 'ticket_deliveries_retry_lookup');
        });

        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->char('deduplication_key', 64)->nullable()->after('aggregate_id');
            $table->timestampTz('reserved_at')->nullable()->after('processed_at');
            $table->uuid('reservation_token')->nullable()->after('reserved_at');
            $table->unique('deduplication_key', 'outbox_messages_deduplication_unique');
            $table->index(
                ['event_type', 'processed_at', 'available_at'],
                'outbox_messages_processing_lookup'
            );
        });
    }

    public function down(): void
    {
        Schema::table('outbox_messages', function (Blueprint $table) {
            $table->dropIndex('outbox_messages_processing_lookup');
            $table->dropUnique('outbox_messages_deduplication_unique');
            $table->dropColumn(['deduplication_key', 'reserved_at', 'reservation_token']);
        });

        Schema::table('ticket_deliveries', function (Blueprint $table) {
            $table->dropIndex('ticket_deliveries_retry_lookup');
            $table->dropUnique('ticket_deliveries_registration_channel_type_unique');
            $table->dropUnique('ticket_deliveries_idempotency_unique');
            $table->dropColumn([
                'notification_type',
                'idempotency_key',
                'last_attempt_at',
                'next_attempt_at',
            ]);
        });
    }
};
