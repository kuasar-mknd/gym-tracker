<?php

declare(strict_types=1);

use App\Actions\HandleSocialCallbackAction;
use App\Exceptions\SocialAuthException;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Un fournisseur qui rend l'utilisateur social donne.
 *
 * Une classe anonyme plutot qu'un double Mockery : PHPStan ne sait pas typer
 * `shouldReceive()->andReturn()`, ce qui coutait une entree de baseline a chaque
 * appel — sept dans ce seul fichier. L'interface ne declare que deux methodes.
 */
function fournisseurRendant(SocialiteUser $utilisateur): Provider
{
    return new readonly class($utilisateur) implements Provider
    {
        public function __construct(private SocialiteUser $utilisateur)
        {
        }

        public function redirect(): never
        {
            throw new LogicException('Ce test n’emprunte pas la redirection.');
        }

        public function user(): SocialiteUser
        {
            return $this->utilisateur;
        }
    };
}

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

    $providerMock = fournisseurRendant($socialUser);

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

    $providerMock = fournisseurRendant($socialUser);

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

    $providerMock = fournisseurRendant($socialUser);

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

/*
 * La valeur vient du fournisseur, elle n'est donc pas typee.
 *
 * Le controle etait un test de verite : `! $isVerified` est faux pour
 * n'importe quelle chaine non vide, « false » et « no » compris. Une reponse
 * mal formee ouvrait la porte au lieu de la fermer — ce qui est l'inverse de ce
 * qu'un controle de securite doit faire quand il ne comprend pas ce qu'il lit.
 *
 * Les entrees ci-dessous sont toutes des facons plausibles de dire « non ».
 */
it('refuse une valeur de vérification qui n’est pas un vrai oui', function (mixed $valeur): void {
    $socialUser = new SocialiteUser();
    $socialUser->user = ['email_verified' => $valeur];

    $providerMock = fournisseurRendant($socialUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($providerMock);
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => app(HandleSocialCallbackAction::class)->execute('google'))
        ->toThrow(SocialAuthException::class, 'Votre email n\'est pas vérifié par Google');
})->with([
    'la chaîne « false »' => ['false'],
    'la chaîne « no »' => ['no'],
    'la chaîne « off »' => ['off'],
    'le zéro en chaîne' => ['0'],
    'un entier nul' => [0],
    'la clé absente' => [null],
]);

/*
 * Le pendant : les facons plausibles de dire « oui » doivent continuer de
 * passer, sans quoi le durcissement ci-dessus fermerait la porte a tout le
 * monde et le test precedent serait vert pour la mauvaise raison.
 */
it('accepte une valeur de vérification affirmative', function (mixed $valeur): void {
    $socialUser = new SocialiteUser();
    $socialUser->user = ['email_verified' => $valeur];
    $socialUser->map(['id' => '123', 'email' => 'verifie@example.com', 'name' => 'Vérifié']);

    $providerMock = fournisseurRendant($socialUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($providerMock);
    app()->detectEnvironment(fn (): string => 'production');

    expect(app(HandleSocialCallbackAction::class)->execute('google'))->toBeInstanceOf(User::class);
})->with([
    'le booléen vrai' => [true],
    'la chaîne « true »' => ['true'],
    'l’entier un' => [1],
    'le un en chaîne' => ['1'],
]);
