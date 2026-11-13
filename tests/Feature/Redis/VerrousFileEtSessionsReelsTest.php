<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;

/*
 * Ce que `php artisan cache:clear` emporte, contre un vrai Redis (#2151).
 * La commande vide la base de la connexion `cache` (FLUSHDB) : sessions
 * (connexion de session nulle, donc `default`), file (`default`) et verrous
 * (`lock_connection` à `default`, `config/cache.php`) vivent sur la base 0 et
 * doivent survivre. Les limiteurs et les battements de santé vivent dans le
 * magasin du cache tant que `CACHE_DURABLE_STORE` n'est pas posée ; posée, le
 * magasin `redis-durable` les range sur la connexion des données. Sur `array`, rien de tout cela ne se
 * voyait : chaque magasin y a sa mémoire.
 */

/**
 * Une session enregistrée par le gestionnaire de l'application, et son
 * identifiant.
 */
function redisReelEnregistrerUneSession(): string
{
    $session = app('session')->driver();
    $session->start();
    $session->put('redis-reel', 'gardée');
    $session->save();
    $identifiant = $session->getId();
    assert(is_string($identifiant));

    return $identifiant;
}

it('vide le cache par cache:clear sans toucher aux sessions, à la file ni aux verrous', function (): void {
    $identifiant = redisReelEnregistrerUneSession();
    dispatch(static function (): void {
    })->onConnection('redis')->onQueue('redis-reel');
    $verrou = Cache::lock('redis-reel:verrou', 60);
    expect($verrou->get())->toBeTrue();
    Cache::put('redis-reel:entree', 'à vider', 60);

    expect(Artisan::call('cache:clear'))->toBe(0);

    $relue = app('session')->driver()->getHandler()->read($identifiant);

    expect(Cache::get('redis-reel:entree'))->toBeNull()
        ->and($relue)->toContain('gardée')
        ->and(Queue::connection('redis')->size('redis-reel'))->toBe(1)
        ->and(Cache::lock('redis-reel:verrou', 60)->get())->toBeFalse();

    $verrou->release();
});

it('tient un verrou réel : un seul propriétaire, rendu par lui seul, libre à son échéance', function (): void {
    $premier = Cache::lock('redis-reel:verrou', 1);
    $second = Cache::lock('redis-reel:verrou', 1);

    expect($premier->get())->toBeTrue()
        ->and($second->get())->toBeFalse()
        ->and($second->release())->toBeFalse()
        ->and(Cache::lock('redis-reel:verrou', 1)->get())->toBeFalse();

    $repris = Cache::restoreLock('redis-reel:verrou', $premier->owner());

    expect($repris->release())->toBeTrue()
        ->and($second->get())->toBeTrue();

    // Une seconde d'échéance : le serveur la rend sans que personne ne la rende.
    $echeance = microtime(true) + 3;
    while (Cache::lock('redis-reel:verrou', 1)->get() !== true && microtime(true) < $echeance) {
        usleep(50_000);
    }

    expect(microtime(true))->toBeLessThan($echeance, 'Le verrou d’une seconde ne s’est pas libéré tout seul.');
});

it('range les verrous sur la connexion des données, que cache:clear ne vide pas', function (): void {
    Cache::lock('redis-reel:verrou', 60)->get();

    $cles = static fn (string $connexion): array => array_values(array_filter(
        (array) Redis::connection($connexion)->command('keys', ['*']),
        static fn (mixed $cle): bool => is_string($cle) && str_contains($cle, 'redis-reel:verrou'),
    ));

    expect($cles('default'))->toHaveCount(1)
        ->and($cles('cache'))->toBe([]);
});

it('compte un limiteur réel jusqu’à sa limite, avec l’échéance que tient le serveur', function (): void {
    $passages = [];

    foreach (range(1, 4) as $essai) {
        $passages[] = RateLimiter::attempt('redis-reel:limiteur', 3, static fn (): bool => true, 60);
    }

    expect($passages)->toBe([true, true, true, false])
        ->and(RateLimiter::tooManyAttempts('redis-reel:limiteur', 3))->toBeTrue()
        ->and(RateLimiter::availableIn('redis-reel:limiteur'))->toBeGreaterThan(0)->toBeLessThanOrEqual(60)
        // Le serveur rend le compte en chaîne, là où `array` rendait un entier.
        ->and(RateLimiter::attempts('redis-reel:limiteur'))->toEqual(3)
        ->and(RateLimiter::remaining('redis-reel:limiteur', 3))->toBe(0);

    RateLimiter::clear('redis-reel:limiteur');

    expect(RateLimiter::attempt('redis-reel:limiteur', 3, static fn (): bool => true, 60))->toBeTrue();
});

it('range les limiteurs sur la connexion des données quand le magasin durable est posé', function (?string $durable, string $attendue, string $absente): void {
    config()->set('cache.limiter', $durable);
    app()->forgetInstance(\Illuminate\Cache\RateLimiter::class);
    Facade::clearResolvedInstance(\Illuminate\Cache\RateLimiter::class);

    RateLimiter::hit('redis-reel:limiteur-durable', 60);

    $cles = static fn (string $connexion): array => array_values(array_filter(
        (array) Redis::connection($connexion)->command('keys', ['*']),
        static fn (mixed $cle): bool => is_string($cle) && str_contains($cle, 'redis-reel:limiteur-durable'),
    ));

    expect($cles($attendue))->toHaveCount(2)
        ->and($cles($absente))->toBe([]);
})->with([
    'magasin durable posé' => ['redis-durable', 'default', 'cache'],
    'magasin durable absent : le cache, comme Sail et la CI' => [null, 'cache', 'default'],
]);
