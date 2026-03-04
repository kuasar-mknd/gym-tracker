<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Achievement;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // Setup regular user
    $this->user = User::factory()->create();

    // Setup admin user with correct permissions
    $this->admin = Admin::factory()->create();

    // Admin needs these permissions per the policy:
    $permissions = [
        'ViewAny:Achievement',
        'View:Achievement',
        'Create:Achievement',
        'Update:Achievement',
        'Delete:Achievement',
    ];

    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'admin']);
    }

    $role = Role::firstOrCreate(['name' => 'super-admin', 'guard_name' => 'admin']);
    $role->syncPermissions($permissions);
    $this->admin->assignRole($role);
});

describe('authorization middleware', function (): void {
    it('blocks access to non-admins for protected routes', function (): void {
        $achievement = Achievement::factory()->create();

        $this->actingAs($this->user)
            ->postJson('/api/v1/achievements', [])
            ->assertForbidden();

        $this->actingAs($this->user)
            ->putJson("/api/v1/achievements/{$achievement->id}", [])
            ->assertForbidden();

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/achievements/{$achievement->id}")
            ->assertForbidden();
    });
});

describe('index', function (): void {
    it('returns achievements for authenticated users', function (): void {
        Achievement::factory()->count(3)->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/achievements');

        $response->assertOk()
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
                    ],
                ],
                'links',
                'meta',
            ])
            ->assertJsonCount(3, 'data');
    });

    it('returns unauthenticated for guests', function (): void {
        $response = $this->getJson('/api/v1/achievements');

        $response->assertUnauthorized();
    });
});

describe('store', function (): void {
    it('allows authorized admin to create an achievement', function (): void {
        $data = [
            'slug' => 'test-achievement',
            'name' => 'Test Achievement',
            'description' => 'Test description',
            'icon' => '🏆',
            'type' => 'workout_count',
            'threshold' => 10,
            'category' => 'beginner',
        ];

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/achievements', $data);

        $response->assertCreated()
            ->assertJsonPath('data.slug', 'test-achievement');

        $this->assertDatabaseHas('achievements', ['slug' => 'test-achievement']);
    });

    it('denies regular users from creating an achievement', function (): void {
        $data = [
            'slug' => 'test-achievement-2',
            'name' => 'Test Achievement 2',
            'description' => 'Test description',
            'icon' => '🏆',
            'type' => 'workout_count',
            'threshold' => 10,
            'category' => 'beginner',
        ];

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/achievements', $data);

        $response->assertForbidden();
        $this->assertDatabaseMissing('achievements', ['slug' => 'test-achievement-2']);
    });

    it('validates required fields', function (): void {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/achievements', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'slug', 'name', 'description', 'icon', 'type', 'threshold', 'category',
            ]);
    });

    it('validates unique slug', function (): void {
        $existing = Achievement::factory()->create(['slug' => 'duplicate-slug']);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/v1/achievements', [
                'slug' => 'duplicate-slug',
                'name' => 'Name',
                'description' => 'Desc',
                'icon' => '🏆',
                'type' => 'type',
                'threshold' => 1,
                'category' => 'cat',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['slug']);
    });
});

describe('show', function (): void {
    it('returns a specific achievement for authenticated users', function (): void {
        $achievement = Achievement::factory()->create();

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/achievements/{$achievement->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $achievement->id)
            ->assertJsonPath('data.name', $achievement->name);
    });

    it('returns unauthenticated for guests', function (): void {
        $achievement = Achievement::factory()->create();

        $response = $this->getJson("/api/v1/achievements/{$achievement->id}");

        $response->assertUnauthorized();
    });

    it('returns 404 for non-existent achievement', function (): void {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/achievements/999999');

        $response->assertNotFound();
    });
});

describe('update', function (): void {
    it('allows authorized admin to update an achievement', function (): void {
        $achievement = Achievement::factory()->create([
            'name' => 'Old Name',
        ]);

        $data = [
            'slug' => 'updated-slug',
            'name' => 'Updated Name',
            'description' => 'Updated description',
            'icon' => '🥇',
            'type' => 'streak',
            'threshold' => 20,
            'category' => 'advanced',
        ];

        $response = $this->actingAs($this->admin)
            ->putJson("/api/v1/achievements/{$achievement->id}", $data);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Name');

        $this->assertDatabaseHas('achievements', [
            'id' => $achievement->id,
            'name' => 'Updated Name',
        ]);
    });

    it('denies regular users from updating an achievement', function (): void {
        $achievement = Achievement::factory()->create([
            'name' => 'Old Name',
        ]);

        $data = [
            'slug' => 'updated-slug-2',
            'name' => 'Updated Name 2',
            'description' => 'Updated description',
            'icon' => '🥇',
            'type' => 'streak',
            'threshold' => 20,
            'category' => 'advanced',
        ];

        $response = $this->actingAs($this->user)
            ->putJson("/api/v1/achievements/{$achievement->id}", $data);

        $response->assertForbidden();

        $this->assertDatabaseHas('achievements', [
            'id' => $achievement->id,
            'name' => 'Old Name',
        ]);
    });

    it('validates sometimes fields on update', function (): void {
        $achievement = Achievement::factory()->create();

        // `sometimes` means validation only runs if the field is present,
        // but it is `required` if present. So we send empty strings to trigger failure.
        $data = [
            'slug' => '',
            'name' => '',
            'description' => '',
            'icon' => '',
            'type' => '',
            'threshold' => '',
            'category' => '',
        ];

        $response = $this->actingAs($this->admin)
            ->putJson("/api/v1/achievements/{$achievement->id}", $data);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors([
                'slug', 'name', 'description', 'icon', 'type', 'threshold', 'category',
            ]);
    });
});

describe('destroy', function (): void {
    it('allows authorized admin to delete an achievement', function (): void {
        $achievement = Achievement::factory()->create();

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/v1/achievements/{$achievement->id}");

        $response->assertNoContent();

        $this->assertDatabaseMissing('achievements', ['id' => $achievement->id]);
    });

    it('denies regular users from deleting an achievement', function (): void {
        $achievement = Achievement::factory()->create();

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/achievements/{$achievement->id}");

        $response->assertForbidden();

        $this->assertDatabaseHas('achievements', ['id' => $achievement->id]);
    });
});
