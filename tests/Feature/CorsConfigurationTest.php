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
            ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS');
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
