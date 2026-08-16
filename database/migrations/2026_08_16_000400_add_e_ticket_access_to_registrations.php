<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->char('ticket_access_token_hash', 64)
                ->nullable()
                ->unique('registrations_ticket_access_token_unique')
                ->after('qr_token_encrypted');
            $table->longText('ticket_access_token_encrypted')
                ->nullable()
                ->after('ticket_access_token_hash');
        });

        DB::table('registrations')
            ->whereNull('ticket_access_token_hash')
            ->chunkById(100, function ($registrations): void {
                foreach ($registrations as $registration) {
                    DB::table('registrations')
                        ->where('id', $registration->id)
                        ->update([
                            'ticket_access_token_hash' => $registration->qr_token_hash,
                            'ticket_access_token_encrypted' => $registration->qr_token_encrypted,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('registrations', function (Blueprint $table) {
            $table->dropUnique('registrations_ticket_access_token_unique');
            $table->dropColumn([
                'ticket_access_token_hash',
                'ticket_access_token_encrypted',
            ]);
        });
    }
};
