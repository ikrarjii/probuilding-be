<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ProductionSafetyTest extends TestCase
{
    public function test_production_readiness_check_accepts_a_safe_configuration(): void
    {
        config()->set([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://backend.probuildintim.com',
            'app.key' => 'base64:configured-production-key',
            'tickets.public_web_url' => 'https://probuildintim.com',
            'database.default' => 'pgsql',
            'cors.allowed_origins' => [
                'https://probuildintim.com',
                'https://www.probuildintim.com',
            ],
            'logging.channels.single.level' => 'warning',
        ]);

        $this->artisan('app:production-check')
            ->expectsOutput('Production configuration passed the readiness check.')
            ->assertSuccessful();
    }

    public function test_production_readiness_check_rejects_unsafe_values_without_printing_secrets(): void
    {
        config()->set([
            'app.env' => 'production',
            'app.debug' => true,
            'app.url' => 'http://127.0.0.1:8000',
            'app.key' => 'base64:must-never-be-printed',
            'tickets.public_web_url' => 'http://localhost:5173',
            'database.default' => 'sqlite',
            'cors.allowed_origins' => ['http://localhost:5173'],
            'logging.channels.single.level' => 'debug',
        ]);

        $exitCode = Artisan::call('app:production-check');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('APP_DEBUG must be false.', $output);
        $this->assertStringContainsString('SQLite is not accepted', $output);
        $this->assertStringNotContainsString('must-never-be-printed', $output);
    }

    public function test_production_readiness_check_requires_the_frontend_origin_in_cors(): void
    {
        config()->set([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://backend.probuildintim.com',
            'app.key' => 'base64:configured-production-key',
            'tickets.public_web_url' => 'https://www.probuildintim.com',
            'database.default' => 'mysql',
            'cors.allowed_origins' => ['https://unrelated.example.com'],
            'logging.channels.single.level' => 'warning',
        ]);

        $this->artisan('app:production-check')
            ->expectsOutput(' - CORS_ALLOWED_ORIGINS must include the PUBLIC_WEB_URL origin.')
            ->assertFailed();
    }

    public function test_unexpected_production_api_errors_are_generic_even_if_debug_is_misconfigured(): void
    {
        config()->set('app.env', 'production');
        config()->set('app.debug', true);

        Route::get('/api/__production-error-test', function (): never {
            throw new RuntimeException(
                'SQLSTATE database failure at /home/private/app.php password=super-secret'
            );
        });

        $response = $this->getJson('/api/__production-error-test')
            ->assertInternalServerError()
            ->assertExactJson([
                'success' => false,
                'message' => 'Terjadi kesalahan tak terduga. Silakan coba lagi nanti.',
            ]);

        foreach (['SQLSTATE', '/home/private', 'super-secret', 'RuntimeException', 'trace'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
    }
}
