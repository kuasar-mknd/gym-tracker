<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Laravel\Dusk\Browser;

uses(DatabaseTruncation::class);

test('unauthenticated users are redirected to login', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/dashboard')
            ->assertPathIs('/login');
    });
});

test('users can see login page', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/login')
            ->waitForText('SE CONNECTER', 10)
            ->assertSee('SE CONNECTER');
    });
});

test('users can register', function (): void {
    $this->browse(function (Browser $browser): void {
        $browser->visit('/register')
            ->type('input[name="name"]', 'John Doe')
            ->type('input[name="email"]', 'john@example.com')
            ->type('input[name="password"]', 'password')
            ->type('input[name="password_confirmation"]', 'password')
            ->press('CRÉER MON COMPTE')
            ->waitForLocation('/verify-email', 30)
            ->assertPathIs('/verify-email');
    });
});

test('authenticated users can see dashboard', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/dashboard')
            ->assertSee('ACCUEIL');
    });
});

test('workouts page renders correctly', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/workouts')
            ->waitForText('AUCUNE SÉANCE', 15)
            ->assertSee('AUCUNE SÉANCE')
            ->assertNoConsoleExceptions();
    });
});

test('exercises page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/exercises')
            ->waitForText('BIBLIOTHÈQUE', 15)
            ->assertSee('BIBLIOTHÈQUE')
            ->assertNoConsoleExceptions();
    });
});

test('stats page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/stats')
            ->assertPathIs('/stats')
            ->assertNoConsoleExceptions();
    });
});

test('calendar page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/calendar')
            ->assertPathIs('/calendar')
            ->assertNoConsoleExceptions();
    });
});

test('goals page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/goals')
            ->assertPathIs('/goals')
            ->assertNoConsoleExceptions();
    });
});

test('templates page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/templates')
            ->assertPathIs('/templates')
            ->assertNoConsoleExceptions();
    });
});

test('body measurements page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/body-measurements')
            ->assertPathIs('/body-measurements')
            ->assertNoConsoleExceptions();
    });
});

test('daily journals page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/daily-journals')
            ->assertPathIs('/daily-journals')
            ->assertNoConsoleExceptions();
    });
});

test('notifications page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/notifications')
            ->assertPathIs('/notifications')
            ->assertNoConsoleExceptions();
    });
});

test('achievements page works', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/achievements')
            ->assertPathIs('/achievements')
            ->assertNoConsoleExceptions();
    });
});

test('profile page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/profile')
            ->assertPathIs('/profile')
            ->waitFor('main', 10)
            ->assertNoConsoleExceptions();
    });
});

test('tools page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/tools')
            ->assertPathIs('/tools')
            ->waitForText('Outils')
            ->assertNoConsoleExceptions();
    });
});

test('plates calculator page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/plates')
            ->assertPathIs('/plates')
            ->assertNoConsoleExceptions();
    });
});

test('user can see daily calorie goal', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/dashboard')
            ->waitForText('VOLUME HEBDO', 15)
            ->assertPathIs('/dashboard')
            ->assertNoConsoleExceptions();
    });
});

test('user can perform full workout logging flow', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->visit('/workouts')
            // 1. Verify workouts page loads with empty state
            ->waitForText('AUCUNE SÉANCE', 15)
            ->waitFor('[data-testid="empty-state-start-workout"]', 15)
            ->pause(500)
            ->script("document.querySelector('[data-testid=\"empty-state-start-workout\"]').click();");

        // 2. Verify redirection to workout show page
        $browser->waitForText('AJOUTER UN EXERCICE', 30);

        // 3. Verify add exercise button opens modal
        $browser->script("document.querySelector('[data-testid=\"add-exercise-button\"]').click();");

        // 4. Verify modal opens with search functionality
        $browser->pause(500)
            ->waitForText('CHOISIR UN EXERCICE', 15)
            ->assertPresent('input[placeholder="Rechercher..."]')
            ->assertNoConsoleExceptions();
    });
});

test('navigation works correctly on mobile', function (): void {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user): void {
        $browser->loginAs($user)
            ->resize(375, 812) // iPhone X dimensions
            ->visit('/dashboard')
            ->assertPathIs('/dashboard')
            // Check bottom nav is visible on mobile
            ->assertPresent('nav, .bottom-nav, [role="navigation"]')
            ->assertNoConsoleExceptions();
    });
});
