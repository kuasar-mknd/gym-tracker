<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Dusk\Browser;

test('unauthenticated users are redirected to login', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/dashboard')
            ->assertPathIs('/login');
    });
});

test('guest pages and registration flow', function (): void {
    $this->browse(function (Browser $browser): void {
        // 1. Login page
        $browser->logout()
            ->visit('/login')
            ->waitFor('button[type="submit"]', 30)
            ->assertVisible('button[type="submit"]');

        // 2. Registration flow
        $browser->visit('/register')
            ->waitFor('input[name="name"]', 30)
            ->type('input[name="name"]', 'John Doe')
            ->type('input[name="email"]', 'john_reg_'.time().'@example.com')
            ->type('input[name="password"]', 'password')
            ->type('input[name="password_confirmation"]', 'password')
            ->click('[data-testid="register-button"]')
            ->waitForLocation('/verify-email', 30)
            ->assertPathIs('/verify-email');
    });
});

test('authenticated pages smoke test', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/dashboard')
            ->waitFor('main', 30)
            ->assertPathIs('/dashboard');

        $pages = [
            '/workouts',
            '/exercises',
            '/stats',
            '/calendar',
            '/goals',
            '/templates',
            '/body-measurements',
            '/daily-journals',
            '/notifications',
            '/achievements',
            '/profile',
            '/tools',
            '/plates',
        ];

        foreach ($pages as $path) {
            $browser->visit($path)
                ->waitFor('main', 15)
                ->assertPathIs($path)
                ->assertNoConsoleExceptions();
        }

        // Mobile check
        $browser->resize(375, 812)
            ->visit('/dashboard')
            ->waitFor('main', 15)
            ->assertPresent('.glass-nav');
    });
});
