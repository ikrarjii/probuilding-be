<?php

namespace App\Providers;

use App\Contracts\Notifications\WhatsAppProvider;
use App\Services\Notifications\MockWhatsAppProvider;
use App\Services\Notifications\NullWhatsAppProvider;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
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
