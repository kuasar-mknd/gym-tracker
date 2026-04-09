<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class PasswordUpdateRateLimitTest extends TestCase
{
    public function test_password_update_is_rate_limited(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user);

        // Fail 5 times
        for ($i = 0; $i < 5; $i++) {
            $response = $this->put(route('password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ]);

            $response->assertSessionHasErrors('current_password');
            $this->assertNotEquals(trans('auth.throttle', ['seconds' => 60, 'minutes' => 1]), session('errors')->get('current_password')[0]);
        }

        // 6th attempt should be rate limited
        $response = $this->put(route('password.update'), [
            'current_password' => 'wrong-password',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ]);

        $response->assertSessionHasErrors('current_password');

        // The expected message might be slightly off in time compared to when the rate limiter sets it
        // and when RateLimiter::availableIn() is called in the test.
        // It's safer to just check if it contains the rate limit message structure.

        $this->assertStringContainsString('essayer de nouveau dans', session('errors')->get('current_password')[0]);
        $this->assertStringContainsString('secondes.', session('errors')->get('current_password')[0]);
    }

    public function test_successful_password_update_clears_rate_limiter(): void
    {
        $user = User::factory()->create([
            'password' => Hash::make('password'),
        ]);

        $this->actingAs($user);

        // Fail 3 times
        for ($i = 0; $i < 3; $i++) {
            $this->put(route('password.update'), [
                'current_password' => 'wrong-password',
                'password' => 'new-password123',
                'password_confirmation' => 'new-password123',
            ]);
        }

        $this->assertEquals(3, RateLimiter::attempts('update-password-'.$user->id));

        // Succeed
        $this->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ]);

        $this->assertEquals(0, RateLimiter::attempts('update-password-'.$user->id));
    }
}
