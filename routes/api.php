<?php

use App\Http\Controllers\Api\V1\PublicETicketController;
use App\Http\Controllers\Api\V1\PublicEventRegistrationController;
use App\Http\Controllers\Api\V1\PublicRegistrationController;
use App\Http\Controllers\Api\V1\Staff\AuditLogController;
use App\Http\Controllers\Api\V1\Staff\AuthController;
use App\Http\Controllers\Api\V1\Staff\CheckinController;
use App\Http\Controllers\Api\V1\Staff\EventAssignmentController;
use App\Http\Controllers\Api\V1\Staff\EventController;
use App\Http\Controllers\Api\V1\Staff\ParticipantController;
use App\Http\Controllers\Api\V1\Staff\RegistrationTicketController;
use App\Http\Controllers\Api\V1\Staff\StatisticsController;
use App\Http\Controllers\Api\V1\Staff\TalkshowAttendanceController;
use App\Http\Controllers\Api\V1\Staff\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

$missingEvent = static function (Request $request) {
    Log::warning('Public registration event was not found.', [
        'event_slug' => (string) $request->route('event'),
    ]);

    return response()->json([
        'message' => 'Informasi event registrasi belum tersedia.',
    ], 404);
};

Route::prefix('v1/public')->group(function () use ($missingEvent) {
    Route::get('/events/{event}/registration', [PublicEventRegistrationController::class, 'show'])
        ->middleware('throttle:60,1')
        ->missing($missingEvent);

    Route::post('/events/{event}/registrations', [PublicRegistrationController::class, 'store'])
        ->middleware('throttle:10,1')
        ->missing($missingEvent);

    Route::get('/e-tickets/{ticketToken}', [PublicETicketController::class, 'show'])
        ->middleware('throttle:30,1');

    Route::get('/e-tickets/{ticketToken}/pdf', [PublicETicketController::class, 'download'])
        ->middleware('throttle:10,1');
});

Route::prefix('v1/staff')->group(function (): void {
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:5,1');

    Route::middleware('staff.auth')->group(function (): void {
        Route::get('/auth/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);

        Route::get('/events', [EventController::class, 'index'])
            ->middleware('permission:events.view');
        Route::get('/events/{event}/statistics', [StatisticsController::class, 'show'])
            ->middleware('permission:statistics.view');
        Route::get('/events/{event}/participants', [ParticipantController::class, 'index'])
            ->middleware(['permission:participants.view', 'permission:registrations.view']);
        Route::get('/events/{event}/registrations/{registration}/e-ticket', [RegistrationTicketController::class, 'download'])
            ->middleware(['permission:participants.view', 'permission:registrations.view', 'throttle:10,1']);
        Route::post('/events/{event}/event-days/{eventDay}/check-ins', [CheckinController::class, 'store'])
            ->middleware('permission:checkins.create');
        Route::post('/events/{event}/event-days/{eventDay}/registrations/{registration}/check-ins', [CheckinController::class, 'storeForRegistration'])
            ->middleware('permission:checkins.create');
        Route::post('/events/{event}/talkshows/{talkshow}/attendances', [TalkshowAttendanceController::class, 'store'])
            ->middleware('permission:attendance.create');

        Route::middleware('role:super_admin')->group(function (): void {
            Route::get('/users', [UserController::class, 'index'])->middleware('permission:users.manage');
            Route::post('/users', [UserController::class, 'store'])->middleware('permission:users.manage');
            Route::patch('/users/{user}', [UserController::class, 'update'])->middleware('permission:users.manage');
            Route::get('/roles', [UserController::class, 'roles'])->middleware('permission:permissions.manage');

            Route::get('/events/{event}/assignments', [EventAssignmentController::class, 'index'])
                ->middleware('permission:assignments.manage');
            Route::post('/events/{event}/assignments', [EventAssignmentController::class, 'store'])
                ->middleware('permission:assignments.manage');
            Route::delete('/events/{event}/assignments/{assignment}', [EventAssignmentController::class, 'destroy'])
                ->middleware('permission:assignments.manage');

            Route::get('/audit-logs', [AuditLogController::class, 'index'])
                ->middleware('permission:audit_logs.view');
        });
    });
});
