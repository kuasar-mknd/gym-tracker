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
            ->waitFor('body', 30)
            ->waitFor('[data-testid="login-button"]', 30) // Wait for the button specifically
            // Use assertSeeIn to be more specific if possible, or stick to assertSee with sufficient wait
            ->assertSee('Bon retour');
    });
});

test('users can register', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/register')
            ->waitFor('body', 30)
            ->waitFor('[data-testid="register-button"]', 30) // Ensure form is loaded
            ->waitFor('input[name="name"]', 10) // Ensure input is ready
            ->type('input[name="name"]', 'John Doe')
            ->type('input[name="email"]', 'john'.time().'@example.com')
            ->type('input[name="password"]', 'password')
            ->type('input[name="password_confirmation"]', 'password')
            ->click('[data-testid="register-button"]') // Use click with selector for robustness
            ->waitForLocation('/verify-email', 60) // Increased timeout for heavy operation
            ->assertPathIs('/verify-email');
    });
});

test('authenticated users can see dashboard', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/dashboard')
            ->waitFor('main', 30)
            ->assertPathIs('/dashboard');
    });
});

test('workouts page renders correctly', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/workouts')
            ->waitFor('main', 30)
            ->assertPathIs('/workouts')
            ->assertNoConsoleExceptions();
    });
});

test('exercises page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/exercises')
            ->waitFor('main', 30)
            ->assertPathIs('/exercises')
            ->assertNoConsoleExceptions();
    });
});

test('stats page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/stats')
            ->waitFor('main', 30)
            ->assertPathIs('/stats')
            ->assertNoConsoleExceptions();
    });
});

test('calendar page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/calendar')
            ->waitFor('main', 30)
            ->assertPathIs('/calendar')
            ->assertNoConsoleExceptions();
    });
});

test('goals page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/goals')
            ->waitFor('main', 30)
            ->assertPathIs('/goals')
            ->assertNoConsoleExceptions();
    });
});

test('templates page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/templates')
            ->waitFor('main', 30)
            ->assertPathIs('/templates')
            ->assertNoConsoleExceptions();
    });
});

test('body measurements page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/body-measurements')
            ->waitFor('main', 30)
            ->assertPathIs('/body-measurements')
            ->assertNoConsoleExceptions();
    });
});

test('daily journals page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/daily-journals')
            ->waitFor('main', 30)
            ->assertPathIs('/daily-journals')
            ->assertNoConsoleExceptions();
    });
});

test('notifications page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/notifications')
            ->waitFor('main', 30)
            ->assertPathIs('/notifications')
            ->assertNoConsoleExceptions();
    });
});

test('achievements page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/achievements')
            ->waitFor('main', 30)
            ->assertPathIs('/achievements')
            ->assertNoConsoleExceptions();
    });
});

test('profile page renders correctly', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/profile')
            ->waitFor('main', 30)
            ->assertPathIs('/profile')
            ->assertNoConsoleExceptions();
    });
});

test('tools page renders correctly', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/tools')
            ->waitFor('main', 30)
            ->assertPathIs('/tools')
            ->assertNoConsoleExceptions();
    });
});

test('plates calculator page renders correctly', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/plates')
            ->waitFor('main', 30)
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
            ->waitFor('main', 30)
            ->assertPathIs('/dashboard')
            // Check glass-nav is visible on mobile
            ->assertPresent('.glass-nav')
            ->assertNoConsoleExceptions();
    });
});
