<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffRateLimitSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_login_rate_limit_returns_a_safe_429_response(): void
    {
        $request = fn () => $this
            ->withServerVariables(['REMOTE_ADDR' => '198.51.100.42'])
            ->postJson('/api/v1/staff/auth/login', [
                'email' => 'unknown@example.test',
                'password' => 'invalid-password',
            ]);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $request()->assertUnprocessable();
        }

        $response = $request()->assertTooManyRequests();

        foreach (['SQLSTATE', 'RuntimeException', 'trace', 'vendor\\'] as $leak) {
            $this->assertStringNotContainsString($leak, $response->getContent());
        }
    }
}
