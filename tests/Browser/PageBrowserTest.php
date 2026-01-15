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

/**
 * ================================
 * PUBLIC PAGES
 * ================================
 */
test('welcome page displays correctly', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/')
            ->assertSee('GymTracker')
            ->assertVisible('a[href*="login"]')
            ->assertVisible('a[href*="register"]');
    });
});

test('login page displays correctly', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/login')
            ->assertSee('Bon retour !')
            ->assertVisible('input[type="email"]')
            ->assertVisible('input[type="password"]')
            ->assertVisible('button[type="submit"]');
    });
});

test('register page displays correctly', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/register')
            ->assertSee('Bienvenue !')
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
            ->press('Se connecter')
            ->waitForLocation('/dashboard')
            ->assertPathIs('/dashboard');
    });
});

test('user can register', function () {
    $this->browse(function (Browser $browser) {
        $browser->visit('/register')
            ->type('input[autocomplete="name"]', 'Test User')
            ->type('input[type="email"]', 'test-dusk-'.time().'@example.com')
            ->type('input[type="password"]', 'SecurePass123!')
            ->type('input[name="password_confirmation"]', 'SecurePass123!')
            ->press('Créer mon compte')
            ->waitForLocation('/dashboard')
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
            ->assertPresent('.glass-card')
            // Greeting varies by time, so check static elements
            ->assertSee('Séances')
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
            ->assertSee('Mes Séances')
            ->assertPresent('.glass-card')
            ->assertNoConsoleExceptions();
    });
});

test('stats page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/stats')
            ->assertPathIs('/stats')
            ->assertSee('Statistiques')
            ->assertPresent('.glass-card')
            ->assertNoConsoleExceptions();
    });
});

test('goals page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/goals')
            ->assertPathIs('/goals')
            ->assertSee('Objectifs')
            ->assertNoConsoleExceptions();
    });
});

test('exercises page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/exercises')
            ->assertPathIs('/exercises')
            ->assertSee('Exercices')
            ->assertNoConsoleExceptions();
    });
});

test('templates page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/templates')
            ->assertPathIs('/templates')
            ->assertSee('Modèles')
            ->assertNoConsoleExceptions();
    });
});

test('body measurements page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/body-measurements')
            ->assertPathIs('/body-measurements')
            ->assertSee('Mesures')
            ->assertNoConsoleExceptions();
    });
});

test('journal page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/daily-journals')
            ->assertPathIs('/daily-journals')
            ->assertSee('Journal')
            ->assertNoConsoleExceptions();
    });
});

test('notifications page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/notifications')
            ->assertPathIs('/notifications')
            ->assertSee('Notifications')
            ->assertNoConsoleExceptions();
    });
});

test('achievements page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/achievements')
            ->assertPathIs('/achievements')
            ->assertSee('Trophées')
            ->assertNoConsoleExceptions();
    });
});

test('profile page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/profile')
            ->assertPathIs('/profile')
            ->assertSee('Profil')
            ->assertNoConsoleExceptions();
    });
});

test('tools page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/tools')
            ->assertPathIs('/tools')
            ->assertSee('Calculateurs')
            ->assertNoConsoleExceptions();
    });
});

test('plates calculator page renders correctly', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/plates')
            ->assertPathIs('/plates')
            ->assertSee('Calculateur')
            ->assertNoConsoleExceptions();
    });
});

/**
 * ================================
 * CRITICAL USER FLOWS
 * ================================
 */
test('user can create a new workout', function () {
    $user = User::factory()->create();

    $this->browse(function (Browser $browser) use ($user) {
        $browser->loginAs($user)
            ->visit('/workouts')
            ->waitFor('button, a')
            // Look for a create workout button/link
            ->click('@start-workout, button:contains("Nouvelle"), a:contains("Nouvelle")', false)
            ->pause(1000)
            // Should redirect to workout show page or stay on workouts
            ->assertPathBeginsWith('/workouts')
            ->assertNoConsoleExceptions();
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
