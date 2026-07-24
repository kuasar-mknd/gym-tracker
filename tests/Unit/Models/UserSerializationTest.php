<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Fortify\TwoFactorAuthenticatable;

it('declares sensitive attributes as hidden', function (): void {
    /** @var array<string, mixed> $defaults */
    $defaults = (new ReflectionClass(User::class))->getDefaultProperties();
    $hidden = $defaults['hidden'] ?? [];

    expect($hidden)
        ->toContain('password')
        ->toContain('remember_token')
        ->toContain('two_factor_secret')
        ->toContain('two_factor_recovery_codes');
});

it('uses the Fortify two-factor authentication trait', function (): void {
    expect(class_uses_recursive(User::class))
        ->toContain(TwoFactorAuthenticatable::class);

    expect(method_exists(User::class, 'hasEnabledTwoFactorAuthentication'))->toBeTrue();
});
