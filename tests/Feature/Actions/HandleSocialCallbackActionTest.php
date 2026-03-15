<?php

declare(strict_types=1);

use App\Actions\HandleSocialCallbackAction;
use App\Exceptions\SocialAuthException;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;

it('throws exception if socialite driver throws exception', function () {
    Socialite::shouldReceive('driver')
        ->with('google')
        ->andThrow(new \Exception('Connection failed'));

    $action = app(HandleSocialCallbackAction::class);

    expect(fn () => $action->execute('google'))
        ->toThrow(SocialAuthException::class, 'Erreur lors de la connexion avec Google');
});

it('throws exception if email is not verified and environment is not local', function () {
    $socialUser = Mockery::mock(SocialiteUser::class);
    $socialUser->user = ['email_verified' => false];

    $providerMock = Mockery::mock(Provider::class);
    $providerMock->shouldReceive('user')->andReturn($socialUser);

    Socialite::shouldReceive('driver')
        ->with('google')
        ->andReturn($providerMock);

    app()->detectEnvironment(fn () => 'production');

    $action = app(HandleSocialCallbackAction::class);

    expect(fn () => $action->execute('google'))
        ->toThrow(SocialAuthException::class, 'Votre email n\'est pas vérifié par Google');
});

it('logs warning and proceeds if email is not verified but environment is local', function () {
    $socialUser = Mockery::mock(SocialiteUser::class);
    $socialUser->user = ['email_verified' => false];
    $socialUser->shouldReceive('getEmail')->andReturn('test@example.com');
    // Mocks for ResolveSocialUserAction execution
    $socialUser->shouldReceive('getId')->andReturn('123');
    $socialUser->shouldReceive('getAvatar')->andReturn('avatar.jpg');
    $socialUser->shouldReceive('getName')->andReturn('Test User');

    $providerMock = Mockery::mock(Provider::class);
    $providerMock->shouldReceive('user')->andReturn($socialUser);

    Socialite::shouldReceive('driver')
        ->with('google')
        ->andReturn($providerMock);

    app()->detectEnvironment(fn () => 'local');

    Log::shouldReceive('warning')
        ->once()
        ->with('Social auth email verification bypassed in local environment', [
            'provider' => 'google',
            'email' => 'test@example.com',
        ]);

    $action = app(HandleSocialCallbackAction::class);

    $result = $action->execute('google');

    expect($result)->toBeInstanceOf(User::class);
    expect($result->email)->toBe('test@example.com');
});

it('proceeds if email is verified', function (array $userData) {
    $socialUser = Mockery::mock(SocialiteUser::class);
    $socialUser->user = $userData;
    $socialUser->shouldReceive('getEmail')->andReturn('test@example.com');
    $socialUser->shouldReceive('getId')->andReturn('123');
    $socialUser->shouldReceive('getAvatar')->andReturn('avatar.jpg');
    $socialUser->shouldReceive('getName')->andReturn('Test User');

    $providerMock = Mockery::mock(Provider::class);
    $providerMock->shouldReceive('user')->andReturn($socialUser);

    Socialite::shouldReceive('driver')
        ->with('google')
        ->andReturn($providerMock);

    app()->detectEnvironment(fn () => 'production');

    $action = app(HandleSocialCallbackAction::class);

    $result = $action->execute('google');

    expect($result)->toBeInstanceOf(User::class);
    expect($result->email)->toBe('test@example.com');
})->with([
    'email_verified key' => [['email_verified' => true]],
    'verified_email key' => [['verified_email' => true]],
    'verified key' => [['verified' => true]],
]);
