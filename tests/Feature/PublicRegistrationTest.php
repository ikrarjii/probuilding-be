<?php

namespace Tests\Feature;

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Registration;
use App\Models\RegistrationTalkshow;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-16 10:00:00', 'Asia/Makassar'));
        $this->seed(DatabaseSeeder::class);
        $this->event = Event::where('slug', 'probuild-intim-2026')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_registration_options_expose_current_talkshow_availability(): void
    {
        $talkshow = $this->event->talkshows()->firstOrFail();
        $talkshow->update(['capacity' => 25, 'waitlist_enabled' => true]);

        $this->getJson($this->registrationPath())
            ->assertOk()
            ->assertJsonPath('data.event.slug', 'probuild-intim-2026')
            ->assertJsonPath('data.event.registration_open', true)
            ->assertJsonPath('data.talkshows.0.capacity', 25)
            ->assertJsonPath('data.talkshows.0.availability', 'available');
    }

    public function test_missing_registration_event_returns_a_safe_json_error(): void
    {
        $this->getJson('/api/v1/public/events/missing-event/registration')
            ->assertNotFound()
            ->assertExactJson([
                'message' => 'Informasi event registrasi belum tersedia.',
            ])
            ->assertDontSee('App\\Models\\Event');
    }

    public function test_full_name_whatsapp_and_email_are_required(): void
    {
        $this->postRegistration([])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['full_name', 'whatsapp', 'email']);

        $this->assertDatabaseCount('registrations', 0);
    }

    public function test_required_contact_fields_create_one_registration_with_multiple_talkshows(): void
    {
        $talkshows = $this->event->talkshows()->take(2)->pluck('id')->all();

        $response = $this->postRegistration($this->payload([
            'talkshow_ids' => $talkshows,
        ]));

        $response
            ->assertCreated()
            ->assertJsonPath('data.registration.registration_number', 'PBI26-000001')
            ->assertJsonPath('data.registration.participant.whatsapp', '+6281234567890')
            ->assertJsonCount(2, 'data.registration.talkshows');

        $registration = Registration::firstOrFail();

        $this->assertSame(64, strlen($registration->qr_token_hash));
        $this->assertNotSame($registration->whatsapp_e164, Crypt::decryptString($registration->qr_token_encrypted));
        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('registrations', 1);
        $this->assertDatabaseCount('registration_talkshows', 2);
        $this->assertDatabaseCount('ticket_deliveries', 1);
        $this->assertDatabaseCount('outbox_messages', 2);
    }

    public function test_email_can_be_shared_but_whatsapp_is_unique_within_an_event(): void
    {
        $sharedEmail = 'shared@company.example';

        $this->postRegistration($this->payload([
            'email' => $sharedEmail,
            'whatsapp' => '0812 1111 1111',
        ]))->assertCreated();

        $this->postRegistration($this->payload([
            'full_name' => 'Second Participant',
            'email' => $sharedEmail,
            'whatsapp' => '0812 2222 2222',
        ]))->assertCreated();

        $this->postRegistration($this->payload([
            'full_name' => 'Duplicate WhatsApp',
            'email' => 'different@example.test',
            'whatsapp' => '+62 812 1111 1111',
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('whatsapp');

        $this->assertDatabaseCount('registrations', 2);
        $this->assertSame(2, Registration::where('email', $sharedEmail)->count());
    }

    public function test_database_constraint_rejects_duplicate_whatsapp_for_the_same_event(): void
    {
        $this->postRegistration($this->payload())->assertCreated();
        $existing = Registration::firstOrFail()->getAttributes();
        $participantId = (string) Str::uuid();

        DB::table('participants')->insert([
            'id' => $participantId,
            'full_name' => 'Constraint Test',
            'whatsapp_e164' => $existing['whatsapp_e164'],
            'email' => 'constraint@example.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        DB::table('registrations')->insert([
            ...$existing,
            'id' => (string) Str::uuid(),
            'participant_id' => $participantId,
            'registration_number' => 'PBI26-999999',
            'idempotency_key' => (string) Str::uuid(),
            'request_fingerprint' => hash('sha256', 'constraint-test'),
            'qr_token_hash' => hash('sha256', 'different-token'),
            'qr_token_encrypted' => Crypt::encryptString('different-token'),
        ]);
    }

    public function test_the_same_whatsapp_can_register_for_another_event(): void
    {
        $this->postRegistration($this->payload())->assertCreated();

        $otherEvent = Event::create([
            'name' => 'Another Event',
            'slug' => 'another-event',
            'registration_prefix' => 'OTHER',
            'timezone' => 'Asia/Makassar',
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-10-01',
            'registration_starts_at' => now()->subDay(),
            'registration_ends_at' => now()->addDay(),
            'venue' => 'Makassar',
            'status' => EventStatus::Published,
        ]);

        $this->postRegistration($this->payload(), $otherEvent, (string) Str::uuid())
            ->assertCreated()
            ->assertJsonPath('data.registration.registration_number', 'OTHER-000001');

        $this->assertDatabaseCount('registrations', 2);
        $this->assertSame(2, Registration::where('whatsapp_e164', '+6281234567890')->count());
    }

    public function test_idempotent_retries_return_the_existing_registration_and_reject_changed_data(): void
    {
        $key = (string) Str::uuid();
        $payload = $this->payload();

        $first = $this->postRegistration($payload, key: $key)
            ->assertCreated()
            ->json('data.registration.id');

        $this->postRegistration($payload, key: $key)
            ->assertOk()
            ->assertJsonPath('data.registration.id', $first)
            ->assertJsonPath('data.idempotent_replay', true);

        $this->postRegistration([...$payload, 'full_name' => 'Changed Name'], key: $key)
            ->assertStatus(409);

        $this->assertDatabaseCount('registrations', 1);
    }

    public function test_talkshow_capacity_is_resolved_individually_without_rejecting_main_registration(): void
    {
        $talkshows = $this->event->talkshows()->take(3)->get();
        $fullWithoutWaitlist = $talkshows[0];
        $fullWithWaitlist = $talkshows[1];
        $available = $talkshows[2];

        $fullWithoutWaitlist->update(['capacity' => 1, 'waitlist_enabled' => false]);
        $fullWithWaitlist->update(['capacity' => 1, 'waitlist_enabled' => true]);
        $available->update(['capacity' => 2, 'waitlist_enabled' => true]);

        $this->postRegistration($this->payload([
            'whatsapp' => '0812 0000 0001',
            'talkshow_ids' => [$fullWithoutWaitlist->id, $fullWithWaitlist->id],
        ]))->assertCreated();

        $response = $this->postRegistration($this->payload([
            'full_name' => 'Capacity Test',
            'whatsapp' => '0812 0000 0002',
            'talkshow_ids' => [
                $fullWithoutWaitlist->id,
                $fullWithWaitlist->id,
                $available->id,
            ],
        ]))->assertCreated();

        $results = collect($response->json('data.registration.talkshows'))->keyBy('talkshow_id');

        $this->assertSame('unavailable', $results[$fullWithoutWaitlist->id]['status']);
        $this->assertSame('waitlisted', $results[$fullWithWaitlist->id]['status']);
        $this->assertSame(1, $results[$fullWithWaitlist->id]['waitlist_position']);
        $this->assertSame('confirmed', $results[$available->id]['status']);
        $this->assertDatabaseCount('registrations', 2);
        $this->assertSame(5, RegistrationTalkshow::count());
        $this->assertDatabaseHas('registration_talkshows', [
            'registration_id' => Registration::where('whatsapp_e164', '+6281200000002')->value('id'),
            'talkshow_id' => $fullWithoutWaitlist->id,
            'status' => 'unavailable',
            'resolution_reason' => 'capacity_full',
        ]);
    }

    public function test_closed_talkshow_is_reported_unavailable_while_main_registration_succeeds(): void
    {
        $talkshow = $this->event->talkshows()->firstOrFail();
        $talkshow->update(['registration_ends_at' => now()->subMinute()]);

        $this->postRegistration($this->payload(['talkshow_ids' => [$talkshow->id]]))
            ->assertCreated()
            ->assertJsonPath('data.registration.talkshows.0.status', 'unavailable')
            ->assertJsonPath('data.registration.talkshows.0.reason', 'registration_closed');

        $this->assertDatabaseCount('registrations', 1);
        $this->assertDatabaseHas('registration_talkshows', [
            'talkshow_id' => $talkshow->id,
            'status' => 'unavailable',
            'resolution_reason' => 'registration_closed',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'full_name' => 'Salwa Participant',
            'whatsapp' => '0812 3456 7890',
            'email' => 'participant@example.test',
            'organization' => 'ProBuild Partner',
            'job_title' => 'Engineer',
            'city' => 'Makassar',
            'address' => 'Jl. Contoh No. 1',
            'talkshow_ids' => [],
            ...$overrides,
        ];
    }

    private function registrationPath(?Event $event = null): string
    {
        $event ??= $this->event;

        return "/api/v1/public/events/{$event->slug}/registration";
    }

    private function postRegistration(
        array $payload,
        ?Event $event = null,
        ?string $key = null,
    ) {
        $event ??= $this->event;

        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson("/api/v1/public/events/{$event->slug}/registrations", $payload);
    }
}
