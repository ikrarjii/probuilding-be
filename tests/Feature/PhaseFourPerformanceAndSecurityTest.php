<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Enums\RegistrationSource;
use App\Enums\RegistrationStatus;
use App\Models\Event;
use App\Models\Participant;
use App\Models\Registration;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhaseFourPerformanceAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->event = Event::where('slug', 'probuild-intim-2026')->firstOrFail();
        $panitia = User::factory()->create([
            'email' => 'performance-panitia@example.test',
            'password' => 'ValidPassword123',
        ]);
        $role = Role::where('slug', 'panitia')->firstOrFail();
        $panitia->roles()->attach($role);
        DB::table('event_user_assignments')->insert([
            'id' => (string) Str::uuid(),
            'event_id' => $this->event->id,
            'user_id' => $panitia->id,
            'role_id' => $role->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->token = $this->postJson('/api/v1/staff/auth/login', [
            'email' => $panitia->email,
            'password' => 'ValidPassword123',
        ])->assertOk()->json('data.token');
    }

    public function test_participant_lists_are_paginated_and_reject_unbounded_page_sizes(): void
    {
        $this->createRegistrations(12);

        $this->withToken($this->token)
            ->getJson("/api/v1/staff/events/{$this->event->slug}/participants?per_page=5")
            ->assertOk()
            ->assertJsonCount(5, 'data.data')
            ->assertJsonPath('data.total', 12)
            ->assertJsonPath('data.per_page', 5);
        $this->withToken($this->token)
            ->getJson("/api/v1/staff/events/{$this->event->slug}/participants?per_page=1000")
            ->assertUnprocessable();
    }

    public function test_participant_search_status_filters_and_pagination_are_combined_server_side(): void
    {
        $this->createRegistrations(6);
        $day = $this->event->days()->firstOrFail();
        $checkedRegistration = Registration::where('registration_number', 'PERF-000001')
            ->firstOrFail();
        $panitia = User::where('email', 'performance-panitia@example.test')->firstOrFail();

        DB::table('daily_event_checkins')->insert([
            'id' => (string) Str::uuid(),
            'registration_id' => $checkedRegistration->id,
            'event_day_id' => $day->id,
            'checked_in_by_user_id' => $panitia->id,
            'checked_in_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $localPhone = '0'.substr($checkedRegistration->whatsapp_e164, 3);
        $checkedQuery = http_build_query([
            'search' => $localPhone,
            'checkin_status' => 'checked_in',
            'event_day_id' => $day->id,
            'per_page' => 25,
        ]);

        $this->withToken($this->token)
            ->getJson("/api/v1/staff/events/{$this->event->slug}/participants?{$checkedQuery}")
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.registration_number', 'PERF-000001')
            ->assertJsonPath('data.data.0.daily_checkins.0.event_day_id', $day->id);

        $notCheckedInQuery = http_build_query([
            'search' => 'Participant',
            'checkin_status' => 'not_checked_in',
            'event_day_id' => $day->id,
            'per_page' => 2,
            'page' => 2,
        ]);

        $this->withToken($this->token)
            ->getJson("/api/v1/staff/events/{$this->event->slug}/participants?{$notCheckedInQuery}")
            ->assertOk()
            ->assertJsonPath('data.total', 5)
            ->assertJsonPath('data.per_page', 2)
            ->assertJsonPath('data.current_page', 2)
            ->assertJsonCount(2, 'data.data');

        foreach (['PERF-000003', 'participant3@example.test'] as $search) {
            $this->withToken($this->token)
                ->getJson("/api/v1/staff/events/{$this->event->slug}/participants?search={$search}")
                ->assertOk()
                ->assertJsonPath('data.total', 1)
                ->assertJsonPath('data.data.0.registration_number', 'PERF-000003');
        }

        $this->withToken($this->token)
            ->getJson(
                "/api/v1/staff/events/{$this->event->slug}/participants?checkin_status=checked_in"
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event_day_id');

        $otherEvent = Event::create([
            'name' => 'Other Filter Event',
            'slug' => 'other-filter-event',
            'registration_prefix' => 'OFE',
            'timezone' => 'Asia/Makassar',
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-10-01',
            'venue' => 'Other Venue',
            'status' => EventStatus::Published,
        ]);
        $otherDay = $otherEvent->days()->create([
            'label' => 'Hari 1',
            'event_date' => '2026-10-01',
            'sort_order' => 1,
        ]);

        $this->withToken($this->token)
            ->getJson(
                "/api/v1/staff/events/{$this->event->slug}/participants?checkin_status=checked_in&event_day_id={$otherDay->id}"
            )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('event_day_id');
    }

    public function test_participant_eager_loading_has_a_stable_query_count(): void
    {
        $this->createRegistrations(10);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->withToken($this->token)
            ->getJson("/api/v1/staff/events/{$this->event->slug}/participants?per_page=1")
            ->assertOk();
        $oneParticipantQueryCount = count(DB::getQueryLog());

        DB::flushQueryLog();
        $this->withToken($this->token)
            ->getJson("/api/v1/staff/events/{$this->event->slug}/participants?per_page=10")
            ->assertOk();
        $tenParticipantQueryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(
            1,
            abs($oneParticipantQueryCount - $tenParticipantQueryCount),
            'Query count must not grow with the number of participant rows.'
        );
    }

    public function test_statistics_use_aggregate_queries_without_loading_participant_rows(): void
    {
        $this->createRegistrations(4);
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->withToken($this->token)
            ->getJson("/api/v1/staff/events/{$this->event->slug}/statistics")
            ->assertOk()
            ->assertJsonPath('data.summary.total_registrations', 4)
            ->assertJsonPath('data.summary.confirmed_registrations', 4);
        $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
        DB::disableQueryLog();

        $this->assertStringNotContainsString('from "participants"', strtolower($queries));
        $this->assertStringNotContainsString('Participant 1', json_encode($response->json(), JSON_THROW_ON_ERROR));
    }

    public function test_critical_lookup_and_invariant_indexes_exist(): void
    {
        $this->assertIndexExists('users', 'users_email_unique');
        $this->assertIndexExists('staff_access_tokens', 'staff_access_tokens_token_hash_unique');
        $this->assertIndexExists('staff_access_tokens', 'staff_tokens_auth_lookup');
        $this->assertIndexExists('event_user_assignments', 'event_user_role_unique');
        $this->assertIndexExists('event_user_assignments', 'event_assignments_user_active_event_lookup');
        $this->assertIndexExists('registrations', 'registrations_ticket_access_token_unique');
        $this->assertIndexExists('registrations', 'registrations_event_status_lookup');
        $this->assertIndexExists('daily_event_checkins', 'daily_event_checkin_unique');
        $this->assertIndexExists('talkshow_attendances', 'talkshow_attendance_unique');
    }

    private function createRegistrations(int $count): void
    {
        foreach (range(1, $count) as $number) {
            $whatsapp = '+6281300'.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
            $participant = Participant::create([
                'full_name' => "Participant {$number}",
                'whatsapp_e164' => $whatsapp,
                'email' => "participant{$number}@example.test",
                'address' => "Private address {$number}",
            ]);
            $rawToken = bin2hex(random_bytes(32));
            $encrypted = Crypt::encryptString($rawToken);
            $hash = hash('sha256', $rawToken);

            Registration::create([
                'event_id' => $this->event->id,
                'participant_id' => $participant->id,
                'registration_number' => sprintf('PERF-%06d', $number),
                'whatsapp_e164' => $whatsapp,
                'email' => $participant->email,
                'registration_source' => RegistrationSource::Public,
                'status' => RegistrationStatus::Confirmed,
                'qr_token_hash' => $hash,
                'qr_token_encrypted' => $encrypted,
                'ticket_access_token_hash' => $hash,
                'ticket_access_token_encrypted' => $encrypted,
                'registered_at' => now(),
                'confirmed_at' => now(),
            ]);
        }
    }

    private function assertIndexExists(string $table, string $index): void
    {
        $indexes = collect(Schema::getIndexes($table))->pluck('name');
        $this->assertTrue($indexes->contains($index), "Missing {$index} on {$table}.");
    }
}
