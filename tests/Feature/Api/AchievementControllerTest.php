<?php

declare(strict_types=1);

use App\Models\Achievement;
use App\Models\Admin;
use App\Models\User;
use Spatie\Permission\Models\Permission;

use function Pest\Laravel\actingAs;

it('allows viewing achievements list for authenticated users', function (): void {
    $user = User::factory()->create();
    Achievement::factory()->count(3)->create();

    $response = actingAs($user)->getJson(route('api.v1.achievements.index'));

    $response->assertOk()
        ->assertJsonCount(3, 'data')
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
        ]);
});

it('allows viewing a specific achievement for authenticated users', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();

    $response = actingAs($user)->getJson(route('api.v1.achievements.show', $achievement));

    $response->assertOk()
        ->assertJson([
            'data' => [
                'id' => $achievement->id,
                'slug' => $achievement->slug,
            ],
        ]);
});

it('prevents regular users from creating achievements', function (): void {
    $user = User::factory()->create();

    $response = actingAs($user)->postJson(route('api.v1.achievements.store'), [
        'slug' => 'test-achievement',
        'name' => 'Test Achievement',
        'description' => 'Test Description',
        'icon' => 'test-icon',
        'type' => 'test-type',
        'threshold' => 10,
        'category' => 'test-category',
    ]);

    $response->assertForbidden();
});

it('prevents regular users from updating achievements', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();

    $response = actingAs($user)->patchJson(route('api.v1.achievements.update', $achievement), [
        'name' => 'Updated Name',
    ]);

    $response->assertForbidden();
});

it('prevents regular users from deleting achievements', function (): void {
    $user = User::factory()->create();
    $achievement = Achievement::factory()->create();

    $response = actingAs($user)->deleteJson(route('api.v1.achievements.destroy', $achievement));

    $response->assertForbidden();
});

// For Admin tests: using the guard 'sanctum' is required since the api routes use `auth:sanctum`
it('allows creating achievements for users with permission', function (): void {
    $admin = Admin::factory()->create();
    Permission::firstOrCreate(['name' => 'Create:Achievement', 'guard_name' => 'admin']);
    $admin->givePermissionTo('Create:Achievement');

    $response = actingAs($admin, 'sanctum')->postJson(route('api.v1.achievements.store'), [
        'slug' => 'test-achievement',
        'name' => 'Test Achievement',
        'description' => 'Test Description',
        'icon' => 'test-icon',
        'type' => 'test-type',
        'threshold' => 10,
        'category' => 'test-category',
    ]);

    $response->assertCreated()
        ->assertJson([
            'data' => [
                'slug' => 'test-achievement',
                'name' => 'Test Achievement',
            ],
        ]);

    $this->assertDatabaseHas('achievements', [
        'slug' => 'test-achievement',
        'name' => 'Test Achievement',
    ]);
});

it('allows updating achievements for users with permission', function (): void {
    $admin = Admin::factory()->create();
    Permission::firstOrCreate(['name' => 'Update:Achievement', 'guard_name' => 'admin']);
    $admin->givePermissionTo('Update:Achievement');
    $achievement = Achievement::factory()->create();

    $response = actingAs($admin, 'sanctum')->patchJson(route('api.v1.achievements.update', $achievement), [
        'name' => 'Updated Name',
    ]);

    $response->assertOk()
        ->assertJson([
            'data' => [
                'id' => $achievement->id,
                'name' => 'Updated Name',
            ],
        ]);

    $this->assertDatabaseHas('achievements', [
        'id' => $achievement->id,
        'name' => 'Updated Name',
    ]);
});

it('allows deleting achievements for users with permission', function (): void {
    $admin = Admin::factory()->create();
    Permission::firstOrCreate(['name' => 'Delete:Achievement', 'guard_name' => 'admin']);
    $admin->givePermissionTo('Delete:Achievement');
    $achievement = Achievement::factory()->create();

    $response = actingAs($admin, 'sanctum')->deleteJson(route('api.v1.achievements.destroy', $achievement));

    $response->assertNoContent();

    $this->assertDatabaseMissing('achievements', [
        'id' => $achievement->id,
    ]);
});
