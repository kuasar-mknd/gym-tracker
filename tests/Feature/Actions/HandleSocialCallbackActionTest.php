<?php

declare(strict_types=1);

use App\Actions\HandleSocialCallbackAction;
use App\Actions\ResolveSocialUserAction;
use App\Exceptions\SocialAuthException;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

it('throws exception if driver user fails', function () {
    $provider = 'google';

    // Mock Socialite driver to throw Exception
    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('user')->andThrow(new Exception('Connection failed'));

    Socialite::shouldReceive('driver')
        ->with($provider)
        ->andReturn($driverMock);

    $action = app(HandleSocialCallbackAction::class);

    $action->execute($provider);
})->throws(SocialAuthException::class, 'Erreur lors de la connexion avec Google');

it('resolves user when email is verified', function () {
    $provider = 'google';

    $socialUserMock = Mockery::mock(\Laravel\Socialite\Contracts\User::class);
    $socialUserMock->user = ['email_verified' => true];

    // Provide necessary data for ResolveSocialUserAction
    $socialUserMock->shouldReceive('getEmail')->andReturn('test@example.com');
    $socialUserMock->shouldReceive('getId')->andReturn('12345');
    $socialUserMock->shouldReceive('getName')->andReturn('Test User');
    $socialUserMock->shouldReceive('getAvatar')->andReturn('https://example.com/avatar.jpg');

    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('user')->andReturn($socialUserMock);

    Socialite::shouldReceive('driver')
        ->with($provider)
        ->andReturn($driverMock);

    $action = app(HandleSocialCallbackAction::class);

    $result = $action->execute($provider);

    expect($result)->toBeInstanceOf(User::class);
    expect($result->email)->toBe('test@example.com');
});

it('logs warning and resolves user in local environment when email is unverified', function () {
    $provider = 'google';

    $socialUserMock = Mockery::mock(\Laravel\Socialite\Contracts\User::class);
    $socialUserMock->user = []; // Missing verification keys

    // Provide necessary data for ResolveSocialUserAction
    $socialUserMock->shouldReceive('getEmail')->andReturn('local@example.com');
    $socialUserMock->shouldReceive('getId')->andReturn('67890');
    $socialUserMock->shouldReceive('getName')->andReturn('Local User');
    $socialUserMock->shouldReceive('getAvatar')->andReturn('https://example.com/avatar2.jpg');

    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('user')->andReturn($socialUserMock);

    Socialite::shouldReceive('driver')
        ->with($provider)
        ->andReturn($driverMock);

    Log::shouldReceive('warning')
        ->once()
        ->with('Social auth email verification bypassed in local environment', [
            'provider' => $provider,
            'email' => 'local@example.com',
        ]);

    // Set environment to local
    app()['env'] = 'local';

    $action = app(HandleSocialCallbackAction::class);

    $result = $action->execute($provider);

    expect($result)->toBeInstanceOf(User::class);
    expect($result->email)->toBe('local@example.com');
});

it('throws exception in production environment when email is unverified', function () {
    $provider = 'google';

    $socialUserMock = Mockery::mock(\Laravel\Socialite\Contracts\User::class);
    $socialUserMock->user = []; // Missing verification keys

    $driverMock = Mockery::mock();
    $driverMock->shouldReceive('user')->andReturn($socialUserMock);

    Socialite::shouldReceive('driver')
        ->with($provider)
        ->andReturn($driverMock);

    // Set environment to production
    app()['env'] = 'production';

    $action = app(HandleSocialCallbackAction::class);

    $action->execute($provider);
})->throws(SocialAuthException::class, 'Votre email n\'est pas vérifié par Google');
