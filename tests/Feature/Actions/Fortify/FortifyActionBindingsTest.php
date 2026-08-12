<?php

declare(strict_types=1);

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Actions\Fortify\UpdateUserProfileInformation;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Contracts\CreatesNewUsers;
use Laravel\Fortify\Contracts\ResetsUserPasswords;
use Laravel\Fortify\Contracts\UpdatesUserPasswords;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

it('resolves the application actions for every Fortify contract', function (): void {
    expect(app(CreatesNewUsers::class))->toBeInstanceOf(CreateNewUser::class)
        ->and(app(UpdatesUserProfileInformation::class))->toBeInstanceOf(UpdateUserProfileInformation::class)
        ->and(app(UpdatesUserPasswords::class))->toBeInstanceOf(UpdateUserPassword::class)
        ->and(app(ResetsUserPasswords::class))->toBeInstanceOf(ResetUserPassword::class);
});

it('throttles login to five attempts per minute per lowercased e-mail and ip', function (): void {
    $request = Request::create('/login', 'POST', ['email' => 'Sam@Example.COM'], [], [], [
        'REMOTE_ADDR' => '10.0.0.7',
    ]);

    $limit = RateLimiter::limiter('login')($request);

    expect($limit)->toBeInstanceOf(Limit::class)
        ->and($limit->maxAttempts)->toBe(5)
        ->and($limit->decaySeconds)->toBe(60)
        ->and($limit->key)->toBe('sam@example.com|10.0.0.7');
});

it('builds a login throttling key even when no e-mail is submitted', function (): void {
    $request = Request::create('/login', 'POST', [], [], [], [
        'REMOTE_ADDR' => '10.0.0.8',
    ]);

    $limit = RateLimiter::limiter('login')($request);

    expect($limit->key)->toBe('|10.0.0.8');
});
