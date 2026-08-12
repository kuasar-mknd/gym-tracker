<?php

declare(strict_types=1);

use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

use function PHPUnit\Framework\assertIsString;
use function PHPUnit\Framework\assertNotNull;

beforeEach(function (): void {
    $this->updateUserPassword = app(UpdateUserPassword::class);
});

it('replaces the password with a new hash when the current password is correct', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->updateUserPassword->update($user, [
        'current_password' => 'password',
        'password' => 'brand-new-secret',
        'password_confirmation' => 'brand-new-secret',
    ]);

    $stored = DB::table('users')->where('id', $user->id)->value('password');

    assertIsString($stored, "La colonne password de l'utilisateur ne contient pas de chaine.");
    expect($stored)->not->toBe('brand-new-secret')
        ->and($stored)->toStartWith('$')
        ->and(Hash::check('brand-new-secret', $stored))->toBeTrue()
        ->and(Hash::check('password', $stored))->toBeFalse();
});

it('refuses to change the password when the current password is wrong', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(fn () => $this->updateUserPassword->update($user, [
        'current_password' => 'not-my-password',
        'password' => 'brand-new-secret',
        'password_confirmation' => 'brand-new-secret',
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errorBag)->toBe('updatePassword')
            ->and($exception->errors())->toHaveKey('current_password')
            ->and($exception->errors()['current_password'][0])
            ->not->toBe(trans('validation.current_password'));
    });

    $stored = $user->refresh()->password;

    assertNotNull($stored, "L'utilisateur n'a plus de mot de passe en base.");
    expect(Hash::check('password', $stored))->toBeTrue();
});

it('refuses to change the password when the current password is missing', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(fn () => $this->updateUserPassword->update($user, [
        'password' => 'brand-new-secret',
        'password_confirmation' => 'brand-new-secret',
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors())->toHaveKey('current_password');
    });

    $stored = $user->refresh()->password;

    assertNotNull($stored, "L'utilisateur n'a plus de mot de passe en base.");
    expect(Hash::check('password', $stored))->toBeTrue();
});

it('applies the shared password policy to the new password', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(fn () => $this->updateUserPassword->update($user, [
        'current_password' => 'password',
        'password' => 'short7c',
        'password_confirmation' => 'short7c',
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errorBag)->toBe('updatePassword')
            ->and($exception->errors())->toHaveKey('password');
    });

    $stored = $user->refresh()->password;

    assertNotNull($stored, "L'utilisateur n'a plus de mot de passe en base.");
    expect(Hash::check('password', $stored))->toBeTrue();
});

it('requires the new password to be confirmed', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(fn () => $this->updateUserPassword->update($user, [
        'current_password' => 'password',
        'password' => 'brand-new-secret',
        'password_confirmation' => 'a-different-secret',
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors())->toHaveKey('password');
    });

    $stored = $user->refresh()->password;

    assertNotNull($stored, "L'utilisateur n'a plus de mot de passe en base.");
    expect(Hash::check('password', $stored))->toBeTrue();
});
