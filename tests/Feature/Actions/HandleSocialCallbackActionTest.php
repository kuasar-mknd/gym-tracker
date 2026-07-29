<?php

declare(strict_types=1);

use App\Actions\HandleSocialCallbackAction;
use App\Exceptions\SocialAuthException;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

it('throws exception if socialite driver throws exception', function (): void {
    Socialite::shouldReceive('driver')
        ->with('google')
        ->andThrow(new Exception('Connection failed'));

    $action = app(HandleSocialCallbackAction::class);

    expect(fn () => $action->execute('google'))
        ->toThrow(SocialAuthException::class, 'Erreur lors de la connexion avec Google');
});

it('throws exception if email is not verified and environment is not local', function (): void {
    $socialUser = new SocialiteUser();
    $socialUser->user = ['email_verified' => false];

    $providerMock = Mockery::mock(Provider::class);
    $providerMock->shouldReceive('user')->andReturn($socialUser);

    Socialite::shouldReceive('driver')
        ->with('google')
        ->andReturn($providerMock);

    // Use app()->detectEnvironment like we originally did to successfully override environment since config('app.env') may not immediately alter app()->environment() in Laravel testing context. Or we can just rebind app('env').
    app()->detectEnvironment(fn (): string => 'production');

    $action = app(HandleSocialCallbackAction::class);

    expect(fn () => $action->execute('google'))
        ->toThrow(SocialAuthException::class, 'Votre email n\'est pas vérifié par Google');
});

it('logs warning and proceeds if email is not verified but environment is local', function (): void {
    $socialUser = new SocialiteUser();
    $socialUser->user = ['email_verified' => false];
    $socialUser->map([
        'id' => '123',
        'nickname' => 'testuser',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'avatar' => 'avatar.jpg',
    ]);

    $providerMock = Mockery::mock(Provider::class);
    $providerMock->shouldReceive('user')->andReturn($socialUser);

    Socialite::shouldReceive('driver')
        ->with('google')
        ->andReturn($providerMock);

    app()->detectEnvironment(fn (): string => 'local');

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

it('proceeds if email is verified', function (array $userData): void {
    $socialUser = new SocialiteUser();
    $socialUser->user = $userData;
    $socialUser->map([
        'id' => '123',
        'nickname' => 'testuser',
        'name' => 'Test User',
        'email' => 'test@example.com',
        'avatar' => 'avatar.jpg',
    ]);

    $providerMock = Mockery::mock(Provider::class);
    $providerMock->shouldReceive('user')->andReturn($socialUser);

    Socialite::shouldReceive('driver')
        ->with('google')
        ->andReturn($providerMock);

    app()->detectEnvironment(fn (): string => 'production');

    $action = app(HandleSocialCallbackAction::class);

    $result = $action->execute('google');

    expect($result)->toBeInstanceOf(User::class);
    expect($result->email)->toBe('test@example.com');
})->with([
    'email_verified key' => [['email_verified' => true]],
    'verified_email key' => [['verified_email' => true]],
    'verified key' => [['verified' => true]],
]);

it('throws exception if socialite user throws exception', function (): void {
    $providerMock = Mockery::mock(Provider::class);
    $providerMock->shouldReceive('user')->andThrow(new Exception('Connection failed'));

    Socialite::shouldReceive('driver')
        ->with('google')
        ->andReturn($providerMock);

    $action = app(HandleSocialCallbackAction::class);

    expect(fn () => $action->execute('google'))
        ->toThrow(SocialAuthException::class, 'Erreur lors de la connexion avec Google');
});
