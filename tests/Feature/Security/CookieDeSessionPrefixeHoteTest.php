<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Process;
use Tests\Support\Appareil;

/*
 * Le cookie de session prend le préfixe `__Host-` quand il est réservé à HTTPS
 * (`Secure`), sans domaine et sur `/` : le navigateur refuse alors qu'un
 * sous-domaine ou une connexion en clair le remplace. La configuration se lit
 * dans un processus à part, pour que chaque cas porte son environnement.
 */

/**
 * @param  array<string, string|false>  $environnement
 * @return array{cookie: string, secure: bool}
 */
function cookieDeSessionSous(array $environnement): array
{
    $script = 'require $argv[1]."/vendor/autoload.php"; new Illuminate\\Foundation\\Application($argv[1]); echo json_encode(array_intersect_key(require $argv[1]."/config/session.php", array_flip(["cookie", "secure"])));';
    $environnement += ['SESSION_COOKIE' => false, 'SESSION_DOMAIN' => false, 'SESSION_PATH' => false, 'SESSION_SECURE_COOKIE' => false, 'APP_NAME' => 'GymTracker'];
    $resultat = Process::env($environnement)->run([PHP_BINARY, '-r', $script, base_path()]);

    expect($resultat->successful())->toBeTrue($resultat->errorOutput());

    /** @var array{cookie: string, secure: bool} $configuration */
    $configuration = json_decode($resultat->output(), true, flags: JSON_THROW_ON_ERROR);

    return $configuration;
}

it('prend le préfixe __Host- en production', function (): void {
    expect(cookieDeSessionSous(['APP_ENV' => 'production']))->toBe(['cookie' => '__Host-gymtracker-session', 'secure' => true]);
});

it('prend le préfixe __Host- quand le cookie est réservé à HTTPS hors production', function (): void {
    expect(cookieDeSessionSous(['APP_ENV' => 'local', 'SESSION_SECURE_COOKIE' => 'true'])['cookie'])->toBe('__Host-gymtracker-session');
});

it('garde le nom ordinaire hors HTTPS, où Dusk et le développement tournent', function (): void {
    expect(cookieDeSessionSous(['APP_ENV' => 'local']))->toBe(['cookie' => 'gymtracker-session', 'secure' => false])
        ->and(cookieDeSessionSous(['APP_ENV' => 'production', 'SESSION_SECURE_COOKIE' => 'false'])['cookie'])->toBe('gymtracker-session');
});

it('ne prend pas le préfixe avec un domaine ou un chemin que __Host- refuse', function (): void {
    expect(cookieDeSessionSous(['APP_ENV' => 'production', 'SESSION_DOMAIN' => 'exemple.org'])['cookie'])->toBe('gymtracker-session')
        ->and(cookieDeSessionSous(['APP_ENV' => 'production', 'SESSION_PATH' => '/app'])['cookie'])->toBe('gymtracker-session');
});

it('laisse SESSION_COOKIE décider du nom quand il est posé', function (): void {
    expect(cookieDeSessionSous(['APP_ENV' => 'production', 'SESSION_COOKIE' => 'mon-cookie'])['cookie'])->toBe('mon-cookie');
});

it('pose le cookie de session prefixé avec Secure, sur /, sans domaine', function (): void {
    config(['session.secure' => true, 'session.cookie' => '__Host-gymtracker-session']);
    $compte = User::factory()->create();

    $reponse = new Appareil()->envoyer('POST', 'https://gym.example.org/login', ['email' => $compte->email, 'password' => 'password']);

    $cookie = collect($reponse->headers->getCookies())->first(fn ($c): bool => $c->getName() === '__Host-gymtracker-session');
    expect($cookie)->not->toBeNull()
        ->and($cookie->isSecure())->toBeTrue()
        ->and($cookie->getPath())->toBe('/')
        ->and($cookie->getDomain())->toBeNull();
});
