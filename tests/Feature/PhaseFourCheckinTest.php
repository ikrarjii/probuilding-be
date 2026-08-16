<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\DailyEventCheckin;
use App\Models\Event;
use App\Models\EventDay;
use App\Models\Registration;
use App\Models\Role;
use App\Models\TalkshowAttendance;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhaseFourCheckinTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    private User $panitia;

    private string $accessToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->event = Event::where('slug', 'probuild-intim-2026')->firstOrFail();
        $this->panitia = $this->userWithRole('panitia');
        $this->assignPanitia($this->panitia, $this->event);
        $this->accessToken = $this->login($this->panitia);
    }

    public function test_daily_checkin_is_idempotent_and_supports_different_event_days(): void
    {
        $ticket = $this->register('0812 8100 0001');
        $days = $this->event->days()->take(2)->get();

        $first = $this->withToken($this->accessToken)
            ->postJson($this->dailyPath($days[0]), ['ticket' => $ticket['url']])
            ->assertCreated()
            ->assertJsonPath('data.result', 'checked_in')
            ->assertJsonPath('data.idempotent', false);
        $duplicate = $this->withToken($this->accessToken)
            ->postJson($this->dailyPath($days[0]), ['ticket' => $ticket['token']])
            ->assertOk()
            ->assertJsonPath('data.result', 'already_checked_in')
            ->assertJsonPath('data.idempotent', true);

        $this->assertSame($first->json('data.checkin.id'), $duplicate->json('data.checkin.id'));

        $this->withToken($this->accessToken)
            ->postJson($this->dailyPath($days[1]), ['ticket' => $ticket['token']])
            ->assertCreated();

        $this->assertDatabaseCount('daily_event_checkins', 2);
        $this->assertSame(2, DB::table('scan_logs')->where('result', 'accepted')->count());
        $this->assertSame(1, DB::table('scan_logs')->where('result', 'duplicate')->count());
        $this->assertSame(2, DB::table('audit_logs')->where('action', 'event.checkin_recorded')->count());
    }

    public function test_assigned_panitia_can_check_in_an_arriving_participant_from_the_list(): void
    {
        $ticket = $this->register('0812 8150 0001');
        $day = $this->event->days()->firstOrFail();
        $path = $this->participantDailyPath($day, $ticket['id']);

        $first = $this->withToken($this->accessToken)
            ->postJson($path)
            ->assertCreated()
            ->assertJsonPath('data.result', 'checked_in')
            ->assertJsonPath('data.idempotent', false)
            ->assertJsonPath('data.checkin.registration_number', $ticket['registration_number']);
        $duplicate = $this->withToken($this->accessToken)
            ->postJson($path)
            ->assertOk()
            ->assertJsonPath('data.result', 'already_checked_in')
            ->assertJsonPath('data.idempotent', true);

        $this->assertSame($first->json('data.checkin.id'), $duplicate->json('data.checkin.id'));
        $this->assertDatabaseCount('daily_event_checkins', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'event.checkin_recorded')->count());

        $unassignedPanitia = $this->userWithRole('panitia');
        $this->withToken($this->login($unassignedPanitia))
            ->postJson($path)
            ->assertForbidden();

        $vendor = $this->userWithRole('vendor');
        $this->withToken($this->login($vendor))
            ->postJson($path)
            ->assertForbidden();
    }

    public function test_assigned_panitia_can_find_a_participant_by_id_and_download_the_ticket(): void
    {
        $ticket = $this->register('0812 8160 0001');
        $registration = Registration::findOrFail($ticket['id']);

        foreach ([$registration->id, $registration->participant_id] as $searchId) {
            $this->withToken($this->accessToken)
                ->getJson("/api/v1/staff/events/{$this->event->slug}/participants?search={$searchId}")
                ->assertOk()
                ->assertJsonPath('data.total', 1)
                ->assertJsonPath('data.data.0.id', $registration->id);
        }

        $path = "/api/v1/staff/events/{$this->event->slug}/registrations/{$registration->id}/e-ticket";
        $response = $this->withToken($this->accessToken)
            ->get($path)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'registration.ticket_downloaded',
            'actor_user_id' => $this->panitia->id,
            'event_id' => $this->event->id,
            'subject_id' => $registration->id,
        ]);

        $unassignedPanitia = $this->userWithRole('panitia');
        $this->withToken($this->login($unassignedPanitia))->get($path)->assertForbidden();

        $vendor = $this->userWithRole('vendor');
        $this->withToken($this->login($vendor))->get($path)->assertForbidden();

        $otherEvent = Event::create([
            'name' => 'Ticket IDOR Event',
            'slug' => 'ticket-idor-event',
            'registration_prefix' => 'TIDOR',
            'timezone' => 'Asia/Makassar',
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-10-01',
            'venue' => 'Other Venue',
            'status' => EventStatus::Published,
        ]);
        $admin = $this->userWithRole('super_admin');
        $this->withToken($this->login($admin))
            ->get("/api/v1/staff/events/{$otherEvent->slug}/registrations/{$registration->id}/e-ticket")
            ->assertNotFound();
    }

    public function test_competing_duplicate_checkin_attempts_are_guarded_by_a_unique_constraint(): void
    {
        $ticket = $this->register('0812 8200 0001');
        $day = $this->event->days()->firstOrFail();

        $response = $this->withToken($this->accessToken)
            ->postJson($this->dailyPath($day), ['ticket' => $ticket['token']])
            ->assertCreated();
        $checkin = DailyEventCheckin::findOrFail($response->json('data.checkin.id'));

        $losingInsert = DB::table('daily_event_checkins')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'registration_id' => $checkin->registration_id,
            'event_day_id' => $checkin->event_day_id,
            'checked_in_by_user_id' => $this->panitia->id,
            'checked_in_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame(0, $losingInsert, 'The database must reject the losing concurrent insert.');
        $this->assertDatabaseCount('daily_event_checkins', 1);
    }

    public function test_talkshow_attendance_is_idempotent_and_requires_a_confirmed_selection(): void
    {
        $talkshow = $this->event->talkshows()->firstOrFail();
        $ticket = $this->register('0812 8300 0001', [$talkshow->id]);
        $day = EventDay::findOrFail($talkshow->event_day_id);

        $this->withToken($this->accessToken)
            ->postJson($this->dailyPath($day), ['ticket' => $ticket['token']])
            ->assertCreated();

        $first = $this->withToken($this->accessToken)
            ->postJson($this->talkshowPath($talkshow->id), ['ticket' => $ticket['token']])
            ->assertCreated()
            ->assertJsonPath('data.result', 'attendance_recorded');
        $duplicate = $this->withToken($this->accessToken)
            ->postJson($this->talkshowPath($talkshow->id), ['ticket' => $ticket['token']])
            ->assertOk()
            ->assertJsonPath('data.result', 'already_recorded');

        $this->assertSame($first->json('data.attendance.id'), $duplicate->json('data.attendance.id'));
        $this->assertDatabaseCount('talkshow_attendances', 1);
        $this->assertSame(1, DB::table('audit_logs')->where('action', 'talkshow.attendance_recorded')->count());

        $losingInsert = DB::table('talkshow_attendances')->insertOrIgnore([
            'id' => (string) Str::uuid(),
            'registration_id' => TalkshowAttendance::firstOrFail()->registration_id,
            'talkshow_id' => $talkshow->id,
            'event_day_id' => $day->id,
            'recorded_by_user_id' => $this->panitia->id,
            'attended_at' => now(),
            'prerequisite_overridden' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertSame(0, $losingInsert);
        $this->assertDatabaseCount('talkshow_attendances', 1);
    }

    public function test_unknown_ticket_is_rejected_and_logged_without_storing_the_raw_value(): void
    {
        $unknown = str_repeat('a', 64);
        $day = $this->event->days()->firstOrFail();

        $this->withToken($this->accessToken)
            ->postJson($this->dailyPath($day), ['ticket' => $unknown])
            ->assertNotFound()
            ->assertJsonPath('message', 'Invalid or unknown ticket.');

        $this->assertDatabaseHas('scan_logs', [
            'event_id' => $this->event->id,
            'result' => 'invalid_ticket',
            'token_hash' => null,
        ]);
        $this->assertStringNotContainsString($unknown, json_encode(DB::table('scan_logs')->first(), JSON_THROW_ON_ERROR));
    }

    public function test_ticket_cannot_be_checked_into_another_event_or_nested_resource(): void
    {
        $ticket = $this->register('0812 8400 0001');
        $otherEvent = Event::create([
            'name' => 'Wrong Event',
            'slug' => 'wrong-event',
            'registration_prefix' => 'WRONG',
            'timezone' => 'Asia/Makassar',
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-10-01',
            'venue' => 'Wrong Venue',
            'status' => EventStatus::Published,
        ]);
        $otherDay = EventDay::create([
            'event_id' => $otherEvent->id,
            'label' => 'Other Day',
            'event_date' => '2026-10-01',
            'sort_order' => 1,
        ]);
        $admin = $this->userWithRole('super_admin');
        $adminToken = $this->login($admin);

        $this->withToken($adminToken)
            ->postJson(
                "/api/v1/staff/events/{$otherEvent->slug}/event-days/{$otherDay->id}/check-ins",
                ['ticket' => $ticket['token']],
            )
            ->assertUnprocessable()
            ->assertJsonPath('message', 'This ticket belongs to a different event.');
        $this->withToken($adminToken)
            ->postJson(
                "/api/v1/staff/events/{$this->event->slug}/event-days/{$otherDay->id}/check-ins",
                ['ticket' => $ticket['token']],
            )
            ->assertNotFound();
        $this->withToken($adminToken)
            ->postJson(
                "/api/v1/staff/events/{$otherEvent->slug}/event-days/{$otherDay->id}/registrations/{$ticket['id']}/check-ins",
            )
            ->assertNotFound();

        $this->assertDatabaseCount('daily_event_checkins', 0);
        $this->assertDatabaseHas('scan_logs', ['result' => 'wrong_event']);
    }

    private function register(string $whatsapp, array $talkshows = []): array
    {
        $data = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/public/events/{$this->event->slug}/registrations", [
                'full_name' => "Checkin {$whatsapp}",
                'whatsapp' => $whatsapp,
                'email' => Str::uuid().'@example.test',
                'talkshow_ids' => $talkshows,
            ])->assertCreated()->json('data.registration');

        return [
            'id' => $data['id'],
            'registration_number' => $data['registration_number'],
            'token' => $data['e_ticket']['access_token'],
            'url' => $data['e_ticket']['url'],
        ];
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create([
            'email' => Str::uuid().'@example.test',
            'password' => 'ValidPassword123',
        ]);
        $user->roles()->attach(Role::where('slug', $role)->firstOrFail());

        return $user;
    }

    private function assignPanitia(User $user, Event $event): void
    {
        DB::table('event_user_assignments')->insert([
            'id' => (string) Str::uuid(),
            'event_id' => $event->id,
            'user_id' => $user->id,
            'role_id' => Role::where('slug', 'panitia')->value('id'),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function login(User $user): string
    {
        return $this->postJson('/api/v1/staff/auth/login', [
            'email' => $user->email,
            'password' => 'ValidPassword123',
        ])->assertOk()->json('data.token');
    }

    private function dailyPath(EventDay $day): string
    {
        return "/api/v1/staff/events/{$this->event->slug}/event-days/{$day->id}/check-ins";
    }

    private function participantDailyPath(EventDay $day, string $registrationId): string
    {
        return "/api/v1/staff/events/{$this->event->slug}/event-days/{$day->id}/registrations/{$registrationId}/check-ins";
    }

    private function talkshowPath(string $talkshowId): string
    {
        return "/api/v1/staff/events/{$this->event->slug}/talkshows/{$talkshowId}/attendances";
    }
}
