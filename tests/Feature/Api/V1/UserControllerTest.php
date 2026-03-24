<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Guest', function (): void {
    test('cannot list users', function (): void {
        getJson(route('api.v1.users.index'))->assertUnauthorized();
    });

    test('cannot create user', function (): void {
        postJson(route('api.v1.users.store'), [])->assertUnauthorized();
    });

    test('cannot view user', function (): void {
        $user = User::factory()->create();
        getJson(route('api.v1.users.show', $user))->assertUnauthorized();
    });

    test('cannot update user', function (): void {
        $user = User::factory()->create();
        putJson(route('api.v1.users.update', $user), [])->assertUnauthorized();
    });

    test('cannot delete user', function (): void {
        $user = User::factory()->create();
        deleteJson(route('api.v1.users.destroy', $user))->assertUnauthorized();
    });
});

describe('Authenticated', function (): void {
    beforeEach(function (): void {
        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);
    });

    describe('Index', function (): void {
        test('authorized user can list users', function (): void {
            Gate::before(fn (): true => true);

            User::factory()->count(3)->create();

            $response = getJson(route('api.v1.users.index'));

            $response->assertOk()
                ->assertJsonStructure([
                    'data' => [
                        '*' => ['id', 'name', 'email'],
                    ],
                    'links',
                    'meta',
                ]);
        });

        test('unauthorized user cannot list users', function (): void {
            Gate::before(fn (): false => false);

            $response = getJson(route('api.v1.users.index'));

            $response->assertForbidden();
        });
    });

    describe('Store', function (): void {
        test('authorized user can create a user', function (): void {
            Gate::before(fn (): true => true);

            $data = [
                'name' => 'New Test User',
                'email' => 'newuser@example.com',
                'password' => 'secret123',
                'default_rest_time' => 90,
            ];

            $response = postJson(route('api.v1.users.store'), $data);

            $response->assertCreated()
                ->assertJsonPath('data.name', 'New Test User')
                ->assertJsonPath('data.email', 'newuser@example.com');

            assertDatabaseHas('users', [
                'name' => 'New Test User',
                'email' => 'newuser@example.com',
                'default_rest_time' => 90,
            ]);

            $createdUser = User::where('email', 'newuser@example.com')->first();
            expect(Hash::check('secret123', $createdUser->password))->toBeTrue();
        });

        test('unauthorized user cannot create a user', function (): void {
            Gate::before(fn (): false => false);

            $response = postJson(route('api.v1.users.store'), [
                'name' => 'Unauthorized User',
                'email' => 'unauthorized@example.com',
                'password' => 'password',
            ]);

            $response->assertForbidden();
        });

        test('validation: required fields', function (): void {
            Gate::before(fn (): true => true);

            postJson(route('api.v1.users.store'), [])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['name', 'email', 'password']);
        });

        test('validation: email must be unique', function (): void {
            Gate::before(fn (): true => true);

            $existingUser = User::factory()->create();

            postJson(route('api.v1.users.store'), [
                'name' => 'Another User',
                'email' => $existingUser->email,
                'password' => 'password',
            ])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['email']);
        });
    });

    describe('Show', function (): void {
        test('authorized user can view a user', function (): void {
            Gate::before(fn (): true => true);

            $userToView = User::factory()->create();

            getJson(route('api.v1.users.show', $userToView))
                ->assertOk()
                ->assertJsonPath('data.id', $userToView->id)
                ->assertJsonPath('data.name', $userToView->name);
        });

        test('unauthorized user cannot view a user', function (): void {
            Gate::before(fn (): false => false);

            $userToView = User::factory()->create();

            getJson(route('api.v1.users.show', $userToView))
                ->assertForbidden();
        });
    });

    describe('Update', function (): void {
        test('authorized user can update a user', function (): void {
            Gate::before(fn (): true => true);

            $userToUpdate = User::factory()->create(['name' => 'Old Name']);

            putJson(route('api.v1.users.update', $userToUpdate), [
                'name' => 'New Name',
                'password' => 'newsecret123',
            ])
                ->assertOk()
                ->assertJsonPath('data.name', 'New Name');

            assertDatabaseHas('users', [
                'id' => $userToUpdate->id,
                'name' => 'New Name',
            ]);

            $updatedUser = $userToUpdate->fresh();
            expect(Hash::check('newsecret123', $updatedUser->password))->toBeTrue();
        });

        test('unauthorized user cannot update a user', function (): void {
            Gate::before(fn (): false => false);

            $userToUpdate = User::factory()->create();

            putJson(route('api.v1.users.update', $userToUpdate), ['name' => 'Unauthorized Name'])
                ->assertForbidden();
        });

        test('validation: email unique ignore self', function (): void {
            Gate::before(fn (): true => true);

            $userToUpdate = User::factory()->create();

            putJson(route('api.v1.users.update', $userToUpdate), ['email' => $userToUpdate->email])
                ->assertOk()
                ->assertJsonPath('data.email', $userToUpdate->email);
        });

        test('validation: email unique conflicts with another user', function (): void {
            Gate::before(fn (): true => true);

            $userToUpdate = User::factory()->create();
            $anotherUser = User::factory()->create();

            putJson(route('api.v1.users.update', $userToUpdate), ['email' => $anotherUser->email])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['email']);
        });
    });

    describe('Destroy', function (): void {
        test('authorized user can delete a user', function (): void {
            Gate::before(fn (): true => true);

            $userToDelete = User::factory()->create();

            deleteJson(route('api.v1.users.destroy', $userToDelete))
                ->assertNoContent();

            assertDatabaseMissing('users', ['id' => $userToDelete->id]);
        });

        test('unauthorized user cannot delete a user', function (): void {
            Gate::before(fn (): false => false);

            $userToDelete = User::factory()->create();

            deleteJson(route('api.v1.users.destroy', $userToDelete))
                ->assertForbidden();

            assertDatabaseHas('users', ['id' => $userToDelete->id]);
        });
    });
});
