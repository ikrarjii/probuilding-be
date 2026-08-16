<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('registrations')
            ->select(['id', 'ticket_access_token_hash', 'ticket_access_token_encrypted'])
            ->orderBy('id')
            ->chunk(100, function ($registrations): void {
                foreach ($registrations as $registration) {
                    DB::table('registrations')
                        ->where('id', $registration->id)
                        ->update([
                            'qr_token_hash' => $registration->ticket_access_token_hash,
                            'qr_token_encrypted' => $registration->ticket_access_token_encrypted,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // A canonical identity cannot be safely split into two historical tokens again.
    }
};
