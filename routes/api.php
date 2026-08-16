<?php

use App\Http\Controllers\Api\V1\PublicETicketController;
use App\Http\Controllers\Api\V1\PublicEventRegistrationController;
use App\Http\Controllers\Api\V1\PublicRegistrationController;
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
