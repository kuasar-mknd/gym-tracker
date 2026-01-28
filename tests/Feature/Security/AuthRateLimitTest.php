<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_is_rate_limited(): void
    {
        // Hit the endpoint 6 times (allowed)
        for ($i = 0; $i < 6; $i++) {
            $response = $this->post('/register', [
                'name' => 'Test User',
                'email' => "test{$i}@example.com",
                'password' => 'password',
                'password_confirmation' => 'password',
            ]);

            $this->assertNotEquals(429, $response->status(), "Request $i failed with 429");

            // Successful registration logs the user in. We must logout to try registering again as a guest.
            // This also ensures we are testing the endpoint's IP rate limiting, not user-specific limits.
            auth()->logout();
        }

        // The 7th attempt should be blocked
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test_limit@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertStatus(429);
    }

    public function test_login_is_rate_limited_by_ip(): void
    {
        // Hit the endpoint 6 times with different emails to bypass LoginRequest's email throttling
        // checking that route-level throttling catches it.
        for ($i = 0; $i < 6; $i++) {
            $response = $this->post('/login', [
                'email' => "test{$i}@example.com",
                'password' => 'password',
            ]);

            $this->assertNotEquals(429, $response->status(), "Request $i failed with 429");
        }

        $response = $this->post('/login', [
            'email' => 'test_limit@example.com',
            'password' => 'password',
        ]);

        $response->assertStatus(429);
    }

    public function test_forgot_password_is_rate_limited(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $response = $this->post('/forgot-password', [
                'email' => "test{$i}@example.com",
            ]);

            $this->assertNotEquals(429, $response->status(), "Request $i failed with 429");
        }

        $response = $this->post('/forgot-password', [
            'email' => 'test_limit@example.com',
        ]);

        $response->assertStatus(429);
    }
}
