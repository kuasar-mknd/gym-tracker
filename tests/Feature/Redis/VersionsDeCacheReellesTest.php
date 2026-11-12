<?php

declare(strict_types=1);

use App\Services\RecommendedValuesService;
use App\Support\Sante\LecteurDeRedis;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Tests\Support\RedisReel;

/*
 * Les versions de clés de cache contre un vrai Redis (#2151, #2204). Une
 * version vit dans le cache, sans échéance, et `allkeys-lru` l'évince comme
 * le reste : un ULID tiré à neuf ne nomme alors jamais une entrée d'avant.
 * Ce que `array` ne montrait pas : l'éviction elle-même, le compteur qu'une
 * release d'avant a laissé tel que le serveur le garde, et ce que rend un
 * `increment` de cette release d'avant sur un ULID.
 */

/**
 * La version que la clé de recommandation d'un compte porte : ce qui suit
 * `:v` jusqu'au deux-points suivant.
 */
function redisReelVersionDeLaRecommandation(int $compte): string
{
    return Str::betweenFirst(RecommendedValuesService::cleDeCache($compte, 1, 1), ':v', ':');
}

/**
 * Vrai tant que la clé du cache existe, sans la toucher : `EXISTS` ne
 * rafraîchit pas son âge pour l'éviction, là où une lecture le ferait.
 */
function redisReelCleDuCacheExiste(string $cle): bool
{
    $existe = Redis::connection('cache')->command('exists', [Cache::getPrefix().$cle]);

    return $existe === 1 || $existe === true;
}

/**
 * Oublie le mémo du cache, comme la fin d'une requête ou d'un job.
 */
function redisReelNouvelleRequete(): void
{
    app()->forgetScopedInstances();
}

it('remplace par un ULID le compteur qu’une release d’avant a laissé dans Redis', function (): void {
    Cache::increment('recommended_values:version:7');
    Cache::increment('recommended_values:version:7');
    expect(Redis::connection('cache')->command('get', [Cache::getPrefix().'recommended_values:version:7']))->toBe('2');

    $version = redisReelVersionDeLaRecommandation(7);

    expect(Str::isUlid($version))->toBeTrue()
        ->and(Cache::get('recommended_values:version:7'))->toBe($version);
});

it('rend false sans erreur à l’increment d’une release d’avant sur un ULID, d’où le cache:clear du retour en arrière', function (): void {
    $version = redisReelVersionDeLaRecommandation(7);

    expect(Cache::increment('recommended_values:version:7'))->toBeFalse()
        ->and(Cache::get('recommended_values:version:7'))->toBe($version);
})->skip(fn (): bool => config('database.redis.client') !== 'phpredis', 'phpredis seul rend false ; predis lève une erreur du serveur.');

it('ne ressert jamais une entrée d’avant quand Redis évince la version', function (): void {
    $version = redisReelVersionDeLaRecommandation(7);
    $entree = RecommendedValuesService::cleDeCache(7, 1, 1);
    Cache::forever($entree, ['weight' => 80.0]);

    $occupee = LecteurDeRedis::entier(app(LecteurDeRedis::class)->lire('default')['used_memory'] ?? null);
    assert(is_int($occupee));

    // Le cache se remplit jusqu'à évincer la version. L'horloge de l'éviction
    // de Redis compte en secondes : sur un test plus court, `allkeys-lru`
    // choisit presque au hasard parmi ce qu'il échantillonne. L'entrée se
    // réécrit donc après chaque écriture de remplissage, et reste en place
    // quand la version part : c'est le cas où un compteur la ressortait.
    $evincee = RedisReel::sousLesReglages(['maxmemory' => (string) ($occupee + 4 * 1024 * 1024), 'maxmemory-policy' => 'allkeys-lru'], function () use ($entree): bool {
        $valeur = str_repeat('x', 64 * 1024);

        for ($tour = 0; $tour < 2000 && redisReelCleDuCacheExiste('recommended_values:version:7'); $tour++) {
            Cache::put("remplissage:{$tour}", $valeur, 600);
            Cache::forever($entree, ['weight' => 80.0]);
        }

        return ! redisReelCleDuCacheExiste('recommended_values:version:7');
    });

    redisReelNouvelleRequete();
    $neuve = redisReelVersionDeLaRecommandation(7);

    expect($evincee)->toBeTrue('Le remplissage n’a pas évincé la version.')
        ->and(Cache::get($entree))->toBe(['weight' => 80.0])
        ->and($neuve)->not->toBe($version)
        ->and(Str::isUlid($neuve))->toBeTrue()
        ->and(Cache::get(RecommendedValuesService::cleDeCache(7, 1, 1)))->toBeNull();
});

it('invalide d’un coup les entrées d’un compte, et elles seules', function (): void {
    $service = app(RecommendedValuesService::class);
    Cache::forever(RecommendedValuesService::cleDeCache(7, 1, 1), ['weight' => 80.0]);
    Cache::forever(RecommendedValuesService::cleDeCache(8, 1, 1), ['weight' => 60.0]);

    $service->invaliderPour(7);
    redisReelNouvelleRequete();

    expect(Cache::get(RecommendedValuesService::cleDeCache(7, 1, 1)))->toBeNull()
        ->and(Cache::get(RecommendedValuesService::cleDeCache(8, 1, 1)))->toBe(['weight' => 60.0]);
});
