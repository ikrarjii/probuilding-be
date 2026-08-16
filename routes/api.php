<?php

use App\Http\Controllers\Api\V1\PublicETicketController;
use App\Http\Controllers\Api\V1\PublicEventRegistrationController;
use App\Http\Controllers\Api\V1\PublicRegistrationController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/public')->group(function () {
    Route::get('/events/{event}/registration', [PublicEventRegistrationController::class, 'show'])
        ->middleware('throttle:60,1');

    Route::post('/events/{event}/registrations', [PublicRegistrationController::class, 'store'])
        ->middleware('throttle:10,1');

    Route::get('/e-tickets/{ticketToken}', [PublicETicketController::class, 'show'])
        ->middleware('throttle:30,1');

    Route::get('/e-tickets/{ticketToken}/pdf', [PublicETicketController::class, 'download'])
        ->middleware('throttle:10,1');
});
