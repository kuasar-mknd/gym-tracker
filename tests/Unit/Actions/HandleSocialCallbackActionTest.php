<?php

declare(strict_types=1);

use App\Actions\HandleSocialCallbackAction;
use App\Actions\ResolveSocialUserAction;
use App\Exceptions\SocialAuthException;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(Tests\TestCase::class);

it('throws SocialAuthException when Socialite fails', function (): void {
    // Arrange
    $provider = 'github';

    // Mock the Socialite provider to throw an exception
    $socialiteProvider = Mockery::mock(\Laravel\Socialite\Contracts\Provider::class);
    $socialiteProvider->shouldReceive('user')
        ->once()
        ->andThrow(new \Exception('Connection failed'));

    Socialite::shouldReceive('driver')
        ->with($provider)
        ->once()
        ->andReturn($socialiteProvider);

    // We cannot mock ResolveSocialUserAction because it's final
    // But since the exception is thrown before it's used, we can just resolve it
    $resolver = app(ResolveSocialUserAction::class);

    $action = new HandleSocialCallbackAction($resolver);

    // Act & Assert
    $action->execute($provider);
})->throws(SocialAuthException::class, 'Erreur lors de la connexion avec Github');

it('bypasses email verification and logs a warning in local environment', function (): void {
    $provider = 'github';

    $socialUser = new SocialiteUser();
    $socialUser->user = ['email_verified' => false];
    $socialUser->map([
        'id' => '123',
        'nickname' => 'testuser',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'avatar' => 'avatar.jpg',
    ]);

    $socialiteProvider = Mockery::mock(Provider::class);
    $socialiteProvider->shouldReceive('user')
        ->once()
        ->andReturn($socialUser);

    Socialite::shouldReceive('driver')
        ->with($provider)
        ->once()
        ->andReturn($socialiteProvider);

    Log::shouldReceive('warning')
        ->once()
        ->with('Social auth email verification bypassed in local environment', [
            'provider' => $provider,
            'email' => 'test@example.com',
        ]);

    app()->detectEnvironment(fn (): string => 'local');

    // Use RefreshDatabase context explicitly here or use DB transaction,
    // but tests/Unit runs faster without it. We can simply let the action
    // throw its expected error if the user doesn't exist, as long as it isn't
    // the SocialAuthException we are testing against.
    $resolver = app(ResolveSocialUserAction::class);
    $action = new HandleSocialCallbackAction($resolver);

    try {
        $action->execute($provider);
    } catch (\Exception $e) {
        expect($e)->not->toBeInstanceOf(\App\Exceptions\SocialAuthException::class);
    }
});
