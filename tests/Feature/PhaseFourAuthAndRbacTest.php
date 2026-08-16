<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Role;
use App\Models\StaffAccessToken;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PhaseFourAuthAndRbacTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->event = Event::where('slug', 'probuild-intim-2026')->firstOrFail();
    }

    public function test_valid_login_uses_a_hashed_expiring_token_and_returns_staff_identity(): void
    {
        $user = $this->userWithRole('super_admin');

        $response = $this->postJson('/api/v1/staff/auth/login', [
            'email' => strtoupper($user->email),
            'password' => 'ValidPassword123',
            'device_name' => 'phase-four-test',
        ])->assertOk()
            ->assertJsonPath('data.user.roles.0', 'super_admin')
            ->assertJsonPath('data.token_type', 'Bearer');

        $plainToken = $response->json('data.token');
        $stored = StaffAccessToken::firstOrFail();

        $this->assertTrue(Hash::check('ValidPassword123', $user->password));
        $this->assertNotSame('ValidPassword123', $user->password);
        $this->assertNotSame($plainToken, $stored->token_hash);
        $this->assertSame(hash('sha256', $plainToken), $stored->token_hash);
        $this->assertTrue($stored->expires_at->isFuture());
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'actor_user_id' => $user->id]);
    }

    public function test_first_super_admin_can_be_bootstrapped_without_a_plaintext_password_argument(): void
    {
        $this->artisan('staff:create-super-admin', [
            '--name' => 'Bootstrap Admin',
            '--email' => 'bootstrap-admin@example.test',
        ])
            ->expectsQuestion('Password (minimum 8 characters)', 'sandi123')
            ->expectsQuestion('Confirm password', 'sandi123')
            ->assertSuccessful();

        $user = User::where('email', 'bootstrap-admin@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole('super_admin'));
        $this->assertTrue(Hash::check('sandi123', $user->password));
        $this->assertNotSame('sandi123', $user->password);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.bootstrap_super_admin_created',
            'subject_id' => $user->id,
        ]);
    }

    public function test_an_additional_super_admin_can_be_created_with_an_internally_generated_password(): void
    {
        $this->userWithRole('super_admin');

        $this->artisan('staff:create-super-admin', [
            '--name' => 'Additional Admin',
            '--email' => 'additional-admin@example.test',
            '--allow-additional' => true,
            '--generate-password' => true,
        ])->assertSuccessful();

        $user = User::where('email', 'additional-admin@example.test')->firstOrFail();
        $this->assertTrue($user->hasRole('super_admin'));
        $this->assertNotEmpty($user->password);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'user.additional_super_admin_created',
            'subject_id' => $user->id,
        ]);
    }

    public function test_invalid_login_is_rejected_without_issuing_a_token(): void
    {
        $user = $this->userWithRole('panitia');

        $this->postJson('/api/v1/staff/auth/login', [
            'email' => $user->email,
            'password' => 'WrongPassword123',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Invalid credentials.');

        $this->assertDatabaseCount('staff_access_tokens', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login_failed']);
    }

    public function test_logout_revokes_only_the_presented_token(): void
    {
        $user = $this->userWithRole('super_admin');
        $token = $this->login($user);

        $this->withToken($token)->postJson('/api/v1/staff/auth/logout')
            ->assertOk()
            ->assertJsonPath('data.logged_out', true);

        $this->withToken($token)->getJson('/api/v1/staff/auth/me')->assertUnauthorized();
        $this->assertNotNull(StaffAccessToken::firstOrFail()->revoked_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.logout', 'actor_user_id' => $user->id]);
    }

    public function test_unauthenticated_staff_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/staff/events')->assertUnauthorized();
        $this->getJson("/api/v1/staff/events/{$this->event->slug}/participants")->assertUnauthorized();
        $this->getJson("/api/v1/staff/events/{$this->event->slug}/statistics")->assertUnauthorized();
    }

    public function test_super_admin_can_manage_users_assign_panitia_and_view_audit_logs(): void
    {
        $admin = $this->userWithRole('super_admin');
        $panitia = $this->userWithRole('panitia');
        $token = $this->login($admin);

        $this->withToken($token)->getJson('/api/v1/staff/users')->assertOk();
        $assignment = $this->withToken($token)
            ->postJson("/api/v1/staff/events/{$this->event->slug}/assignments", [
                'user_id' => $panitia->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.user.id', $panitia->id)
            ->json('data');

        $this->assertDatabaseHas('event_user_assignments', [
            'id' => $assignment['id'],
            'event_id' => $this->event->id,
            'user_id' => $panitia->id,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'event.panitia_assigned',
            'actor_user_id' => $admin->id,
            'event_id' => $this->event->id,
        ]);

        $this->withToken($token)->getJson('/api/v1/staff/audit-logs')
            ->assertOk()
            ->assertJsonFragment(['action' => 'event.panitia_assigned']);
    }

    public function test_super_admin_can_create_staff_with_a_simple_eight_character_password(): void
    {
        $admin = $this->userWithRole('super_admin');
        $token = $this->login($admin);

        $this->withToken($token)->postJson('/api/v1/staff/users', [
            'name' => 'Simple Password Staff',
            'email' => 'simple-password@example.test',
            'password' => 'sandi123',
            'role' => 'panitia',
        ])->assertCreated();

        $user = User::where('email', 'simple-password@example.test')->firstOrFail();
        $this->assertTrue(Hash::check('sandi123', $user->password));
        $this->assertNotSame('sandi123', $user->password);

        $this->withToken($token)->postJson('/api/v1/staff/users', [
            'name' => 'Too Short Staff',
            'email' => 'too-short@example.test',
            'password' => 'sandi12',
            'role' => 'panitia',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }

    public function test_panitia_can_access_only_explicitly_assigned_events(): void
    {
        $admin = $this->userWithRole('super_admin');
        $panitia = $this->userWithRole('panitia');
        $otherEvent = $this->createOtherEvent();

        $this->withToken($this->login($admin))
            ->postJson("/api/v1/staff/events/{$this->event->slug}/assignments", [
                'user_id' => $panitia->id,
            ])->assertCreated();
        $token = $this->login($panitia);

        $this->withToken($token)->getJson('/api/v1/staff/events')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->event->id);
        $this->withToken($token)
            ->getJson("/api/v1/staff/events/{$this->event->slug}/participants")
            ->assertOk();
        $this->withToken($token)
            ->getJson("/api/v1/staff/events/{$otherEvent->slug}/participants")
            ->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/staff/users')->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/staff/audit-logs')->assertForbidden();
    }

    public function test_vendor_receives_only_aggregates_and_cannot_reach_pii_by_tampering(): void
    {
        $this->registerParticipant('Vendor Privacy Target', '0812 7700 0001', 'private@example.test');
        $vendor = $this->userWithRole('vendor');
        $token = $this->login($vendor);
        $otherEvent = $this->createOtherEvent();

        $response = $this->withToken($token)->getJson(
            "/api/v1/staff/events/{$this->event->slug}/statistics?include=participants&search=private%40example.test"
        )->assertOk()
            ->assertJsonPath('data.summary.total_registrations', 1)
            ->assertJsonPath('data.summary.confirmed_registrations', 1);
        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);

        foreach (['Vendor Privacy Target', 'private@example.test', '+6281277000001', 'full_name', 'whatsapp', 'address'] as $pii) {
            $this->assertStringNotContainsString($pii, $encoded);
        }

        $this->withToken($token)
            ->getJson("/api/v1/staff/events/{$this->event->slug}/participants?include=all")
            ->assertForbidden();
        $this->withToken($token)
            ->getJson("/api/v1/staff/events/{$otherEvent->slug}/participants")
            ->assertForbidden();
        $this->withToken($token)
            ->getJson("/api/v1/staff/events/{$otherEvent->slug}/statistics?participant_id=".Str::uuid())
            ->assertOk();
        $this->withToken($token)->getJson('/api/v1/staff/users')->assertForbidden();
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create([
            'email' => Str::uuid().'@example.test',
            'password' => 'ValidPassword123',
        ]);
        $user->roles()->attach(Role::where('slug', $roleSlug)->firstOrFail());

        return $user;
    }

    private function login(User $user): string
    {
        return $this->postJson('/api/v1/staff/auth/login', [
            'email' => $user->email,
            'password' => 'ValidPassword123',
        ])->assertOk()->json('data.token');
    }

    private function createOtherEvent(): Event
    {
        return Event::create([
            'name' => 'Other Event',
            'slug' => 'other-event-'.Str::lower(Str::random(6)),
            'registration_prefix' => Str::upper(Str::random(8)),
            'timezone' => 'Asia/Makassar',
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-10-02',
            'venue' => 'Other Venue',
            'status' => EventStatus::Published,
        ]);
    }

    private function registerParticipant(string $name, string $whatsapp, string $email): void
    {
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/public/events/{$this->event->slug}/registrations", [
                'full_name' => $name,
                'whatsapp' => $whatsapp,
                'email' => $email,
                'address' => 'A private participant address',
                'talkshow_ids' => [],
            ])->assertCreated();
    }
}
