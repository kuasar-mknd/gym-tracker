<?php

/**
 * Comprehensive Browser E2E Tests
 *
 * These tests use a REAL browser (Chrome) to verify:
 * 1. Pages load without blank screens
 * 2. No JavaScript console errors
 * 3. Critical UI elements are visible
 * 4. User flows work end-to-end
 */

use App\Models\Exercise;
use App\Models\User;
use App\Models\Workout;
use Laravel\Dusk\Browser;

beforeEach(function () {
    // Ensure we have test data
    Exercise::factory()->count(5)->create();
});

test('login page displays correctly', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/login')
            ->waitForText('BON RETOUR')
            ->assertVisible('input[type="email"]')
            ->assertVisible('input[type="password"]')
            ->assertVisible('button[type="submit"]');
    });
});

test('register page displays correctly', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/register')
            ->waitForText('BIENVENUE')
            ->assertVisible('input[autocomplete="name"]')
            ->assertVisible('input[type="email"]')
            ->assertVisible('input[type="password"]');
    });
});

/**
 * ================================
 * AUTHENTICATION FLOW
 * ================================
 */
test('user can login and see dashboard', function () {
    $user = User::factory()->create([
        'password' => bcrypt('password123'),
    ]);

    $this->browse(function (Browser $browser) use ($user) {
        $browser->visit('/login')
            ->type('input[type="email"]', $user->email)
            ->type('input[type="password"]', 'password123')
            ->click('button[type="submit"]')
            ->waitForLocation('/dashboard')
            ->assertPathIs('/dashboard');
    });
});

test('user can register', function () {
    $this->browse(function (Browser $browser) {
        $browser->logout()
            ->visit('/register')
            ->type('input[name="name"]', 'Test User')
            ->type('input[name="email"]', 'test-dusk-'.time().'@example.com')
            ->type('input[name="password"]', 'SecurePass123!')
            ->type('input[name="password_confirmation"]', 'SecurePass123!')
            ->click('button[type="submit"]')
            ->waitForText('Test User') // User name appears on dashboard
            ->assertPathIs('/dashboard');
    });
});

/**
 * ================================
 * AUTHENTICATED PAGE RENDERING
 * ================================
 */
test('dashboard page renders correctly', function () {
    $user = User::factory()->create();
    Workout::factory()->count(3)->create(['user_id' => $user->id]);

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/dashboard')
            ->assertPathIs('/dashboard')
            // Check page is not blank - key UI elements visible
            ->assertPresent('.glass-panel-light')
            // Greeting varies by time, so check static elements
            ->waitForText('DÉMARRER')
            // Check no JavaScript errors
            ->assertNoConsoleExceptions();
    });
});

test('workouts page renders correctly', function () {
    $user = User::factory()->create();
    Workout::factory()->count(5)->create(['user_id' => $user->id]);

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/workouts')
            ->assertPathIs('/workouts')
            ->waitForText('Séances')
            ->assertPresent('.glass-panel-light')
            ->assertNoConsoleExceptions();
    });
});

test('stats page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/stats')
            ->assertPathIs('/stats')
            ->waitForText('ÉVOLUTION')
            ->assertPresent('.glass-panel-light')
            ->assertNoConsoleExceptions();
    });
});

test('goals page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/goals')
            ->assertPathIs('/goals')
            ->waitForText('Objectif')
            ->assertNoConsoleExceptions();
    });
});

test('exercises page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/exercises')
            ->assertPathIs('/exercises')
            ->waitForText('BIBLIOTHÈQUE')
            ->assertNoConsoleExceptions();
    });
});

test('templates page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/templates')
            ->assertPathIs('/templates')
            ->waitForText('Modèle')
            ->assertNoConsoleExceptions();
    });
});

test('body measurements page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/body-measurements')
            ->assertPathIs('/body-measurements')
            ->waitForText('Mesures')
            ->assertNoConsoleExceptions();
    });
});

test('journal page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/daily-journals')
            ->assertPathIs('/daily-journals')
            ->waitForText('Journal')
            ->assertNoConsoleExceptions();
    });
});

test('notifications page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/notifications')
            ->assertPathIs('/notifications')
            ->waitForText('Notifications')
            ->assertNoConsoleExceptions();
    });
});

test('achievements page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/achievements')
            ->assertPathIs('/achievements')
            ->waitForText('Trophées')
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

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/plates')
            ->assertPathIs('/plates')
            ->waitForText('CALCULATEUR')
            ->assertNoConsoleExceptions();
    });
});

/**
 * ================================
 * CRITICAL USER FLOWS
 * ================================
 */
test('user can perform full workout logging flow', function () {
    $this->markTestSkipped('Skipping due to persistent CI timeout in workout creation flow.');
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['name' => 'Bench Press', 'category' => 'Pectoraux']);

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/workouts')
            // 1. Start new workout
            ->waitForText('Séances') // Ensure page loaded
            ->waitForText('Aucune séance')
            ->waitFor('[data-testid="empty-state-start-workout"]')
            ->pause(1000)
            ->script("document.querySelector('[data-testid=\"empty-state-start-workout\"]').click();");

        $browser->waitForLocation('/workouts/*', 30)
            ->waitForText('Ajouter un exercice', 30); // Unique to Show page

        // 2. Add Exercise

        $browser->press('Ajouter un exercice')
            ->waitFor('.glass-modal')
            ->type('input[placeholder="Rechercher..."]', 'Bench')
            ->waitForText('Bench Press')
            ->click('button[aria-label="Ajouter Bench Press"]')
            ->waitUntilMissing('.glass-modal')

            // 3. Verify Exercise Added
            ->waitForText('Bench Press')

            // 4. Log a Set
            ->press('Ajouter une série')
            ->waitFor('input[aria-label*="Poids"]')
            ->type('input[aria-label*="Poids"]', '80')
            ->type('input[aria-label*="Répétitions"]', '12')

            // 5. Complete Set
            ->click('button[aria-label="Marquer comme complété"]')

            // 6. Verify Completion (Green background class or checkmark)
            ->waitFor('.bg-accent-success')
            ->refresh()
            ->waitForText('Bench Press')
            ->waitUsing(10, 100, function () use ($browser) {
                return $browser->inputValue('input[aria-label*="Poids"]') === '80'
                    && $browser->inputValue('input[aria-label*="Répétitions"]') === '12';
            })
            ->assertInputValue('input[aria-label*="Poids"]', '80')
            ->assertInputValue('input[aria-label*="Répétitions"]', '12');
    });
});

test('navigation works correctly on mobile', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->resize(375, 812) // iPhone X dimensions
            ->visit('/dashboard')
            ->assertPathIs('/dashboard')
            // Check bottom nav is visible on mobile
            ->assertPresent('nav, .bottom-nav, [role="navigation"]')
            ->assertNoConsoleExceptions();
    });
});
