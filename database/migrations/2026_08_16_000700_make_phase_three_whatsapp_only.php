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
            $table->string('recipient_reference', 32)->nullable()->after('channel');
            $table->index(
                ['channel', 'recipient_reference'],
                'ticket_deliveries_channel_recipient_lookup'
            );
        });

        DB::table('ticket_deliveries')
            ->where('channel', 'email')
            ->select('id')
            ->chunkById(100, function ($deliveries): void {
                $deliveryIds = $deliveries->pluck('id');

                DB::table('outbox_messages')->whereIn('aggregate_id', $deliveryIds)->delete();
                DB::table('ticket_deliveries')->whereIn('id', $deliveryIds)->delete();
            });

        DB::table('ticket_deliveries')
            ->where('channel', 'whatsapp')
            ->select(['id', 'registration_id'])
            ->chunkById(100, function ($deliveries): void {
                foreach ($deliveries as $delivery) {
                    $recipient = DB::table('registrations')
                        ->where('id', $delivery->registration_id)
                        ->value('whatsapp_e164');

                    DB::table('ticket_deliveries')
                        ->where('id', $delivery->id)
                        ->update(['recipient_reference' => $recipient]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('ticket_deliveries', function (Blueprint $table) {
            $table->dropIndex('ticket_deliveries_channel_recipient_lookup');
            $table->dropColumn('recipient_reference');
        });
    }
};
