<?php

declare(strict_types=1);

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * The action under test, resolved through the container.
 *
 * Resolved in each test rather than parked on $this by beforeEach: Pest binds
 * the test closure at run time, so a fixture held on the test case reaches the
 * body untyped and everything read off its result analyses as a dereference of
 * an unknown value.
 */
function createNewUserAction(): CreateNewUser
{
    return app(CreateNewUser::class);
}

it('creates the user and never stores the password in clear text', function (): void {
    $user = createNewUserAction()->create([
        'name' => 'Sam Dulex',
        'email' => 'sam@example.com',
        'password' => 'correct-horse-8',
        'password_confirmation' => 'correct-horse-8',
    ]);

    $stored = DB::table('users')->where('id', $user->id)->value('password');

    expect($user)->toBeInstanceOf(User::class)
        ->and($user->exists)->toBeTrue()
        ->and($stored)->toBeString()
        ->and($stored)->not->toBe('correct-horse-8')
        ->and($stored)->toStartWith('$')
        ->and(Hash::check('correct-horse-8', $stored))->toBeTrue();

    $this->assertDatabaseHas('users', [
        'id' => $user->id,
        'name' => 'Sam Dulex',
        'email' => 'sam@example.com',
        'email_verified_at' => null,
    ]);
});

it('rejects an e-mail that is already registered', function (): void {
    User::factory()->create(['email' => 'taken@example.com']);

    expect(fn (): \App\Models\User => createNewUserAction()->create([
        'name' => 'Imposteur',
        'email' => 'taken@example.com',
        'password' => 'correct-horse-8',
        'password_confirmation' => 'correct-horse-8',
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors())->toHaveKey('email');
    });

    expect(User::query()->where('email', 'taken@example.com')->count())->toBe(1);
    expect(User::query()->where('name', 'Imposteur')->exists())->toBeFalse();
});

it('rejects a registration without a name', function (): void {
    expect(fn (): \App\Models\User => createNewUserAction()->create([
        'name' => '',
        'email' => 'nameless@example.com',
        'password' => 'correct-horse-8',
        'password_confirmation' => 'correct-horse-8',
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors())->toHaveKey('name');
    });

    $this->assertDatabaseMissing('users', ['email' => 'nameless@example.com']);
});

it('rejects a malformed e-mail address', function (): void {
    expect(fn (): \App\Models\User => createNewUserAction()->create([
        'name' => 'Sam Dulex',
        'email' => 'not-an-email',
        'password' => 'correct-horse-8',
        'password_confirmation' => 'correct-horse-8',
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors())->toHaveKey('email');
    });

    expect(User::query()->count())->toBe(0);
});

it('accepts a 255 character name but rejects a 256 character one', function (): void {
    $accepted = str_repeat('a', 255);
    $rejected = str_repeat('a', 256);

    $user = createNewUserAction()->create([
        'name' => $accepted,
        'email' => 'long-but-ok@example.com',
        'password' => 'correct-horse-8',
        'password_confirmation' => 'correct-horse-8',
    ]);

    expect(DB::table('users')->where('id', $user->id)->value('name'))->toBe($accepted);

    expect(fn (): \App\Models\User => createNewUserAction()->create([
        'name' => $rejected,
        'email' => 'too-long@example.com',
        'password' => 'correct-horse-8',
        'password_confirmation' => 'correct-horse-8',
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors())->toHaveKey('name');
    });

    $this->assertDatabaseMissing('users', ['email' => 'too-long@example.com']);
});

it('applies the shared password policy at registration', function (): void {
    expect(fn (): \App\Models\User => createNewUserAction()->create([
        'name' => 'Sam Dulex',
        'email' => 'weak@example.com',
        'password' => 'short7c',
        'password_confirmation' => 'short7c',
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors())->toHaveKey('password');
    });

    $this->assertDatabaseMissing('users', ['email' => 'weak@example.com']);
});

it('rejects a registration whose password confirmation does not match', function (): void {
    expect(fn (): \App\Models\User => createNewUserAction()->create([
        'name' => 'Sam Dulex',
        'email' => 'mismatch@example.com',
        'password' => 'correct-horse-8',
        'password_confirmation' => 'correct-horse-9',
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors())->toHaveKey('password');
    });

    $this->assertDatabaseMissing('users', ['email' => 'mismatch@example.com']);
});
