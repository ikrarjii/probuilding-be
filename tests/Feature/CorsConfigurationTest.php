<?php

namespace Tests\Feature;

use Tests\TestCase;

class CorsConfigurationTest extends TestCase
{
    public function test_registration_preflight_allows_the_configured_frontend_origin(): void
    {
        config()->set('cors.allowed_origins', [
            'https://www.probuildintim.com',
            'https://probuildintim.com',
        ]);

        $this->withHeaders([
            'Origin' => 'https://www.probuildintim.com',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,idempotency-key',
        ])->options('/api/v1/public/events/probuild-intim-2026/registrations')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://www.probuildintim.com')
            ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, DELETE, OPTIONS');
    }

    public function test_staff_preflight_allows_bearer_auth_and_staff_http_methods(): void
    {
        config()->set('cors.allowed_origins', [
            'https://www.probuildintim.com',
            'https://probuildintim.com',
        ]);

        $response = $this->withHeaders([
            'Origin' => 'https://probuildintim.com',
            'Access-Control-Request-Method' => 'PATCH',
            'Access-Control-Request-Headers' => 'authorization,content-type',
        ])->options('/api/v1/staff/users/example-id')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://probuildintim.com')
            ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, PATCH, DELETE, OPTIONS');

        $this->assertStringContainsString(
            'authorization',
            strtolower((string) $response->headers->get('Access-Control-Allow-Headers')),
        );
    }

    public function test_registration_preflight_rejects_an_unconfigured_origin(): void
    {
        config()->set('cors.allowed_origins', [
            'https://www.probuildintim.com',
            'https://probuildintim.com',
        ]);

        $this->withHeaders([
            'Origin' => 'https://example.invalid',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'content-type,idempotency-key',
        ])->options('/api/v1/public/events/probuild-intim-2026/registrations')
            ->assertHeaderMissing('Access-Control-Allow-Origin');
    }
}
