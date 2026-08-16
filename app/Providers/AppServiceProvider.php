<?php

namespace App\Providers;

use App\Contracts\Notifications\EmailProvider;
use App\Contracts\Notifications\WhatsAppProvider;
use App\Services\Notifications\LaravelMailEmailProvider;
use App\Services\Notifications\MockEmailProvider;
use App\Services\Notifications\MockWhatsAppProvider;
use App\Services\Notifications\NullEmailProvider;
use App\Services\Notifications\NullWhatsAppProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(EmailProvider::class, function ($app) {
            $driver = config('notifications.email.driver', 'disabled');

            if ($app->environment('production') && $driver === 'mock') {
                return $app->make(NullEmailProvider::class);
            }

            return match ($driver) {
                'mail' => $app->make(LaravelMailEmailProvider::class),
                'mock' => $app->make(MockEmailProvider::class),
                default => $app->make(NullEmailProvider::class),
            };
        });

        $this->app->singleton(WhatsAppProvider::class, function ($app) {
            $driver = config('notifications.whatsapp.driver', 'disabled');

            if ($app->environment('production') && $driver === 'mock') {
                return $app->make(NullWhatsAppProvider::class);
            }

            return match ($driver) {
                'mock' => $app->make(MockWhatsAppProvider::class),
                default => $app->make(NullWhatsAppProvider::class),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
