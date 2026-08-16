<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_event_checkins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('event_day_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('checked_in_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('checked_in_at');
            $table->timestampsTz();

            $table->unique(['registration_id', 'event_day_id'], 'daily_event_checkin_unique');
            $table->index(['event_day_id', 'checked_in_at']);
        });

        Schema::create('talkshow_attendances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('talkshow_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('event_day_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('recorded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestampTz('attended_at');
            $table->boolean('prerequisite_overridden')->default(false);
            $table->foreignUuid('overridden_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->text('override_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['registration_id', 'talkshow_id'], 'talkshow_attendance_unique');
            $table->index(['talkshow_id', 'attended_at']);
        });

        Schema::create('ticket_deliveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->string('channel', 24);
            $table->string('provider', 80)->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('status', 24)->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('outbox_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_type', 120)->index();
            $table->uuid('aggregate_id')->index();
            $table->json('payload');
            $table->timestampTz('available_at');
            $table->timestampTz('processed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestampsTz();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('event_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 120)->index();
            $table->string('subject_type', 120);
            $table->uuid('subject_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at');

            $table->index(['subject_type', 'subject_id']);
            $table->index(['event_id', 'created_at']);
        });

        Schema::create('scan_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('registration_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('scanned_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('result', 40)->index();
            $table->char('token_hash', 64)->nullable()->index();
            $table->json('metadata')->nullable();
            $table->timestampTz('scanned_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scan_logs');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('outbox_messages');
        Schema::dropIfExists('ticket_deliveries');
        Schema::dropIfExists('talkshow_attendances');
        Schema::dropIfExists('daily_event_checkins');
    }
};
