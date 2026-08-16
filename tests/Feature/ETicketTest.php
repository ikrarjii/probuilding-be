<?php

namespace Tests\Feature;

use App\Models\DailyEventCheckin;
use App\Models\Event;
use App\Models\Registration;
use App\Models\User;
use App\Services\Tickets\ETicketAccessService;
use App\Services\Tickets\QrCodeRenderer;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ETicketTest extends TestCase
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

    public function test_qr_payload_is_a_secure_persistent_ticket_url_without_participant_pii(): void
    {
        $first = $this->register('0812 7100 0001', 'QR Identity One');
        $second = $this->register('0812 7100 0002', 'QR Identity Two');
        $firstRegistration = Registration::findOrFail($first['registration']['id']);
        $secondRegistration = Registration::findOrFail($second['registration']['id']);
        $renderer = app(QrCodeRenderer::class);

        $this->assertNotSame($firstRegistration->qr_token_hash, $secondRegistration->qr_token_hash);
        $this->assertNotSame(
            $firstRegistration->ticket_access_token_hash,
            $secondRegistration->ticket_access_token_hash
        );
        $this->assertSame(
            $firstRegistration->qr_token_hash,
            $firstRegistration->ticket_access_token_hash
        );
        $this->assertSame(64, strlen($firstRegistration->qr_token_hash));
        $this->assertSame(64, strlen($firstRegistration->ticket_access_token_hash));

        $payload = $renderer->payload($firstRegistration);
        $ticketToken = $first['registration']['e_ticket']['access_token'];

        $this->assertNotFalse(filter_var($payload, FILTER_VALIDATE_URL));
        $this->assertSame('http', parse_url($payload, PHP_URL_SCHEME));
        $this->assertSame('localhost', parse_url($payload, PHP_URL_HOST));
        $this->assertSame(5173, parse_url($payload, PHP_URL_PORT));
        $this->assertSame("/ticket/{$ticketToken}", parse_url($payload, PHP_URL_PATH));
        $this->assertSame($first['registration']['e_ticket']['url'], $payload);
        $this->assertStringContainsString($ticketToken, $payload);
        $this->assertStringNotContainsString('QR Identity One', $payload);
        $this->assertStringNotContainsString('participant@example.test', $payload);
        $this->assertStringNotContainsString('+6281271000001', $payload);
        $this->assertStringNotContainsString($firstRegistration->registration_number, $payload);
        $this->assertStringNotContainsString($firstRegistration->id, $payload);

        $beforeHash = $firstRegistration->qr_token_hash;
        $beforeEncryptedToken = $firstRegistration->qr_token_encrypted;
        $firstRender = $renderer->render($firstRegistration);
        $secondRender = $renderer->render($firstRegistration->fresh());

        $this->assertSame('image/svg+xml', $firstRender['mime_type']);
        $this->assertStringStartsWith('data:image/svg+xml;base64,', $firstRender['data_uri']);
        $this->assertStringContainsString('<svg', $firstRender['svg']);
        $this->assertSame($payload, $renderer->payload($firstRegistration->fresh()));
        $this->assertSame($firstRender['data_uri'], $secondRender['data_uri']);
        $this->assertSame($beforeHash, $firstRegistration->fresh()->qr_token_hash);
        $this->assertSame($beforeEncryptedToken, $firstRegistration->fresh()->qr_token_encrypted);

        $tokenFromUrl = basename((string) parse_url($payload, PHP_URL_PATH));
        $this->getJson($this->ticketPath($tokenFromUrl))
            ->assertOk()
            ->assertJsonPath('data.participant.full_name', 'QR Identity One')
            ->assertJsonPath('data.registration_number', $firstRegistration->registration_number)
            ->assertJsonPath('data.qr_code.url', $payload);
    }

    public function test_idempotent_retry_and_duplicate_whatsapp_never_create_another_qr_identity(): void
    {
        $key = (string) Str::uuid();
        $first = $this->register('0812 7200 0001', key: $key);
        $registration = Registration::firstOrFail();
        $qrHash = $registration->qr_token_hash;
        $ticketHash = $registration->ticket_access_token_hash;

        $retry = $this->register('0812 7200 0001', key: $key, expectedStatus: 200);

        $this->assertSame($first['registration']['registration_number'], $retry['registration']['registration_number']);
        $this->assertSame(
            $first['registration']['e_ticket']['access_token'],
            $retry['registration']['e_ticket']['access_token']
        );
        $this->assertSame(
            $first['registration']['e_ticket']['url'],
            $retry['registration']['e_ticket']['url']
        );
        $this->assertSame($qrHash, $registration->fresh()->qr_token_hash);
        $this->assertSame($ticketHash, $registration->fresh()->ticket_access_token_hash);

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->registrationPath(), $this->payload('0812 7200 0001', 'Duplicate'))
            ->assertUnprocessable();

        $this->assertDatabaseCount('registrations', 1);
    }

    public function test_e_ticket_displays_registration_event_confirmed_and_waitlisted_talkshows(): void
    {
        $talkshows = $this->event->talkshows()->take(2)->get();
        $waitlistTalkshow = $talkshows[0];
        $confirmedTalkshow = $talkshows[1];
        $waitlistTalkshow->update(['capacity' => 1, 'waitlist_enabled' => true]);
        $confirmedTalkshow->update(['capacity' => 10, 'waitlist_enabled' => true]);

        $this->register('0812 7300 0001', 'Capacity Filler', [$waitlistTalkshow->id]);
        $target = $this->register(
            '0812 7300 0002',
            'Ticket Participant',
            [$waitlistTalkshow->id, $confirmedTalkshow->id]
        );

        $token = $target['registration']['e_ticket']['access_token'];
        $response = $this->getJson($this->ticketPath($token));

        $response
            ->assertOk()
            ->assertJsonPath('data.participant.full_name', 'Ticket Participant')
            ->assertJsonPath(
                'data.registration_number',
                $target['registration']['registration_number']
            )
            ->assertJsonPath('data.event.name', 'ProBuild INTIM 2026')
            ->assertJsonPath('data.talkshows.confirmed.0.code', $confirmedTalkshow->code)
            ->assertJsonPath('data.talkshows.waitlisted.0.code', $waitlistTalkshow->code)
            ->assertJsonPath('data.talkshows.waitlisted.0.waitlist_position', 1)
            ->assertJsonPath('data.check_in.overall_status', 'not_checked_in')
            ->assertJsonCount(4, 'data.check_in.event_days');

        $body = $response->getContent();
        $this->assertStringNotContainsString('participant@example.test', $body);
        $this->assertStringNotContainsString('+6281273000002', $body);
        $this->assertStringNotContainsString('PBI:QR:V1:', $body);
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
        $this->assertSame('noindex, nofollow, noarchive', $response->headers->get('X-Robots-Tag'));
    }

    public function test_e_ticket_reads_current_daily_checkin_status_from_database(): void
    {
        $created = $this->register('0812 7400 0001');
        $registration = Registration::findOrFail($created['registration']['id']);
        $token = $created['registration']['e_ticket']['access_token'];
        $eventDays = $this->event->days()->get();
        $actor = User::factory()->create();

        $this->getJson($this->ticketPath($token))
            ->assertOk()
            ->assertJsonPath('data.check_in.overall_status', 'not_checked_in')
            ->assertJsonPath('data.check_in.event_days.0.status', 'not_checked_in');

        DailyEventCheckin::create([
            'registration_id' => $registration->id,
            'event_day_id' => $eventDays[0]->id,
            'checked_in_by_user_id' => $actor->id,
            'checked_in_at' => now(),
        ]);

        $this->getJson($this->ticketPath($token))
            ->assertOk()
            ->assertJsonPath('data.check_in.overall_status', 'partially_checked_in')
            ->assertJsonPath('data.check_in.event_days.0.status', 'checked_in')
            ->assertJsonPath('data.check_in.event_days.1.status', 'not_checked_in');

        foreach ($eventDays->skip(1) as $eventDay) {
            DailyEventCheckin::create([
                'registration_id' => $registration->id,
                'event_day_id' => $eventDay->id,
                'checked_in_by_user_id' => $actor->id,
                'checked_in_at' => now(),
            ]);
        }

        $this->getJson($this->ticketPath($token))
            ->assertOk()
            ->assertJsonPath('data.check_in.overall_status', 'checked_in');
    }

    public function test_invalid_e_ticket_identifier_is_rejected_without_internal_details(): void
    {
        $this->getJson($this->ticketPath(str_repeat('a', 64)))
            ->assertNotFound()
            ->assertJsonPath('message', 'E-ticket tidak ditemukan atau tautan tidak valid.')
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');

        $this->getJson('/api/v1/public/e-tickets/not-a-valid-token')
            ->assertNotFound()
            ->assertJsonMissingPath('exception');
    }

    public function test_pdf_download_is_valid_compact_and_does_not_change_registration_identity(): void
    {
        $talkshowIds = $this->event->talkshows()->take(2)->pluck('id')->all();
        $created = $this->register('0812 7500 0001', 'PDF Participant', $talkshowIds);
        $registration = Registration::findOrFail($created['registration']['id']);
        $token = $created['registration']['e_ticket']['access_token'];
        $qrHash = $registration->qr_token_hash;
        $ticketHash = $registration->ticket_access_token_hash;

        $first = $this->get($this->pdfPath($token));
        $second = $this->get($this->pdfPath($token));

        $first->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString(
            'attachment; filename="e-ticket-'.$registration->registration_number.'.pdf"',
            (string) $first->headers->get('Content-Disposition')
        );
        $this->assertStringStartsWith('%PDF-', $first->getContent());
        $this->assertLessThan(1_000_000, strlen($first->getContent()));
        $this->assertStringStartsWith('%PDF-', $second->getContent());
        $this->assertDatabaseCount('registrations', 1);
        $this->assertSame($qrHash, $registration->fresh()->qr_token_hash);
        $this->assertSame($ticketHash, $registration->fresh()->ticket_access_token_hash);
    }

    public function test_corrupt_qr_identity_returns_a_safe_user_facing_error(): void
    {
        $created = $this->register('0812 7600 0001');
        $registration = Registration::findOrFail($created['registration']['id']);
        $registration->update(['qr_token_encrypted' => 'corrupt-encrypted-value']);

        $this->getJson($this->ticketPath($created['registration']['e_ticket']['access_token']))
            ->assertStatus(503)
            ->assertJsonPath('message', 'Identitas QR Code tidak dapat dibaca.')
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace');
    }

    public function test_phase_two_migration_backfills_secure_access_for_existing_registrations(): void
    {
        $created = $this->register('0812 7700 0001', 'Existing Phase One Participant');
        $registrationId = $created['registration']['id'];
        $migration = require database_path(
            'migrations/2026_08_16_000400_add_e_ticket_access_to_registrations.php'
        );

        $migration->down();
        $this->assertFalse(Schema::hasColumn('registrations', 'ticket_access_token_hash'));

        $migration->up();
        $registration = Registration::findOrFail($registrationId);

        $this->assertNotNull($registration->ticket_access_token_hash);
        $this->assertNotNull($registration->ticket_access_token_encrypted);
        $this->assertSame(64, strlen($registration->ticket_access_token_hash));
        $this->assertSame($registration->qr_token_hash, $registration->ticket_access_token_hash);
        $this->assertSame(
            $registration->qr_token_encrypted,
            $registration->ticket_access_token_encrypted
        );

        $rawToken = app(ETicketAccessService::class)->rawToken($registration);
        $this->assertSame($registration->id, app(ETicketAccessService::class)->resolve($rawToken)->id);
    }

    public function test_identity_unification_migration_aligns_existing_phase_two_registration(): void
    {
        $created = $this->register('0812 7800 0001', 'Legacy Phase Two Participant');
        $registration = Registration::findOrFail($created['registration']['id']);
        $legacyTicketToken = bin2hex(random_bytes(32));

        $registration->update([
            'ticket_access_token_hash' => hash('sha256', $legacyTicketToken),
            'ticket_access_token_encrypted' => Crypt::encryptString($legacyTicketToken),
        ]);

        $migration = require database_path(
            'migrations/2026_08_16_000500_unify_qr_and_e_ticket_identity.php'
        );
        $migration->up();
        $registration->refresh();

        $this->assertSame($registration->qr_token_hash, $registration->ticket_access_token_hash);
        $this->assertSame(
            $registration->qr_token_encrypted,
            $registration->ticket_access_token_encrypted
        );

        $token = app(ETicketAccessService::class)->rawToken($registration);
        $this->assertSame($legacyTicketToken, $token);
        $this->assertSame($registration->id, app(ETicketAccessService::class)->resolve($token)->id);
        $this->assertSame(
            "http://localhost:5173/ticket/{$token}",
            app(QrCodeRenderer::class)->payload($registration)
        );
    }

    /**
     * @param  array<int, string>  $talkshowIds
     * @return array<string, mixed>
     */
    private function register(
        string $whatsapp,
        string $fullName = 'E-Ticket Participant',
        array $talkshowIds = [],
        ?string $key = null,
        int $expectedStatus = 201,
    ): array {
        $response = $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson($this->registrationPath(), $this->payload($whatsapp, $fullName, $talkshowIds));

        $response->assertStatus($expectedStatus);

        return $response->json('data');
    }

    /**
     * @param  array<int, string>  $talkshowIds
     * @return array<string, mixed>
     */
    private function payload(string $whatsapp, string $fullName, array $talkshowIds = []): array
    {
        return [
            'full_name' => $fullName,
            'whatsapp' => $whatsapp,
            'email' => 'participant@example.test',
            'talkshow_ids' => $talkshowIds,
        ];
    }

    private function registrationPath(): string
    {
        return "/api/v1/public/events/{$this->event->slug}/registrations";
    }

    private function ticketPath(string $token): string
    {
        return "/api/v1/public/e-tickets/{$token}";
    }

    private function pdfPath(string $token): string
    {
        return $this->ticketPath($token).'/pdf';
    }
}
