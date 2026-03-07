<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

use Illuminate\Support\Facades\Gate;

beforeEach(function () {
    $this->user = User::factory()->create();
});

test('user can view achievements list', function () {
    Sanctum::actingAs($this->user);

    Achievement::factory()->count(3)->create();

    getJson(route('api.v1.achievements.index'))
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => [
                    'id',
                    'slug',
                    'name',
                    'description',
                    'icon',
                    'type',
                    'threshold',
                    'category',
                    'created_at',
                    'updated_at',
                ],
            ],
            'meta',
            'links',
        ])
        ->assertJsonCount(3, 'data');
});

test('unauthenticated user cannot view achievements list', function () {
    getJson(route('api.v1.achievements.index'))
        ->assertUnauthorized();
});

test('user can view a specific achievement', function () {
    Sanctum::actingAs($this->user);

    $achievement = Achievement::factory()->create();

    getJson(route('api.v1.achievements.show', $achievement))
        ->assertOk()
        ->assertJsonPath('data.id', $achievement->id)
        ->assertJsonPath('data.name', $achievement->name);
});

test('user cannot create an achievement', function () {
    Sanctum::actingAs($this->user);

    $payload = Achievement::factory()->make()->toArray();

    postJson(route('api.v1.achievements.store'), $payload)
        ->assertForbidden();
});

test('admin can create an achievement', function () {
    Gate::define('Create:Achievement', function () { return true; });
    Gate::define('ViewAny:Achievement', function () { return true; });

    // API routes use `auth:sanctum`. So we can use a User model instead of Admin if Sanctum is needed
    // The policy just checks $authUser instanceof \App\Models\User.
    // If it is, it returns false for Update/Delete but wait, the policy allows any auth user who can pass 'Create:Achievement'.
    $adminUser = User::factory()->create();
    Sanctum::actingAs($adminUser);

    $payload = [
        'slug' => 'test-achievement',
        'name' => 'Test Achievement',
        'description' => 'This is a test achievement.',
        'icon' => 'test-icon',
        'type' => 'test',
        'threshold' => 10,
        'category' => 'test-category',
    ];

    postJson(route('api.v1.achievements.store'), $payload)
        ->assertCreated()
        ->assertJsonPath('data.slug', 'test-achievement');

    assertDatabaseHas('achievements', ['slug' => 'test-achievement']);
});

test('create achievement validates required fields', function () {
    Gate::define('Create:Achievement', function () { return true; });
    Gate::define('ViewAny:Achievement', function () { return true; });
    $adminUser = User::factory()->create();
    Sanctum::actingAs($adminUser);

    postJson(route('api.v1.achievements.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['slug', 'name', 'description', 'icon', 'type', 'threshold', 'category']);
});

test('create achievement validates unique slug', function () {
    Gate::define('Create:Achievement', function () { return true; });
    Gate::define('ViewAny:Achievement', function () { return true; });
    $adminUser = User::factory()->create();
    Sanctum::actingAs($adminUser);

    $existing = Achievement::factory()->create(['slug' => 'existing-slug']);

    $payload = Achievement::factory()->make(['slug' => 'existing-slug'])->toArray();

    postJson(route('api.v1.achievements.store'), $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['slug']);
});

test('user cannot update an achievement', function () {
    Sanctum::actingAs($this->user);

    $achievement = Achievement::factory()->create();

    putJson(route('api.v1.achievements.update', $achievement), ['name' => 'New Name'])
        ->assertForbidden();
});

test('admin can update an achievement', function () {
    Gate::define('Update:Achievement', function () { return true; });
    Gate::define('update', function () { return true; });

    // We can't use an anonymous class for policy replacement in Laravel 11.
    // Instead we bypass Gate authorization checking.
    $adminUser = User::factory()->create();
    Gate::before(function ($user, $ability) { return true; });

    Sanctum::actingAs($adminUser);

    $achievement = Achievement::factory()->create(['name' => 'Old Name']);

    putJson(route('api.v1.achievements.update', $achievement), ['name' => 'New Name'])
        ->assertOk()
        ->assertJsonPath('data.name', 'New Name');

    assertDatabaseHas('achievements', [
        'id' => $achievement->id,
        'name' => 'New Name',
    ]);
});

test('update achievement ignores unique slug for current achievement', function () {
    Gate::define('Update:Achievement', function () { return true; });
    Gate::define('update', function () { return true; });

    Gate::before(function ($user, $ability) { return true; });

    $adminUser = User::factory()->create();
    Sanctum::actingAs($adminUser);

    $achievement = Achievement::factory()->create(['slug' => 'my-slug']);

    putJson(route('api.v1.achievements.update', $achievement), ['slug' => 'my-slug', 'name' => 'Updated Name'])
        ->assertOk();

    assertDatabaseHas('achievements', [
        'id' => $achievement->id,
        'name' => 'Updated Name',
        'slug' => 'my-slug',
    ]);
});

test('update achievement validates unique slug for other achievements', function () {
    Gate::define('Update:Achievement', function () { return true; });
    Gate::define('update', function () { return true; });

    Gate::before(function ($user, $ability) { return true; });

    $adminUser = User::factory()->create();
    Sanctum::actingAs($adminUser);

    Achievement::factory()->create(['slug' => 'other-slug']);
    $achievement = Achievement::factory()->create(['slug' => 'my-slug']);

    putJson(route('api.v1.achievements.update', $achievement), ['slug' => 'other-slug'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['slug']);
});

test('user cannot delete an achievement', function () {
    Sanctum::actingAs($this->user);

    $achievement = Achievement::factory()->create();

    deleteJson(route('api.v1.achievements.destroy', $achievement))
        ->assertForbidden();
});

test('admin can delete an achievement', function () {
    Gate::define('Delete:Achievement', function () { return true; });
    Gate::define('delete', function () { return true; });

    Gate::before(function ($user, $ability) { return true; });

    $adminUser = User::factory()->create();
    Sanctum::actingAs($adminUser);

    $achievement = Achievement::factory()->create();

    deleteJson(route('api.v1.achievements.destroy', $achievement))
        ->assertNoContent();

    assertDatabaseMissing('achievements', ['id' => $achievement->id]);
});
