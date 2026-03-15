<?php

declare(strict_types=1);

use App\Actions\HandleSocialCallbackAction;
use App\Actions\ResolveSocialUserAction;
use App\Exceptions\SocialAuthException;
use Laravel\Socialite\Facades\Socialite;

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
