<?php

declare(strict_types=1);

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->resetUserPassword = app(ResetUserPassword::class);
    $this->user = User::factory()->create();
});

it('rehashes the password without asking for the current one', function (): void {
    $this->resetUserPassword->reset($this->user, [
        'password' => 'reset-secret-1',
        'password_confirmation' => 'reset-secret-1',
    ]);

    $stored = DB::table('users')->where('id', $this->user->id)->value('password');

    expect($stored)->toBeString()
        ->and($stored)->not->toBe('reset-secret-1')
        ->and($stored)->toStartWith('$')
        ->and(Hash::check('reset-secret-1', $stored))->toBeTrue()
        ->and(Hash::check('password', $stored))->toBeFalse();
});

it('applies the shared password policy on reset', function (): void {
    expect(fn () => $this->resetUserPassword->reset($this->user, [
        'password' => 'short7c',
        'password_confirmation' => 'short7c',
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors())->toHaveKey('password');
    });

    expect(Hash::check('password', $this->user->fresh()->password))->toBeTrue();
});

it('requires the reset password to be confirmed', function (): void {
    expect(fn () => $this->resetUserPassword->reset($this->user, [
        'password' => 'reset-secret-1',
        'password_confirmation' => 'reset-secret-2',
    ]))->toThrow(function (ValidationException $exception): void {
        expect($exception->errors())->toHaveKey('password');
    });

    expect(Hash::check('password', $this->user->fresh()->password))->toBeTrue();
});

it('requires a password to be supplied', function (): void {
    expect(fn () => $this->resetUserPassword->reset($this->user, []))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toHaveKey('password');
        });

    expect(Hash::check('password', $this->user->fresh()->password))->toBeTrue();
});
