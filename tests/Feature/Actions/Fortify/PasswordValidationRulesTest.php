<?php

declare(strict_types=1);

use App\Actions\Fortify\PasswordValidationRules;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Expose the trait's protected rule set through a throwaway consumer, so the
 * policy is asserted on the real rule objects rather than on a copy of them.
 *
 * @return array<int, mixed>
 */
function fortifyPasswordRules(): array
{
    return (new class()
    {
        use PasswordValidationRules;

        /**
         * @return array<int, mixed>
         */
        public function expose(): array
        {
            return $this->passwordRules();
        }
    })->expose();
}

/**
 * @param  array<string, mixed>  $data
 */
function fortifyPasswordFails(array $data): bool
{
    return Validator::make($data, ['password' => fortifyPasswordRules()])->fails();
}

/**
 * The rule names that actually failed, e.g. "Required", "String",
 * "Illuminate\Validation\Rules\Password".
 *
 * @param  array<string, mixed>  $data
 * @return array<int, string>
 */
function fortifyPasswordFailedRules(array $data): array
{
    $validator = Validator::make($data, ['password' => fortifyPasswordRules()]);
    $validator->fails();

    /** @var array<string, array<string, mixed>> $failed */
    $failed = $validator->failed();

    return array_keys($failed['password'] ?? []);
}

/**
 * @return array<string, string>
 */
function fortifyPasswordPayload(string $password): array
{
    return [
        'password' => $password,
        'password_confirmation' => $password,
    ];
}

it('requires a password to be present', function (): void {
    expect(fortifyPasswordFailedRules([]))->toContain('Required')
        ->and(fortifyPasswordFailedRules(['password' => '']))->toContain('Required');
});

it('requires the password to be a string', function (): void {
    expect(fortifyPasswordFailedRules([
        'password' => 12345678,
        'password_confirmation' => 12345678,
    ]))->toContain('String');
});

it('rejects a 7 character password and accepts an 8 character one', function (): void {
    expect(fortifyPasswordFails(fortifyPasswordPayload(str_repeat('a', 7))))->toBeTrue()
        ->and(fortifyPasswordFailedRules(fortifyPasswordPayload(str_repeat('a', 7))))
        ->toContain(Password::class)
        ->and(fortifyPasswordFails(fortifyPasswordPayload(str_repeat('a', 8))))->toBeFalse();
});

it('requires the password to be confirmed', function (): void {
    expect(fortifyPasswordFailedRules([
        'password' => 'correct-horse-8',
        'password_confirmation' => 'correct-horse-9',
    ]))->toContain('Confirmed');

    expect(fortifyPasswordFailedRules(['password' => 'correct-horse-8']))->toContain('Confirmed');
});

it('only demands mixed case once the application runs in production', function (): void {
    $lowercaseOnly = 'lowercaseonly1';

    expect(fortifyPasswordFails(fortifyPasswordPayload($lowercaseOnly)))->toBeFalse();

    config(['app.env' => 'production']);
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);

    expect(fortifyPasswordFails(fortifyPasswordPayload($lowercaseOnly)))->toBeTrue()
        ->and(fortifyPasswordFails(fortifyPasswordPayload('LowercaseOnly1')))->toBeFalse();
});

it('rejects a password found in a public breach when running in production', function (): void {
    config(['app.env' => 'production']);

    $password = 'Str0ngButLeaked';
    $hash = strtoupper(sha1($password));

    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response(substr($hash, 5).':17'),
    ]);

    expect(fortifyPasswordFails(fortifyPasswordPayload($password)))->toBeTrue();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://api.pwnedpasswords.com/range/'.substr($hash, 0, 5));
});

it('accepts a password absent from public breaches when running in production', function (): void {
    config(['app.env' => 'production']);

    Http::fake([
        'api.pwnedpasswords.com/*' => Http::response('FFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFFF:3'),
    ]);

    expect(fortifyPasswordFails(fortifyPasswordPayload('Str0ngButLeaked')))->toBeFalse();
});
