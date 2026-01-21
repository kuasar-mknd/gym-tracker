<?php

/**
 * Comprehensive Page Rendering Tests
 *
 * These tests verify that every page in the application:
 * 1. Returns HTTP 200 (no server errors)
 * 2. Renders the correct Inertia component
 * 3. Passes the correct prop structures (especially pagination)
 *
 * This prevents issues like:
 * - Controller cache configuration errors (BadMethodCallException)
 * - Pagination format mismatches (workouts vs workouts.data)
 * - Missing Inertia props
 */

use App\Models\Achievement;
use App\Models\BodyMeasurement;
use App\Models\DailyJournal;
use App\Models\Exercise;
use App\Models\Goal;
use App\Models\Plate;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

/**
 * ================================
 * DASHBOARD PAGE
 * ================================
 */
test('dashboard page renders with correct props', function (): void {
    // Create some test data
    Workout::factory()->count(5)->create(['user_id' => $this->user->id]);
    BodyMeasurement::factory()->create(['user_id' => $this->user->id]);
    Goal::factory()->create(['user_id' => $this->user->id]);

    actingAs($this->user)
        ->get('/dashboard')
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Dashboard')
                ->has('workoutsCount')
                ->has('thisWeekCount')
                ->has('recentWorkouts')
                ->has('activeGoals')
        );
});

/**
 * ================================
 * WORKOUTS INDEX PAGE (PAGINATED)
 * ================================
 */
test('workouts index page renders with paginated data structure', function (): void {
    // Create 25 workouts to trigger pagination
    Workout::factory()->count(25)->create(['user_id' => $this->user->id]);

    actingAs($this->user)
        ->get('/workouts')
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Workouts/Index')
                // CRITICAL: workouts must be paginated object with data array
                ->has('workouts.data')
                ->has('workouts.links')
                ->has('monthlyFrequency')
        );
});

test('workouts index page renders correctly when empty', function (): void {
    actingAs($this->user)
        ->get('/workouts')
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Workouts/Index')
                ->has('workouts.data', 0)  // Empty array
        );
});

/**
 * ================================
 * WORKOUTS SHOW PAGE
 * ================================
 */
test('workout show page renders with correct props', function (): void {
    $workout = Workout::factory()->create(['user_id' => $this->user->id]);
    Exercise::factory()->count(3)->create();

    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    Set::factory()->count(3)->create(['workout_line_id' => $line->id]);

    actingAs($this->user)
        ->get("/workouts/{$workout->id}")
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Workouts/Show')
                ->has('workout')
                ->has('workout.workout_lines')
                ->has('exercises')
        );
});

/**
 * ================================
 * STATS PAGE
 * ================================
 */
test('stats page renders without cache errors', function (): void {
    // This test specifically catches BadMethodCallException for cache tagging
    $workout = Workout::factory()->create([
        'user_id' => $this->user->id,
        'started_at' => now()->subDays(5),
    ]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id]);
    Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 10,
    ]);

    actingAs($this->user)
        ->get('/stats')
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Stats/Index')
                ->has('volumeTrend')
                ->has('muscleDistribution')
                ->has('monthlyComparison')
                ->has('exercises')
        );
});

/**
 * ================================
 * GOALS PAGE
 * ================================
 */
test('goals index page renders with correct props', function (): void {
    Goal::factory()->count(3)->create(['user_id' => $this->user->id]);

    actingAs($this->user)
        ->get('/goals')
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Goals/Index')
                ->has('goals')
        );
});

/**
 * ================================
 * EXERCISES PAGE
 * ================================
 */
test('exercises index page renders with correct props', function (): void {
    Exercise::factory()->count(5)->create();

    actingAs($this->user)
        ->get('/exercises')
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Exercises/Index')
                ->has('exercises')
                ->has('categories')
        );
});

/**
 * ================================
 * TEMPLATES PAGE
 * ================================
 */
test('templates index page renders with correct props', function (): void {
    actingAs($this->user)
        ->get('/templates')
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Workouts/Templates/Index')
                ->has('templates')
        );
});

/**
 * ================================
 * BODY MEASUREMENTS PAGE
 * ================================
 */
test('body measurements index page renders with correct props', function (): void {
    BodyMeasurement::factory()->count(5)->create(['user_id' => $this->user->id]);

    actingAs($this->user)
        ->get('/body-measurements')
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Measurements/Index')
                ->has('measurements')
        );
});

/**
 * ================================
 * DAILY JOURNALS PAGE
 * ================================
 */
test('daily journals index page renders with correct props', function (): void {
    DailyJournal::factory()->count(3)->create(['user_id' => $this->user->id]);

    actingAs($this->user)
        ->get('/daily-journals')
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Journal/Index')
                ->has('journals')
        );
});

/**
 * ================================
 * NOTIFICATIONS PAGE (PAGINATED)
 * ================================
 */
test('notifications index page renders correctly', function (): void {
    actingAs($this->user)
        ->get('/notifications')
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Notifications/Index')
                ->has('notifications')
        );
});

/**
 * ================================
 * ACHIEVEMENTS PAGE
 * ================================
 */
test('achievements index page renders with correct props', function (): void {
    Achievement::factory()->count(5)->create();

    actingAs($this->user)
        ->get('/achievements')
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Achievements/Index')
                ->has('achievements')
        );
});

/**
 * ================================
 * PROFILE PAGE
 * ================================
 */
test('profile edit page renders with correct props', function (): void {
    actingAs($this->user)
        ->get('/profile')
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Profile/Index')
            // mustVerifyEmail is the standard prop from Breeze
        );
});

/**
 * ================================
 * TOOLS PAGE
 * ================================
 */
test('tools index page renders correctly', function (): void {
    actingAs($this->user)
        ->get('/tools')
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Tools/Index')
        );
});

test('one rep max calculator page renders correctly', function (): void {
    actingAs($this->user)
        ->get('/tools/1rm')
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Tools/OneRepMax')
        );
});

/**
 * ================================
 * PLATES PAGE
 * ================================
 */
test('plates index page renders with correct props', function (): void {
    Plate::factory()->count(3)->create(['user_id' => $this->user->id]);

    actingAs($this->user)
        ->get('/plates')
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Tools/PlateCalculator')
                ->has('plates')
        );
});

/**
 * ================================
 * AUTHENTICATION REDIRECTS
 * ================================
 */
test('unauthenticated users are redirected to login for all protected pages', function ($route): void {
    $this->get($route)->assertRedirect('/login');
})->with([
    '/dashboard',
    '/workouts',
    '/stats',
    '/goals',
    '/exercises',
    '/templates',
    '/body-measurements',
    '/daily-journals',
    '/notifications',
    '/achievements',
    '/profile',
    '/tools',
    '/plates',
]);

/**
 * ================================
 * ROOT REDIRECT
 * ================================
 */
test('root redirects to dashboard', function (): void {
    $this->get('/')
        ->assertStatus(302)
        ->assertRedirect('/dashboard');
});

/**
 * ================================
 * LOGIN/REGISTER PAGES (PUBLIC)
 * ================================
 */
test('login page renders correctly', function (): void {
    $this->get('/login')
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Auth/Login')
        );
});

test('register page renders correctly', function (): void {
    $this->get('/register')
        ->assertStatus(200)
        ->assertInertia(
            fn (Assert $page): \Inertia\Testing\AssertableInertia => $page
                ->component('Auth/Register')
        );
});
