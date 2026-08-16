<?php

namespace Tests\Feature;

use App\Contracts\Notifications\EmailProvider;
use App\Contracts\Notifications\WhatsAppProvider;
use App\Data\EmailNotification;
use App\Data\ProviderDeliveryResult;
use App\Data\WhatsAppNotification;
use App\Exceptions\NotificationDeliveryException;
use App\Mail\RegistrationConfirmationMail;
use App\Models\Event;
use App\Models\Registration;
use App\Models\TicketDelivery;
use App\Services\Notifications\CreateRegistrationNotifications;
use App\Services\Notifications\LaravelMailEmailProvider;
use App\Services\Notifications\NotificationOutboxProcessor;
use App\Services\Notifications\RetryTicketDelivery;
use App\Services\Tickets\QrCodeRenderer;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-08-16 10:00:00', 'Asia/Makassar'));
        config([
            'notifications.email.driver' => 'mock',
            'notifications.email.attach_pdf' => false,
            'notifications.email.mock_failure' => false,
            'notifications.whatsapp.driver' => 'mock',
            'notifications.whatsapp.mock_failure' => false,
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

    public function test_successful_registration_creates_separate_email_and_whatsapp_notifications(): void
    {
        $created = $this->register();
        $registrationId = $created['registration']['id'];

        $this->assertDatabaseHas('ticket_deliveries', [
            'registration_id' => $registrationId,
            'channel' => 'email',
            'notification_type' => 'registration_confirmation',
            'status' => 'pending',
            'attempts' => 0,
        ]);
        $this->assertDatabaseHas('ticket_deliveries', [
            'registration_id' => $registrationId,
            'channel' => 'whatsapp',
            'notification_type' => 'registration_confirmation',
            'status' => 'pending',
            'attempts' => 0,
        ]);
        $this->assertSame(2, TicketDelivery::where('registration_id', $registrationId)->count());
        $this->assertDatabaseCount('outbox_messages', 3);
    }

    public function test_email_failure_does_not_invalidate_registration_and_is_recorded_safely(): void
    {
        $created = $this->register(whatsapp: '0812 8100 0001');
        $email = new RecordingEmailProvider(failuresRemaining: 1);
        $whatsApp = new RecordingWhatsAppProvider;
        $this->bindProviders($email, $whatsApp);

        $result = app(NotificationOutboxProcessor::class)->processBatch();

        $this->assertDatabaseHas('registrations', ['id' => $created['registration']['id']]);
        $this->assertDatabaseHas('ticket_deliveries', [
            'registration_id' => $created['registration']['id'],
            'channel' => 'email',
            'status' => 'failed',
            'attempts' => 1,
            'last_error' => 'Test provider unavailable.',
        ]);
        $this->assertDatabaseHas('ticket_deliveries', [
            'registration_id' => $created['registration']['id'],
            'channel' => 'whatsapp',
            'status' => 'sent',
        ]);
        $this->assertSame(['processed' => 2, 'sent' => 1, 'failed' => 1], $result);
    }

    public function test_whatsapp_failure_does_not_invalidate_registration_and_is_recorded_safely(): void
    {
        $created = $this->register(whatsapp: '0812 8100 0002');
        $email = new RecordingEmailProvider;
        $whatsApp = new RecordingWhatsAppProvider(failuresRemaining: 1);
        $this->bindProviders($email, $whatsApp);

        app(NotificationOutboxProcessor::class)->processBatch();

        $this->assertDatabaseCount('registrations', 1);
        $this->assertDatabaseHas('ticket_deliveries', [
            'registration_id' => $created['registration']['id'],
            'channel' => 'email',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('ticket_deliveries', [
            'registration_id' => $created['registration']['id'],
            'channel' => 'whatsapp',
            'status' => 'failed',
            'attempts' => 1,
            'last_error' => 'Test provider unavailable.',
        ]);
    }

    public function test_successful_deliveries_use_the_same_ticket_url_and_keep_qr_identity(): void
    {
        $created = $this->register(whatsapp: '0812 8200 0001');
        $registration = Registration::findOrFail($created['registration']['id']);
        $qrHash = $registration->qr_token_hash;
        $ticketHash = $registration->ticket_access_token_hash;
        $email = new RecordingEmailProvider;
        $whatsApp = new RecordingWhatsAppProvider;
        $this->bindProviders($email, $whatsApp);

        app(NotificationOutboxProcessor::class)->processBatch();

        $emailMessage = $email->notifications[0];
        $whatsAppMessage = $whatsApp->notifications[0];
        $ticketUrl = $created['registration']['e_ticket']['url'];

        $this->assertSame($ticketUrl, $emailMessage->confirmation->ticketUrl);
        $this->assertSame($ticketUrl, $whatsAppMessage->confirmation->ticketUrl);
        $this->assertSame($ticketUrl, app(QrCodeRenderer::class)->payload($registration->fresh()));
        $this->assertStringContainsString($ticketUrl, $whatsAppMessage->body);
        $this->assertSame($qrHash, $registration->fresh()->qr_token_hash);
        $this->assertSame($ticketHash, $registration->fresh()->ticket_access_token_hash);
        $this->assertSame(2, TicketDelivery::where('status', 'sent')->count());
    }

    public function test_failed_delivery_can_be_retried_without_changing_its_idempotency_key(): void
    {
        $created = $this->register(whatsapp: '0812 8300 0001');
        $failingEmail = new RecordingEmailProvider(failuresRemaining: 1);
        $this->bindProviders($failingEmail, new RecordingWhatsAppProvider);
        app(NotificationOutboxProcessor::class)->processBatch();

        $delivery = TicketDelivery::where('registration_id', $created['registration']['id'])
            ->where('channel', 'email')
            ->firstOrFail();
        $idempotencyKey = $delivery->idempotency_key;
        $successfulEmail = new RecordingEmailProvider;
        $this->app->instance(EmailProvider::class, $successfulEmail);

        app(RetryTicketDelivery::class)->handle($delivery);
        $result = app(NotificationOutboxProcessor::class)->processBatch();

        $delivery->refresh();
        $this->assertSame('sent', $delivery->status->value);
        $this->assertSame(2, $delivery->attempts);
        $this->assertSame($idempotencyKey, $delivery->idempotency_key);
        $this->assertSame($idempotencyKey, $successfulEmail->notifications[0]->idempotencyKey);
        $this->assertSame(1, $result['sent']);
    }

    public function test_duplicate_delivery_creation_and_processing_do_not_send_twice(): void
    {
        $created = $this->register(whatsapp: '0812 8400 0001');
        $registration = Registration::findOrFail($created['registration']['id']);
        app(CreateRegistrationNotifications::class)->handle($registration);
        $email = new RecordingEmailProvider;
        $whatsApp = new RecordingWhatsAppProvider;
        $this->bindProviders($email, $whatsApp);

        $first = app(NotificationOutboxProcessor::class)->processBatch();
        $second = app(NotificationOutboxProcessor::class)->processBatch();

        $this->assertDatabaseCount('ticket_deliveries', 2);
        $this->assertSame(2, $first['sent']);
        $this->assertSame(0, $second['processed']);
        $this->assertCount(1, $email->notifications);
        $this->assertCount(1, $whatsApp->notifications);
    }

    public function test_waitlisted_and_confirmed_talkshows_are_in_both_channel_messages(): void
    {
        $talkshows = $this->event->talkshows()->take(2)->get();
        $waitlisted = $talkshows[0];
        $confirmed = $talkshows[1];
        $waitlisted->update(['capacity' => 1, 'waitlist_enabled' => true]);
        $confirmed->update(['capacity' => 5, 'waitlist_enabled' => true]);

        $this->register(
            whatsapp: '0812 8500 0001',
            fullName: 'Capacity Filler',
            talkshowIds: [$waitlisted->id],
        );
        $target = $this->register(
            whatsapp: '0812 8500 0002',
            fullName: 'Notification Participant',
            talkshowIds: [$waitlisted->id, $confirmed->id],
        );
        $email = new RecordingEmailProvider;
        $whatsApp = new RecordingWhatsAppProvider;
        $this->bindProviders($email, $whatsApp);

        app(NotificationOutboxProcessor::class)->processBatch();

        $emailMessage = collect($email->notifications)->first(
            fn (EmailNotification $message) => $message->confirmation->registrationId === $target['registration']['id']
        );
        $whatsAppMessage = collect($whatsApp->notifications)->first(
            fn (WhatsAppNotification $message) => $message->confirmation->registrationId === $target['registration']['id']
        );

        $this->assertSame($confirmed->title, $emailMessage->confirmation->confirmedTalkshows[0]['title']);
        $this->assertSame($waitlisted->title, $emailMessage->confirmation->waitlistedTalkshows[0]['title']);
        $this->assertStringContainsString($confirmed->title, $whatsAppMessage->body);
        $this->assertStringContainsString($waitlisted->title, $whatsAppMessage->body);
        $this->assertStringContainsString('waitlist', mb_strtolower($whatsAppMessage->body));
    }

    public function test_invalid_contact_values_create_no_registration_or_notifications(): void
    {
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->registrationPath(), [
                'full_name' => 'Invalid Contact',
                'whatsapp' => '123',
                'email' => 'not-an-email',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['whatsapp', 'email']);

        $this->assertDatabaseCount('registrations', 0);
        $this->assertDatabaseCount('ticket_deliveries', 0);
        $this->assertDatabaseCount('outbox_messages', 0);
    }

    public function test_mock_providers_send_nothing_external_and_update_delivery_status(): void
    {
        $this->register(whatsapp: '0812 8600 0001');

        $result = app(NotificationOutboxProcessor::class)->processBatch();

        $this->assertSame(['processed' => 2, 'sent' => 2, 'failed' => 0], $result);
        $this->assertDatabaseHas('ticket_deliveries', [
            'channel' => 'email',
            'provider' => 'mock-email',
            'status' => 'sent',
        ]);
        $this->assertDatabaseHas('ticket_deliveries', [
            'channel' => 'whatsapp',
            'provider' => 'mock-whatsapp',
            'status' => 'sent',
        ]);
    }

    public function test_laravel_mail_adapter_contains_view_ticket_link_and_existing_pdf(): void
    {
        config(['notifications.email.attach_pdf' => true]);
        $created = $this->register(whatsapp: '0812 8700 0001');
        Mail::fake();
        $this->app->instance(EmailProvider::class, new LaravelMailEmailProvider);
        $this->app->instance(WhatsAppProvider::class, new RecordingWhatsAppProvider);

        app(NotificationOutboxProcessor::class)->processBatch();

        Mail::assertSent(RegistrationConfirmationMail::class, function (RegistrationConfirmationMail $mail) use ($created) {
            $html = $mail->render();

            return str_contains($html, 'VIEW E-TICKET')
                && str_contains($html, $created['registration']['e_ticket']['url'])
                && str_starts_with((string) $mail->notification->pdfContent, '%PDF-')
                && $mail->notification->pdfFilename === 'e-ticket-'.$created['registration']['registration_number'].'.pdf';
        });
        $this->assertDatabaseHas('ticket_deliveries', [
            'registration_id' => $created['registration']['id'],
            'channel' => 'email',
            'status' => 'sent',
        ]);
    }

    public function test_server_provider_credentials_never_appear_in_frontend_or_registration_response(): void
    {
        $secret = 'phase3-provider-secret-that-must-not-leak';
        config([
            'notifications.email.api_key' => $secret,
            'notifications.whatsapp.access_token' => $secret,
        ]);

        $created = $this->register(whatsapp: '0812 8800 0001');

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
        string $whatsapp = '0812 8000 0001',
        string $fullName = 'Notification Participant',
        array $talkshowIds = [],
    ): array {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson($this->registrationPath(), [
                'full_name' => $fullName,
                'whatsapp' => $whatsapp,
                'email' => 'notification@example.test',
                'talkshow_ids' => $talkshowIds,
            ])
            ->assertCreated()
            ->json('data');
    }

    private function registrationPath(): string
    {
        return "/api/v1/public/events/{$this->event->slug}/registrations";
    }

    private function bindProviders(
        RecordingEmailProvider $email,
        RecordingWhatsAppProvider $whatsApp,
    ): void {
        $this->app->instance(EmailProvider::class, $email);
        $this->app->instance(WhatsAppProvider::class, $whatsApp);
    }
}

class RecordingEmailProvider implements EmailProvider
{
    /** @var array<int, EmailNotification> */
    public array $notifications = [];

    public function __construct(private int $failuresRemaining = 0) {}

    public function send(EmailNotification $notification): ProviderDeliveryResult
    {
        $this->notifications[] = $notification;

        if ($this->failuresRemaining-- > 0) {
            throw new NotificationDeliveryException('Test provider unavailable.');
        }

        return new ProviderDeliveryResult('test-email', 'email-message-id');
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
            throw new NotificationDeliveryException('Test provider unavailable.');
        }

        return new ProviderDeliveryResult('test-whatsapp', 'whatsapp-message-id');
    }
}
