<?php

declare(strict_types=1);

use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

describe('index', function (): void {
    it('returns a list of admins when authorized', function (): void {
        Gate::before(fn (): true => true);

        Admin::factory()->count(3)->create();

        $response = $this->getJson(route('api.v1.admins.index'));

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'email', 'created_at', 'updated_at'],
                ],
                'meta',
                'links',
            ]);
    });

    it('returns 403 forbidden when unauthorized', function (): void {
        Admin::factory()->count(3)->create();

        $response = $this->getJson(route('api.v1.admins.index'));

        $response->assertForbidden();
    });
});

describe('store', function (): void {
    it('creates a new admin when authorized and data is valid', function (): void {
        Gate::before(fn (): true => true);

        $data = [
            'name' => 'New Admin',
            'email' => 'new.admin@example.com',
            'password' => 'password123',
        ];

        $response = $this->postJson(route('api.v1.admins.store'), $data);

        $response->assertCreated()
            ->assertJsonFragment([
                'name' => 'New Admin',
                'email' => 'new.admin@example.com',
            ]);

        $this->assertDatabaseHas('admins', [
            'name' => 'New Admin',
            'email' => 'new.admin@example.com',
        ]);
    });

    it('returns 422 unprocessable when validation fails', function (): void {
        Gate::before(fn (): true => true);

        $response = $this->postJson(route('api.v1.admins.store'), []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
    });

    it('returns 422 unprocessable when email is not unique', function (): void {
        Gate::before(fn (): true => true);

        $existingAdmin = Admin::factory()->create();

        $data = [
            'name' => 'Another Admin',
            'email' => $existingAdmin->email,
            'password' => 'password123',
        ];

        $response = $this->postJson(route('api.v1.admins.store'), $data);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    it('returns 403 forbidden when unauthorized', function (): void {
        $data = [
            'name' => 'New Admin',
            'email' => 'new.admin@example.com',
            'password' => 'password123',
        ];

        $response = $this->postJson(route('api.v1.admins.store'), $data);

        $response->assertForbidden();
    });
});

describe('show', function (): void {
    it('returns the admin when authorized', function (): void {
        Gate::before(fn (): true => true);

        $admin = Admin::factory()->create();

        $response = $this->getJson(route('api.v1.admins.show', $admin));

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
            ]);
    });

    it('returns 404 not found when admin does not exist', function (): void {
        Gate::before(fn (): true => true);

        $response = $this->getJson(route('api.v1.admins.show', 99999));

        $response->assertNotFound();
    });

    it('returns 403 forbidden when unauthorized', function (): void {
        $admin = Admin::factory()->create();

        $response = $this->getJson(route('api.v1.admins.show', $admin));

        $response->assertForbidden();
    });
});

describe('update', function (): void {
    it('updates the admin when authorized and data is valid', function (): void {
        Gate::before(fn (): true => true);

        $admin = Admin::factory()->create();

        $data = [
            'name' => 'Updated Admin Name',
        ];

        $response = $this->patchJson(route('api.v1.admins.update', $admin), $data);

        $response->assertOk()
            ->assertJsonFragment([
                'id' => $admin->id,
                'name' => 'Updated Admin Name',
                'email' => $admin->email,
            ]);

        $this->assertDatabaseHas('admins', [
            'id' => $admin->id,
            'name' => 'Updated Admin Name',
        ]);
    });

    it('returns 422 unprocessable when email is not unique', function (): void {
        Gate::before(fn (): true => true);

        $admin1 = Admin::factory()->create();
        $admin2 = Admin::factory()->create();

        $data = [
            'email' => $admin2->email,
        ];

        $response = $this->patchJson(route('api.v1.admins.update', $admin1), $data);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);
    });

    it('returns 403 forbidden when unauthorized', function (): void {
        $admin = Admin::factory()->create();

        $data = [
            'name' => 'Updated Admin Name',
        ];

        $response = $this->patchJson(route('api.v1.admins.update', $admin), $data);

        $response->assertForbidden();
    });
});

describe('destroy', function (): void {
    it('deletes the admin when authorized', function (): void {
        Gate::before(fn (): true => true);

        $admin = Admin::factory()->create();

        $response = $this->deleteJson(route('api.v1.admins.destroy', $admin));

        $response->assertNoContent();

        $this->assertDatabaseMissing('admins', [
            'id' => $admin->id,
        ]);
    });

    it('returns 404 not found when admin does not exist', function (): void {
        Gate::before(fn (): true => true);

        $response = $this->deleteJson(route('api.v1.admins.destroy', 99999));

        $response->assertNotFound();
    });

    it('returns 403 forbidden when unauthorized', function (): void {
        $admin = Admin::factory()->create();

        $response = $this->deleteJson(route('api.v1.admins.destroy', $admin));

        $response->assertForbidden();
    });
});
