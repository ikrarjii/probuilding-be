<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('name', 100)->default('staff-web');
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampTz('expires_at');
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->index(['user_id', 'revoked_at', 'expires_at'], 'staff_tokens_auth_lookup');
            $table->index('expires_at', 'staff_tokens_expiry_lookup');
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->string('status', 24)->default('confirmed')->after('registration_source');
            $table->timestampTz('confirmed_at')->nullable()->after('registered_at');
            $table->index(['event_id', 'status'], 'registrations_event_status_lookup');
        });

        DB::table('registrations')
            ->whereNull('confirmed_at')
            ->update(['confirmed_at' => DB::raw('registered_at')]);

        Schema::table('event_user_assignments', function (Blueprint $table) {
            $table->index(
                ['user_id', 'is_active', 'event_id'],
                'event_assignments_user_active_event_lookup'
            );
            $table->index(
                ['event_id', 'is_active', 'role_id'],
                'event_assignments_event_active_role_lookup'
            );
        });

        Schema::table('daily_event_checkins', function (Blueprint $table) {
            $table->index(
                ['registration_id', 'checked_in_at'],
                'daily_checkins_registration_time_lookup'
            );
        });

        Schema::table('talkshow_attendances', function (Blueprint $table) {
            $table->index(
                ['event_day_id', 'attended_at'],
                'talkshow_attendance_day_time_lookup'
            );
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(
                ['actor_user_id', 'created_at'],
                'audit_logs_actor_time_lookup'
            );
        });

        Schema::table('scan_logs', function (Blueprint $table) {
            $table->index(['event_id', 'scanned_at'], 'scan_logs_event_time_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('scan_logs', function (Blueprint $table) {
            $table->dropIndex('scan_logs_event_time_lookup');
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_actor_time_lookup');
        });

        Schema::table('talkshow_attendances', function (Blueprint $table) {
            $table->dropIndex('talkshow_attendance_day_time_lookup');
        });

        Schema::table('daily_event_checkins', function (Blueprint $table) {
            $table->dropIndex('daily_checkins_registration_time_lookup');
        });

        Schema::table('event_user_assignments', function (Blueprint $table) {
            $table->dropIndex('event_assignments_user_active_event_lookup');
            $table->dropIndex('event_assignments_event_active_role_lookup');
        });

        Schema::table('registrations', function (Blueprint $table) {
            $table->dropIndex('registrations_event_status_lookup');
            $table->dropColumn(['status', 'confirmed_at']);
        });

        Schema::dropIfExists('staff_access_tokens');
    }
};
