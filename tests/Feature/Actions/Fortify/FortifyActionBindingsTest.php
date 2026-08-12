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
use PHPUnit\Framework\Assert;

/**
 * The Limit the 'login' limiter builds for a request.
 *
 * RateLimiter::limiter() returns a nullable Closure, and a Closure returns
 * mixed: both are checked here so a limiter that was never registered, or that
 * stops returning a Limit, fails the test by name instead of crashing on a
 * property access.
 */
function loginLimiterFor(Request $request): Limit
{
    $limiter = RateLimiter::limiter('login');

    Assert::assertNotNull($limiter, "aucun limiteur 'login' n'est enregistré");

    $limit = $limiter($request);

    Assert::assertInstanceOf(Limit::class, $limit);

    return $limit;
}

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

    $limit = loginLimiterFor($request);

    expect($limit->maxAttempts)->toBe(5)
        ->and($limit->decaySeconds)->toBe(60)
        ->and($limit->key)->toBe('sam@example.com|10.0.0.7');
});

it('builds a login throttling key even when no e-mail is submitted', function (): void {
    $request = Request::create('/login', 'POST', [], [], [], [
        'REMOTE_ADDR' => '10.0.0.8',
    ]);

    $limit = loginLimiterFor($request);

    expect($limit->key)->toBe('|10.0.0.8');
});
