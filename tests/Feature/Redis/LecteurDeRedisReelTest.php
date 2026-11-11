<?php

declare(strict_types=1);

use App\Support\Sante\LecteurDeRedis;
use App\Support\Sante\ReglagesDeRedisCheck;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\RedisReel;

/*
 * `LecteurDeRedis`, `redis:relever` et « Réglages de Redis » lus contre un
 * vrai serveur (#2151) : la suite ordinaire ne leur donne qu'une réponse
 * écrite (`RedisQuiRepond`), au format que phpredis est censé rendre. Ici,
 * la réponse est celle d'`INFO everything` d'un Redis à l'image de la
 * production, avec ce qu'elle porte et que rien ne doit recopier.
 */

/**
 * Une clé dont le nom porte une adresse et une IP, comme celle du compteur
 * des tentatives de connexion, lue assez pour être servie souvent.
 */
function redisReelEcrireUneCleQuiNommeQuelquUn(): string
{
    $cle = 'login:ada@example.org|203.0.113.7';
    Cache::put($cle, 3, 60);

    foreach (range(1, 50) as $lecture) {
        Cache::get($cle);
    }

    return $cle;
}

/**
 * La version de Redis que lance la production : l'étiquette de l'image du
 * service redis, sans sa variante.
 */
function redisReelVersionDeLaProduction(): string
{
    $image = data_get(Yaml::parseFile(base_path('docker-compose.prod.yml')), 'services.redis.image');
    expect($image)->toBeString()->toStartWith('redis:');
    assert(is_string($image));

    return Str::before(Str::after($image, 'redis:'), '-');
}

it('lit dans INFO les champs de sa liste blanche, et eux seuls', function (): void {
    redisReelEcrireUneCleQuiNommeQuelquUn();
    $brut = Redis::connection('default')->command('info', ['everything']);
    expect($brut)->toBeArray();
    assert(is_array($brut));

    // Sans le filtre, le serveur dit ce que l'hôte est et ce qu'il a.
    $aPlat = [];
    foreach ($brut as $cle => $valeur) {
        $aPlat = [...$aPlat, ...(is_array($valeur) ? $valeur : [$cle => $valeur])];
    }
    expect($aPlat)->toHaveKeys(['os', 'total_system_memory', 'executable']);

    $champs = app(LecteurDeRedis::class)->lire('default');

    expect(array_diff(array_keys($champs), LecteurDeRedis::CHAMPS))->toBe([])
        ->and($champs)->toHaveKeys([
            'redis_version', 'run_id', 'uptime_in_seconds', 'used_memory', 'used_memory_peak', 'maxmemory',
            'maxmemory_policy', 'evicted_keys', 'expired_keys', 'keyspace_hits', 'keyspace_misses',
            'aof_enabled', 'aof_last_write_status', 'aof_last_bgrewrite_status',
            'rdb_last_bgsave_status', 'db1',
        ])
        ->and($champs['redis_version'])->toBe(redisReelVersionDeLaProduction())
        ->and(LecteurDeRedis::entier($champs['used_memory']))->toBeGreaterThan(0)
        ->and(LecteurDeRedis::entier($champs['keyspace_hits']))->toBeGreaterThanOrEqual(50);

    foreach ($champs as $valeur) {
        expect($valeur)->not->toContain('ada')->not->toContain('203.0.113.7');
    }
});

it('ne lit qu’une instance tant que le cache partage l’hôte et le port des données', function (): void {
    expect(app(LecteurDeRedis::class)->instances())->toBe(['donnees' => 'default']);
});

it('relève les compteurs sans la mémoire de l’hôte ni aucun nom de clé, la mémoire en pourcentage du plafond', function (): void {
    redisReelEcrireUneCleQuiNommeQuelquUn();

    [$code, $sortie] = RedisReel::sousLesReglages(['maxmemory' => '256mb'], function (): array {
        $code = Artisan::call('redis:relever');

        return [$code, Artisan::output()];
    });

    $lignes = array_values(array_filter(explode("\n", trim($sortie))));

    expect($code)->toBe(0)
        ->and($lignes[0])->toBe('Instance des données (connexion default)')
        ->and($lignes[1])->toBe('redis_version: '.redisReelVersionDeLaProduction())
        ->and($lignes[2])->toMatch('/^memoire: \d+ % du plafond$/')
        ->and($sortie)->not->toContain('total_system_memory')
        ->not->toContain('used_memory')
        ->not->toContain('os:')
        ->not->toContain('ada')
        ->not->toContain('203.0.113.7');

    $permis = ['memoire', 'memoire_au_pic', ...LecteurDeRedis::CHAMPS];

    foreach (array_slice($lignes, 1) as $ligne) {
        expect(in_array(Str::before($ligne, ':'), $permis, true))->toBeTrue("Le relevé imprime « {$ligne} », hors de la liste blanche.");
    }
});

it('passe « Réglages de Redis » au rouge quand le vrai serveur évince une clé', function (): void {
    app()->detectEnvironment(fn (): string => 'production');

    $occupee = LecteurDeRedis::entier(app(LecteurDeRedis::class)->lire('default')['used_memory'] ?? null);
    expect($occupee)->toBeInt();
    assert(is_int($occupee));

    // Quatre mégaoctets de marge, puis des valeurs de 64 Kio jusqu'à la
    // première éviction : le cache plein évince ce que personne n'a relu.
    $plafond = ['maxmemory' => (string) ($occupee + 4 * 1024 * 1024), 'maxmemory-policy' => 'allkeys-lru'];

    $resultats = RedisReel::sousLesReglages($plafond, function (): array {
        $avant = ReglagesDeRedisCheck::new()->run();
        $valeur = str_repeat('x', 64 * 1024);
        $ecrites = 0;

        while (LecteurDeRedis::entier(app(LecteurDeRedis::class)->lire('default')['evicted_keys'] ?? null) === 0 && $ecrites < 2000) {
            foreach (range(1, 20) as $rang) {
                Cache::put('remplissage:'.$ecrites.':'.$rang, $valeur, 600);
            }
            $ecrites++;
        }

        return [$avant, ReglagesDeRedisCheck::new()->run()];
    });

    [$avant, $apres] = $resultats;

    expect($avant->notificationMessage)->not->toContain('a évincé')
        ->and($apres->status->value)->toBe('failed')
        ->and($apres->notificationMessage)->toContain('L\'instance des données a évincé des clés');
});
