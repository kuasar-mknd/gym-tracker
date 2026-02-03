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

test('users can see login page', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/login')
            ->waitFor('form', 45) // Increased timeout and robustness
            ->assertSee('Se connecter');
    });
});

test('users can register', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/register')
            ->waitFor('form', 45) // Ensure form is loaded
            ->type('input[name="name"]', 'John Doe')
            ->type('input[name="email"]', 'john'.time().'@example.com')
            ->type('input[name="password"]', 'password')
            ->type('input[name="password_confirmation"]', 'password')
            ->waitFor('[data-testid="register-button"]', 10)
            ->press('Créer mon compte')
            ->waitForLocation('/verify-email', 60) // Increased timeout for heavy operation
            ->assertPathIs('/verify-email');
    });
});

test('authenticated users can see dashboard', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/dashboard')
            ->waitFor('main', 45)
            ->assertPathIs('/dashboard');
    });
});

test('workouts page renders correctly', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/workouts')
            ->waitFor('main', 45)
            ->assertPathIs('/workouts')
            ->assertNoConsoleExceptions();
    });
});

test('exercises page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/exercises')
            ->waitFor('main', 45)
            ->assertPathIs('/exercises')
            ->assertNoConsoleExceptions();
    });
});

test('stats page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/stats')
            ->waitFor('main', 45)
            ->assertPathIs('/stats')
            ->assertNoConsoleExceptions();
    });
});

test('calendar page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/calendar')
            ->waitFor('main', 45)
            ->assertPathIs('/calendar')
            ->assertNoConsoleExceptions();
    });
});

test('goals page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/goals')
            ->waitFor('main', 45)
            ->assertPathIs('/goals')
            ->assertNoConsoleExceptions();
    });
});

test('templates page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/templates')
            ->waitFor('main', 45)
            ->assertPathIs('/templates')
            ->assertNoConsoleExceptions();
    });
});

test('body measurements page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/body-measurements')
            ->waitFor('main', 45)
            ->assertPathIs('/body-measurements')
            ->assertNoConsoleExceptions();
    });
});

test('daily journals page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/daily-journals')
            ->waitFor('main', 45)
            ->assertPathIs('/daily-journals')
            ->assertNoConsoleExceptions();
    });
});

test('notifications page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/notifications')
            ->waitFor('main', 45)
            ->assertPathIs('/notifications')
            ->assertNoConsoleExceptions();
    });
});

test('achievements page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/achievements')
            ->waitFor('main', 45)
            ->assertPathIs('/achievements')
            ->assertNoConsoleExceptions();
    });
});

test('profile page renders correctly', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/profile')
            ->waitFor('main', 45)
            ->assertPathIs('/profile')
            ->assertNoConsoleExceptions();
    });
});

test('tools page renders correctly', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/tools')
            ->waitFor('main', 45)
            ->assertPathIs('/tools')
            ->assertNoConsoleExceptions();
    });
});

test('plates calculator page renders correctly', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/plates')
            ->waitFor('main', 45)
            ->assertPathIs('/plates')
            ->assertNoConsoleExceptions();
    });
});

test('navigation works correctly on mobile', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->resize(375, 812) // iPhone X dimensions
            ->visit('/dashboard')
            ->waitFor('main', 45)
            ->assertPathIs('/dashboard')
            // Check glass-nav is visible on mobile
            ->assertPresent('.glass-nav')
            ->assertNoConsoleExceptions();
    });
});
