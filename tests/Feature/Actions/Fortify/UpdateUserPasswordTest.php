<?php

declare(strict_types=1);

use App\Actions\Fortify\UpdateUserPassword;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->updateUserPassword = app(UpdateUserPassword::class);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

it('replaces the password with a new hash when the current password is correct', function (): void {
    $this->updateUserPassword->update($this->user, [
        'current_password' => 'password',
        'password' => 'brand-new-secret',
        'password_confirmation' => 'brand-new-secret',
    ]);

    $stored = DB::table('users')->where('id', $this->user->id)->value('password');

    expect($stored)->toBeString()
        ->and($stored)->not->toBe('brand-new-secret')
        ->and($stored)->toStartWith('$')
        ->and(Hash::check('brand-new-secret', $stored))->toBeTrue()
        ->and(Hash::check('password', $stored))->toBeFalse();
});

it('refuses to change the password when the current password is wrong', function (): void {
    expect(fn () => $this->updateUserPassword->update($this->user, [
        'current_password' => 'not-my-password',
        'password' => 'brand-new-secret',
        'password_confirmation' => 'brand-new-secret',
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errorBag)->toBe('updatePassword')
            ->and($exception->errors())->toHaveKey('current_password')
            ->and($exception->errors()['current_password'][0])
            ->not->toBe(trans('validation.current_password'));
    });

    expect(Hash::check('password', $this->user->fresh()->password))->toBeTrue();
});

it('refuses to change the password when the current password is missing', function (): void {
    expect(fn () => $this->updateUserPassword->update($this->user, [
        'password' => 'brand-new-secret',
        'password_confirmation' => 'brand-new-secret',
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors())->toHaveKey('current_password');
    });

    expect(Hash::check('password', $this->user->fresh()->password))->toBeTrue();
});

it('applies the shared password policy to the new password', function (): void {
    expect(fn () => $this->updateUserPassword->update($this->user, [
        'current_password' => 'password',
        'password' => 'short7c',
        'password_confirmation' => 'short7c',
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errorBag)->toBe('updatePassword')
            ->and($exception->errors())->toHaveKey('password');
    });

    expect(Hash::check('password', $this->user->fresh()->password))->toBeTrue();
});

it('requires the new password to be confirmed', function (): void {
    expect(fn () => $this->updateUserPassword->update($this->user, [
        'current_password' => 'password',
        'password' => 'brand-new-secret',
        'password_confirmation' => 'a-different-secret',
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors())->toHaveKey('password');
    });

    expect(Hash::check('password', $this->user->fresh()->password))->toBeTrue();
});
