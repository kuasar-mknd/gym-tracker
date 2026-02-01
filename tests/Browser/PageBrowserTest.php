<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Dusk\Browser;

test('unauthenticated users are redirected to login', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->resize(1920, 1080)
            ->visit('/dashboard')
            ->assertPathIs('/login');
    });
});

test('users can see login page', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->resize(1920, 1080)
            ->visit('/login')
            ->waitForText('Se connecter', 30) // Increased timeout
            ->assertSee('Se connecter');
    });
});

test('users can register', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->resize(1920, 1080)
            ->visit('/register')
            ->type('input[name="name"]', 'John Doe')
            ->type('input[name="email"]', 'john'.time().'@example.com')
            ->type('input[name="password"]', 'password')
            ->type('input[name="password_confirmation"]', 'password')
            ->press('Créer mon compte')
            ->waitForLocation('/verify-email', 30) // Increased timeout
            ->assertPathIs('/verify-email');
    });
});

test('authenticated users can see dashboard', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->resize(1920, 1080)
            ->visit('/dashboard')
            ->waitFor('main', 30)
            ->assertPathIs('/dashboard');
    });
});

test('workouts page renders correctly', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->resize(1920, 1080)
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
            ->resize(1920, 1080)
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
            ->resize(1920, 1080)
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
            ->resize(1920, 1080)
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
            ->resize(1920, 1080)
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
            ->resize(1920, 1080)
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
            ->resize(1920, 1080)
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
            ->resize(1920, 1080)
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
            ->resize(1920, 1080)
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
            ->resize(1920, 1080)
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
            ->resize(1920, 1080)
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
            ->resize(1920, 1080)
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
            ->resize(1920, 1080)
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
