<?php

use App\Exceptions\DuplicateRegistrationException;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InvalidETicketTokenException;
use App\Exceptions\TicketGenerationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (DuplicateRegistrationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Nomor WhatsApp ini sudah terdaftar untuk event tersebut.',
                    'errors' => [
                        'whatsapp' => ['Satu nomor WhatsApp hanya dapat memiliki satu registrasi per event.'],
                    ],
                ], 422);
            }
        });

        $exceptions->render(function (IdempotencyConflictException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Permintaan registrasi yang sama digunakan dengan data berbeda.',
                ], 409);
            }
        });

        $exceptions->render(function (InvalidETicketTokenException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'E-ticket tidak ditemukan atau tautan tidak valid.',
                ], 404);
            }
        });

        $exceptions->render(function (TicketGenerationException $exception, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => $exception->getMessage(),
                ], 503);
            }
        });
    })->create();
