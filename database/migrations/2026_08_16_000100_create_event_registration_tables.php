<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('registration_prefix', 12)->unique();
            $table->string('timezone')->default('Asia/Makassar');
            $table->date('starts_on');
            $table->date('ends_on');
            $table->timestampTz('registration_starts_at')->nullable();
            $table->timestampTz('registration_ends_at')->nullable();
            $table->string('venue');
            $table->text('address')->nullable();
            $table->string('status', 24)->default('published')->index();
            $table->timestampsTz();
        });

        Schema::create('event_days', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->date('event_date');
            $table->timestampTz('check_in_starts_at')->nullable();
            $table->timestampTz('check_in_ends_at')->nullable();
            $table->unsignedSmallInteger('sort_order');
            $table->timestampsTz();

            $table->unique(['event_id', 'event_date']);
            $table->unique(['event_id', 'sort_order']);
        });

        Schema::create('talkshows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('event_day_id')->nullable()->constrained()->nullOnDelete();
            $table->string('code', 40);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('room')->nullable();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->unsignedInteger('capacity')->nullable();
            $table->timestampTz('registration_starts_at')->nullable();
            $table->timestampTz('registration_ends_at')->nullable();
            $table->boolean('waitlist_enabled')->default(false);
            $table->string('status', 24)->default('published')->index();
            $table->timestampsTz();

            $table->unique(['event_id', 'code']);
            $table->index(['event_id', 'starts_at']);
        });

        Schema::create('participants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('full_name', 150);
            $table->string('whatsapp_e164', 16);
            $table->string('email', 254);
            $table->string('organization', 180)->nullable();
            $table->string('job_title', 150)->nullable();
            $table->string('city', 120)->nullable();
            $table->text('address')->nullable();
            $table->timestampsTz();

            $table->index('full_name');
            $table->index('whatsapp_e164');
            $table->index('email');
            $table->index('organization');
        });

        Schema::create('event_registration_sequences', function (Blueprint $table) {
            $table->foreignUuid('event_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('next_number')->default(1);
            $table->timestampsTz();
        });

        Schema::create('registrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('event_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('participant_id')->constrained()->restrictOnDelete();
            $table->string('registration_number', 32)->unique();
            $table->string('whatsapp_e164', 16);
            $table->string('email', 254);
            $table->string('registration_source', 24)->default('public');
            $table->string('idempotency_key', 80)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->char('qr_token_hash', 64)->unique();
            $table->longText('qr_token_encrypted');
            $table->string('qr_asset_path')->nullable();
            $table->json('talkshow_selection_result')->nullable();
            $table->timestampTz('registered_at');
            $table->timestampsTz();

            $table->unique(['event_id', 'participant_id'], 'registrations_event_participant_unique');
            $table->unique(['event_id', 'whatsapp_e164'], 'registrations_event_whatsapp_unique');
            $table->unique(['event_id', 'idempotency_key'], 'registrations_event_idempotency_unique');
            $table->index(['event_id', 'registered_at']);
        });

        Schema::create('registration_talkshows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('registration_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('talkshow_id')->constrained()->cascadeOnDelete();
            $table->string('status', 24)->index();
            $table->timestampTz('requested_at');
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('waitlisted_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('promoted_at')->nullable();
            $table->foreignUuid('promoted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('resolution_reason', 80)->nullable();
            $table->text('promotion_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['registration_id', 'talkshow_id']);
            $table->index(['talkshow_id', 'status', 'requested_at'], 'talkshow_waitlist_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('registration_talkshows');
        Schema::dropIfExists('registrations');
        Schema::dropIfExists('event_registration_sequences');
        Schema::dropIfExists('participants');
        Schema::dropIfExists('talkshows');
        Schema::dropIfExists('event_days');
        Schema::dropIfExists('events');
    }
};
