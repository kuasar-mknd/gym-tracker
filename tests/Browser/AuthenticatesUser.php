<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;

trait AuthenticatesUser
{
    /**
     * Authenticate a user manually via the login form.
     */
    protected function loginUser(Browser $browser, User $user): void
    {
        $browser->visit('/login')
            ->waitFor('[data-testid="email-input"]', 30)
            ->type('[data-testid="email-input"]', $user->email)
            ->type('[data-testid="password-input"]', 'password123')
            ->click('[data-testid="login-button"]')
            ->waitForLocation('/dashboard', 30)
            ->waitFor('#main-content', 30);
    }
}
