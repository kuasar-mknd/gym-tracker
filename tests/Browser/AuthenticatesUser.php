<?php

namespace Tests\Browser;

use App\Models\User;
use Laravel\Dusk\Browser;

trait AuthenticatesUser
{
    /**
     * Authenticate a user.
     */
    protected function loginUser(Browser $browser, User $user): void
    {
        $browser->loginAs($user->id)
            ->visit('/dashboard')
            ->waitFor('#main-content', 30);
    }
}
