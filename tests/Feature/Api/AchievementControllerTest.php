<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

use function Pest\Laravel\actingAs;

test('authenticated user can list achievements', function (): void {
    $user = User::factory()->create();
    Achievement::factory()->count(3)->create();

    actingAs($user)
        ->getJson(route('api.v1.achievements.index'))
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('user can view specific achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();

    actingAs($user)
        ->getJson(route('api.v1.achievements.show', $achievement))
        ->assertOk()
        ->assertJsonPath('data.id', $achievement->id);
});

test('user with permission can create achievement', function (): void {
    $user = User::factory()->create();
    Gate::define('Create:Achievement', fn (): true => true);

    $data = [
        'name' => 'New Achievement',
        'slug' => 'new-achievement',
        'description' => 'Description',
        'icon' => 'icon-name',
        'type' => 'strength',
        'threshold' => 100,
        'category' => 'general',
    ];

    actingAs($user)
        ->postJson(route('api.v1.achievements.store'), $data)
        ->assertCreated()
        ->assertJsonPath('data.name', 'New Achievement');

    $this->assertDatabaseHas('achievements', ['slug' => 'new-achievement']);
});

test('user without permission cannot create achievement', function (): void {
    $user = User::factory()->create();
    // No gate definition, or explicitly false
    Gate::define('Create:Achievement', fn (): false => false);

    $data = [
        'name' => 'New Achievement',
        'slug' => 'new-achievement',
        'description' => 'Description',
        'icon' => 'icon-name',
        'type' => 'strength',
        'threshold' => 100,
        'category' => 'general',
    ];

    actingAs($user)
        ->postJson(route('api.v1.achievements.store'), $data)
        ->assertForbidden();
});

test('user with permission can update achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();
    Gate::define('Update:Achievement', fn (): true => true);

    $data = [
        'name' => 'Updated Name',
        'slug' => 'updated-slug',
        'description' => 'Updated Description',
        'icon' => 'updated-icon',
        'type' => 'strength',
        'threshold' => 200,
        'category' => 'updated',
    ];

    actingAs($user)
        ->putJson(route('api.v1.achievements.update', $achievement), $data)
        ->assertOk()
        ->assertJsonPath('data.name', 'Updated Name');

    $this->assertDatabaseHas('achievements', ['slug' => 'updated-slug']);
});

test('user with permission can delete achievement', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();
    Gate::define('Delete:Achievement', fn (): true => true);

    actingAs($user)
        ->deleteJson(route('api.v1.achievements.destroy', $achievement))
        ->assertNoContent();

    $this->assertDatabaseMissing('achievements', ['id' => $achievement->id]);
});

test('filtering achievements by name', function (): void {
    $user = User::factory()->create();
    Achievement::factory()->create(['name' => 'Alpha']);
    Achievement::factory()->create(['name' => 'Beta']);

    actingAs($user)
        ->getJson(route('api.v1.achievements.index', ['filter[name]' => 'Alpha']))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Alpha');
});
