<?php

namespace Tests\Feature;

use App\Enums\TalkshowSelectionStatus;
use App\Models\AuditLog;
use App\Models\DailyEventCheckin;
use App\Models\Event;
use App\Models\Registration;
use App\Models\RegistrationTalkshow;
use App\Models\Role;
use App\Models\User;
use App\Services\Attendance\RecordDailyEventCheckin;
use App\Services\Attendance\RecordTalkshowAttendance;
use App\Services\Talkshows\PromoteWaitlistedSelection;
use Carbon\Carbon;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AttendanceAndWaitlistRulesTest extends TestCase
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

    public function test_waitlist_promotion_is_manual_and_audited(): void
    {
        $talkshow = $this->event->talkshows()->firstOrFail();
        $talkshow->update(['capacity' => 1, 'waitlist_enabled' => true]);

        $first = $this->register('0812 1000 0001', [$talkshow->id]);
        $second = $this->register('0812 1000 0002', [$talkshow->id]);
        $confirmed = RegistrationTalkshow::where('registration_id', $first->id)->firstOrFail();
        $waitlisted = RegistrationTalkshow::where('registration_id', $second->id)->firstOrFail();

        $this->assertSame(TalkshowSelectionStatus::Waitlisted, $waitlisted->status);
        $confirmed->update([
            'status' => TalkshowSelectionStatus::Cancelled,
            'cancelled_at' => now(),
        ]);

        $this->assertSame(
            TalkshowSelectionStatus::Waitlisted,
            $waitlisted->fresh()->status,
            'A freed seat must not automatically promote a waitlisted participant.'
        );

        $superAdmin = $this->userWithRole('super_admin');
        $promoted = app(PromoteWaitlistedSelection::class)->handle(
            $waitlisted,
            $superAdmin,
            'Seat released after a participant cancellation.'
        );

        $this->assertSame(TalkshowSelectionStatus::Confirmed, $promoted->status);
        $this->assertSame($superAdmin->id, $promoted->promoted_by_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'talkshow.waitlist.promoted',
            'actor_user_id' => $superAdmin->id,
            'subject_id' => $promoted->id,
        ]);
        $this->assertSame($second->participant_id, AuditLog::latest('created_at')->firstOrFail()->metadata['participant_id']);
    }

    public function test_only_assigned_panitia_or_super_admin_can_promote_a_waitlist_entry(): void
    {
        $talkshow = $this->event->talkshows()->firstOrFail();
        $talkshow->update(['capacity' => 0, 'waitlist_enabled' => true]);
        $registration = $this->register('0812 2000 0001', [$talkshow->id]);
        $selection = RegistrationTalkshow::where('registration_id', $registration->id)->firstOrFail();
        $unassignedPanitia = $this->userWithRole('panitia');

        $this->expectException(AuthorizationException::class);
        app(PromoteWaitlistedSelection::class)->handle($selection, $unassignedPanitia, 'Operational reason');
    }

    public function test_one_registration_can_check_in_once_per_day_but_on_multiple_days(): void
    {
        $registration = $this->register('0812 3000 0001');
        $days = $this->event->days()->take(2)->get();
        $panitia = $this->assignedPanitia();

        foreach ($days as $day) {
            app(RecordDailyEventCheckin::class)->handle($registration, $day, $panitia);
        }

        $this->assertDatabaseCount('daily_event_checkins', 2);

        $this->expectException(QueryException::class);
        DailyEventCheckin::create([
            'registration_id' => $registration->id,
            'event_day_id' => $days[0]->id,
            'checked_in_by_user_id' => $panitia->id,
            'checked_in_at' => now(),
        ]);
    }

    public function test_talkshow_attendance_requires_that_days_main_checkin_and_is_idempotent(): void
    {
        $talkshow = $this->event->talkshows()->firstOrFail();
        $registration = $this->register('0812 4000 0001', [$talkshow->id]);
        $panitia = $this->assignedPanitia();
        $service = app(RecordTalkshowAttendance::class);

        try {
            $service->handle($registration, $talkshow, $panitia);
            $this->fail('Attendance should require a daily main event check-in.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('check_in', $exception->errors());
        }

        DailyEventCheckin::create([
            'registration_id' => $registration->id,
            'event_day_id' => $talkshow->event_day_id,
            'checked_in_by_user_id' => $panitia->id,
            'checked_in_at' => now(),
        ]);

        $first = $service->handle($registration, $talkshow, $panitia);
        $second = $service->handle($registration, $talkshow, $panitia);

        $this->assertSame($first->id, $second->id);
        $this->assertFalse($first->prerequisite_overridden);
        $this->assertDatabaseCount('talkshow_attendances', 1);
    }

    public function test_super_admin_can_override_daily_checkin_with_audited_reason(): void
    {
        $talkshow = $this->event->talkshows()->firstOrFail();
        $registration = $this->register('0812 5000 0001', [$talkshow->id]);
        $superAdmin = $this->userWithRole('super_admin');

        $attendance = app(RecordTalkshowAttendance::class)->handle(
            $registration,
            $talkshow,
            $superAdmin,
            true,
            'Entrance device was temporarily unavailable.'
        );

        $this->assertTrue($attendance->prerequisite_overridden);
        $this->assertSame($superAdmin->id, $attendance->overridden_by_user_id);
        $audit = AuditLog::where('action', 'talkshow.attendance.checkin_prerequisite_overridden')->firstOrFail();
        $this->assertSame($registration->participant_id, $audit->metadata['participant_id']);
        $this->assertSame($talkshow->event_day_id, $audit->metadata['event_day_id']);
        $this->assertSame('Entrance device was temporarily unavailable.', $audit->metadata['reason']);
    }

    public function test_panitia_cannot_override_the_daily_checkin_prerequisite(): void
    {
        $talkshow = $this->event->talkshows()->firstOrFail();
        $registration = $this->register('0812 6000 0001', [$talkshow->id]);
        $panitia = $this->assignedPanitia();

        $this->expectException(AuthorizationException::class);
        app(RecordTalkshowAttendance::class)->handle(
            $registration,
            $talkshow,
            $panitia,
            true,
            'Attempted operational override.'
        );
    }

    private function register(string $whatsapp, array $talkshowIds = []): Registration
    {
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/public/events/{$this->event->slug}/registrations", [
                'full_name' => "Participant {$whatsapp}",
                'whatsapp' => $whatsapp,
                'email' => 'shared@example.test',
                'talkshow_ids' => $talkshowIds,
            ])
            ->assertCreated();

        return Registration::where('whatsapp_e164', preg_replace('/^0/', '+62', str_replace(' ', '', $whatsapp)))
            ->firstOrFail();
    }

    private function userWithRole(string $roleSlug): User
    {
        $user = User::factory()->create();
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user->roles()->attach($role->id);

        return $user;
    }

    private function assignedPanitia(): User
    {
        $user = $this->userWithRole('panitia');
        $role = Role::where('slug', 'panitia')->firstOrFail();

        DB::table('event_user_assignments')->insert([
            'id' => (string) Str::uuid(),
            'event_id' => $this->event->id,
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $user;
    }
}
