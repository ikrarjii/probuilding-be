<?php

namespace Tests\Feature;

use App\Contracts\Notifications\WhatsAppProvider;
use App\Data\ProviderDeliveryResult;
use App\Data\WhatsAppNotification;
use App\Exceptions\NotificationDeliveryException;
use App\Models\Event;
use App\Models\Registration;
use App\Models\TicketDelivery;
use App\Services\Notifications\CreateRegistrationNotifications;
use App\Services\Notifications\NotificationOutboxProcessor;
use App\Services\Notifications\RetryTicketDelivery;
use App\Services\Tickets\QrCodeRenderer;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class WhatsAppDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-16 10:00:00', 'Asia/Makassar'));
        config([
            'notifications.whatsapp.driver' => 'mock',
            'notifications.whatsapp.mock_failure' => false,
            'notifications.whatsapp.mock_log_channel' => 'whatsapp_mock',
            'notifications.outbox.retry_base_minutes' => 1,
        ]);

        $this->seed(DatabaseSeeder::class);
        $this->event = Event::where('slug', 'probuild-intim-2026')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_registration_creates_one_pending_whatsapp_notification_with_normalized_recipient(): void
    {
        $created = $this->register(whatsapp: '0812 9100 0001');

        $this->assertDatabaseCount('registrations', 1);
        $this->assertDatabaseCount('ticket_deliveries', 1);
        $this->assertDatabaseHas('ticket_deliveries', [
            'registration_id' => $created['registration']['id'],
            'channel' => 'whatsapp',
            'recipient_reference' => '+6281291000001',
            'notification_type' => 'registration_confirmation',
            'status' => 'pending',
            'attempts' => 0,
        ]);
        $this->assertSame(
            '+6281291000001',
            $created['registration']['participant']['whatsapp']
        );
        $this->assertDatabaseCount('outbox_messages', 2);
    }

    public function test_whatsapp_contains_correct_participant_registration_recipient_and_ticket_url(): void
    {
        $talkshowIds = $this->event->talkshows()->take(2)->pluck('id')->all();
        $created = $this->register(
            whatsapp: '0812 9200 0001',
            fullName: 'WhatsApp Participant',
            talkshowIds: $talkshowIds,
        );
        $provider = new RecordingWhatsAppProvider;
        $this->app->instance(WhatsAppProvider::class, $provider);

        $result = app(NotificationOutboxProcessor::class)->processBatch();
        $message = $provider->notifications[0];
        $ticketUrl = $created['registration']['e_ticket']['url'];
        $token = $created['registration']['e_ticket']['access_token'];

        $this->assertSame(['processed' => 1, 'sent' => 1, 'failed' => 0], $result);
        $this->assertSame('WhatsApp Participant', $message->confirmation->participantName);
        $this->assertSame(
            $created['registration']['registration_number'],
            $message->confirmation->registrationNumber
        );
        $this->assertSame('+6281292000001', $message->confirmation->whatsapp);
        $this->assertSame($ticketUrl, $message->confirmation->ticketUrl);
        $this->assertStringContainsString('Registrasi Berhasil!', $message->body);
        $this->assertStringContainsString('WhatsApp Participant', $message->body);
        $this->assertStringContainsString($created['registration']['registration_number'], $message->body);
        $this->assertStringContainsString($ticketUrl, $message->body);
        $this->assertDatabaseHas('ticket_deliveries', [
            'registration_id' => $created['registration']['id'],
            'provider' => 'test-whatsapp',
            'status' => 'sent',
            'attempts' => 1,
        ]);

        $this->getJson($this->ticketPath($token))
            ->assertOk()
            ->assertJsonPath('data.participant.full_name', 'WhatsApp Participant')
            ->assertJsonPath(
                'data.registration_number',
                $created['registration']['registration_number']
            );
    }

    public function test_registration_keeps_the_same_ticket_url_and_qr_without_exposing_pii(): void
    {
        $created = $this->register(whatsapp: '0812 9300 0001', fullName: 'Persistent QR Participant');
        $registration = Registration::findOrFail($created['registration']['id']);
        $renderer = app(QrCodeRenderer::class);
        $qrHash = $registration->qr_token_hash;
        $ticketHash = $registration->ticket_access_token_hash;
        $firstUrl = $renderer->payload($registration);
        $firstQr = $renderer->render($registration)['data_uri'];
        $provider = new RecordingWhatsAppProvider;
        $this->app->instance(WhatsAppProvider::class, $provider);

        app(NotificationOutboxProcessor::class)->processBatch();
        app(NotificationOutboxProcessor::class)->processBatch();

        $registration->refresh();
        $secondUrl = $renderer->payload($registration);
        $secondQr = $renderer->render($registration)['data_uri'];

        $this->assertSame($created['registration']['e_ticket']['url'], $firstUrl);
        $this->assertSame($firstUrl, $provider->notifications[0]->confirmation->ticketUrl);
        $this->assertSame($firstUrl, $secondUrl);
        $this->assertSame($firstQr, $secondQr);
        $this->assertSame($qrHash, $registration->qr_token_hash);
        $this->assertSame($ticketHash, $registration->ticket_access_token_hash);
        $this->assertStringNotContainsString('Persistent QR Participant', $firstUrl);
        $this->assertStringNotContainsString('+6281293000001', $firstUrl);
        $this->assertStringNotContainsString('whatsapp@example.test', $firstUrl);
        $this->assertCount(1, $provider->notifications);
    }

    public function test_whatsapp_failure_does_not_cancel_registration(): void
    {
        $created = $this->register(whatsapp: '0812 9400 0001');
        $this->app->instance(
            WhatsAppProvider::class,
            new RecordingWhatsAppProvider(failuresRemaining: 1),
        );

        $result = app(NotificationOutboxProcessor::class)->processBatch();

        $this->assertSame(['processed' => 1, 'sent' => 0, 'failed' => 1], $result);
        $this->assertDatabaseHas('registrations', ['id' => $created['registration']['id']]);
        $this->assertDatabaseHas('ticket_deliveries', [
            'registration_id' => $created['registration']['id'],
            'channel' => 'whatsapp',
            'status' => 'failed',
            'attempts' => 1,
            'last_error' => 'Test WhatsApp provider unavailable.',
        ]);
        $this->getJson($this->ticketPath($created['registration']['e_ticket']['access_token']))
            ->assertOk()
            ->assertJsonPath(
                'data.registration_number',
                $created['registration']['registration_number']
            );
    }

    public function test_failed_whatsapp_can_be_retried_with_the_same_idempotency_key(): void
    {
        $created = $this->register(whatsapp: '0812 9500 0001');
        $failedProvider = new RecordingWhatsAppProvider(failuresRemaining: 1);
        $this->app->instance(WhatsAppProvider::class, $failedProvider);
        app(NotificationOutboxProcessor::class)->processBatch();

        $delivery = TicketDelivery::firstOrFail();
        $idempotencyKey = $delivery->idempotency_key;
        $successfulProvider = new RecordingWhatsAppProvider;
        $this->app->instance(WhatsAppProvider::class, $successfulProvider);

        app(RetryTicketDelivery::class)->handle($delivery);
        $result = app(NotificationOutboxProcessor::class)->processBatch();

        $delivery->refresh();
        $this->assertSame(['processed' => 1, 'sent' => 1, 'failed' => 0], $result);
        $this->assertSame('sent', $delivery->status->value);
        $this->assertSame(2, $delivery->attempts);
        $this->assertSame($idempotencyKey, $delivery->idempotency_key);
        $this->assertSame($idempotencyKey, $successfulProvider->notifications[0]->idempotencyKey);
        $this->assertSame(
            $created['registration']['e_ticket']['url'],
            $successfulProvider->notifications[0]->confirmation->ticketUrl
        );
    }

    public function test_idempotent_and_duplicate_requests_do_not_create_or_send_duplicate_whatsapp(): void
    {
        $key = (string) Str::uuid();
        $created = $this->register(whatsapp: '0812 9600 0001', key: $key);
        $registration = Registration::findOrFail($created['registration']['id']);
        app(CreateRegistrationNotifications::class)->handle($registration);

        $this->postRegistration('0812 9600 0001', 'WhatsApp Participant', [], $key)
            ->assertOk()
            ->assertJsonPath('data.idempotent_replay', true);
        $this->postRegistration(
            '0812 9600 0001',
            'Duplicate WhatsApp Participant',
            [],
            (string) Str::uuid(),
        )->assertUnprocessable();

        $provider = new RecordingWhatsAppProvider;
        $this->app->instance(WhatsAppProvider::class, $provider);
        $first = app(NotificationOutboxProcessor::class)->processBatch();
        $second = app(NotificationOutboxProcessor::class)->processBatch();

        $this->assertDatabaseCount('registrations', 1);
        $this->assertDatabaseCount('ticket_deliveries', 1);
        $this->assertSame(1, $first['sent']);
        $this->assertSame(0, $second['processed']);
        $this->assertCount(1, $provider->notifications);
    }

    public function test_mock_provider_logs_recipient_message_and_ticket_data_without_real_delivery(): void
    {
        $logPath = storage_path('logs/whatsapp-mock-test.log');
        File::delete($logPath);
        Log::forgetChannel('whatsapp_mock');
        config(['logging.channels.whatsapp_mock.path' => $logPath]);

        $created = $this->register(
            whatsapp: '0812 9700 0001',
            fullName: 'Mock Output Participant',
        );

        try {
            $result = app(NotificationOutboxProcessor::class)->processBatch();
            $log = File::get($logPath);

            $this->assertSame(['processed' => 1, 'sent' => 1, 'failed' => 0], $result);
            $this->assertStringContainsString('+6281297000001', $log);
            $this->assertStringContainsString('Mock Output Participant', $log);
            $this->assertStringContainsString(
                $created['registration']['registration_number'],
                $log
            );
            $this->assertStringContainsString($created['registration']['e_ticket']['url'], $log);
            $this->assertStringContainsString('Registrasi Berhasil!', $log);
            $this->assertDatabaseHas('ticket_deliveries', [
                'registration_id' => $created['registration']['id'],
                'provider' => 'mock-whatsapp',
                'status' => 'sent',
            ]);
        } finally {
            Log::forgetChannel('whatsapp_mock');
            File::delete($logPath);
        }
    }

    public function test_invalid_whatsapp_creates_no_registration_or_delivery(): void
    {
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->registrationPath(), [
                'full_name' => 'Invalid WhatsApp',
                'whatsapp' => '123',
                'email' => 'whatsapp@example.test',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('whatsapp');

        $this->assertDatabaseCount('registrations', 0);
        $this->assertDatabaseCount('ticket_deliveries', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_whatsapp_credentials_are_not_present_in_frontend_or_public_response(): void
    {
        $secret = 'whatsapp-production-secret-that-must-not-leak';
        config(['notifications.whatsapp.access_token' => $secret]);

        $created = $this->register(whatsapp: '0812 9800 0001');

        $this->assertStringNotContainsString($secret, json_encode($created, JSON_THROW_ON_ERROR));

        foreach (File::allFiles(base_path('../src')) as $file) {
            $this->assertStringNotContainsString($secret, $file->getContents());
        }
    }

    /**
     * @param  array<int, string>  $talkshowIds
     * @return array<string, mixed>
     */
    private function register(
        string $whatsapp = '0812 9000 0001',
        string $fullName = 'WhatsApp Participant',
        array $talkshowIds = [],
        ?string $key = null,
    ): array {
        return $this->postRegistration($whatsapp, $fullName, $talkshowIds, $key)
            ->assertCreated()
            ->json('data');
    }

    /**
     * @param  array<int, string>  $talkshowIds
     */
    private function postRegistration(
        string $whatsapp,
        string $fullName,
        array $talkshowIds,
        ?string $key = null,
    ) {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())
            ->postJson($this->registrationPath(), [
                'full_name' => $fullName,
                'whatsapp' => $whatsapp,
                'email' => 'whatsapp@example.test',
                'talkshow_ids' => $talkshowIds,
            ]);
    }

    private function registrationPath(): string
    {
        return "/api/v1/public/events/{$this->event->slug}/registrations";
    }

    private function ticketPath(string $token): string
    {
        return "/api/v1/public/e-tickets/{$token}";
    }
}

class RecordingWhatsAppProvider implements WhatsAppProvider
{
    /** @var array<int, WhatsAppNotification> */
    public array $notifications = [];

    public function __construct(private int $failuresRemaining = 0) {}

    public function send(WhatsAppNotification $notification): ProviderDeliveryResult
    {
        $this->notifications[] = $notification;

        if ($this->failuresRemaining-- > 0) {
            throw new NotificationDeliveryException('Test WhatsApp provider unavailable.');
        }

        return new ProviderDeliveryResult('test-whatsapp', 'whatsapp-message-id');
    }
}
