<?php

declare(strict_types=1);

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\Support\RedisReel;

/*
 * La suite tourne sur `array` et `sync` (phpunit.xml) : elle ne voyait ni
 * éviction, ni verrou, ni file, ni limiteur réels, et `LecteurDeRedis` n'y
 * lisait qu'une réponse écrite (#2151). Les tests qui ont besoin d'un vrai
 * Redis vivent dans tests/Feature/Redis, au groupe `redis` :
 *
 * - phpunit.xml écarte le groupe de toute passe qui ne le demande pas : les
 *   éclats parallèles de la CI ne partagent pas un serveur que chaque test
 *   vide, et une passe locale sans Redis ne les lance pas ;
 * - tests/Pest.php les range au groupe, et `Tests\TestCase::setUpTraits()`
 *   y appelle `RedisReel::exiger()`, qui fait échouer, en le disant et avant
 *   toute migration, un test lancé sans vrai Redis ;
 * - la CI les lance dans une étape à part, sans condition, contre un service
 *   à l'image de la production, magasins sur Redis comme en production.
 *
 * Cette garde tient les trois, et refuse toute autre étape qui choisirait
 * ses groupes : `--exclude-group` remplacerait l'exclusion de phpunit.xml,
 * et le groupe tournerait sans Redis.
 */

/** Le dossier du groupe, relatif à tests/. */
const GROUPE_REDIS_DOSSIER = 'Feature/Redis';

/**
 * Le groupe que lance une commande de test (`--group=redis`, `--group redis`).
 */
function groupeRedisLanceLeGroupe(string $commande): bool
{
    return preg_match('/(?:\bartisan\s+test|\bvendor\/bin\/(?:pest|phpunit))\b[^\n]*\s\--group(?:=|\s+)[\'"]?redis\b/', $commande) === 1;
}

/**
 * Une commande de test qui choisit ses groupes.
 */
function groupeRedisChoisitSesGroupes(string $commande): bool
{
    return preg_match('/(?:\bartisan\s+test|\bvendor\/bin\/(?:pest|phpunit))\b[^\n]*\s\--(?:exclude-)?group\b/', $commande) === 1;
}

/**
 * L'image du service redis de la production.
 */
function groupeRedisImageDeLaProduction(): string
{
    $image = data_get(Yaml::parseFile(base_path('docker-compose.prod.yml')), 'services.redis.image');
    assert(is_string($image));

    return $image;
}

/**
 * Les workflows du dépôt, lus, par nom de fichier.
 *
 * @return array<string, array<array-key, mixed>>
 */
function groupeRedisWorkflows(): array
{
    $workflows = [];

    $chemins = glob(base_path('.github/workflows/*.yml'));

    foreach ($chemins === false ? [] : $chemins as $chemin) {
        $lu = Yaml::parseFile($chemin);
        $workflows[basename($chemin)] = is_array($lu) ? $lu : [];
    }

    return $workflows;
}

/**
 * Ce que la garde refuse dans les workflows.
 *
 * @param  array<string, array<array-key, mixed>>  $workflows
 * @return list<string>
 */
function groupeRedisEcartsDeLaCi(array $workflows, string $image): array
{
    $ecarts = [];
    $etapesDuGroupe = 0;

    foreach ($workflows as $fichier => $workflow) {
        $jobs = is_array($workflow['jobs'] ?? null) ? $workflow['jobs'] : [];

        foreach ($jobs as $nomDuJob => $job) {
            $etapes = is_array($job) && is_array($job['steps'] ?? null) ? $job['steps'] : [];

            foreach ($etapes as $etape) {
                $commande = is_array($etape) && is_string($etape['run'] ?? null) ? $etape['run'] : '';
                $nom = sprintf('%s › %s › %s', $fichier, $nomDuJob, is_array($etape) && is_string($etape['name'] ?? null) ? $etape['name'] : '?');

                if (groupeRedisLanceLeGroupe($commande)) {
                    $etapesDuGroupe++;
                    assert(is_array($job) && is_array($etape));
                    $ecarts = [...$ecarts, ...groupeRedisEcartsDeLEtape($nom, $job, $etape, $image)];
                } elseif (groupeRedisChoisitSesGroupes($commande)) {
                    $ecarts[] = "{$nom} choisit ses groupes de tests : l'exclusion du groupe redis par phpunit.xml ne tiendrait plus.";
                }
            }
        }
    }

    if ($etapesDuGroupe !== 1) {
        $ecarts[] = sprintf('%d étapes lancent le groupe redis ; il en faut une, et une seule.', $etapesDuGroupe);
    }

    return $ecarts;
}

/**
 * @param  array<array-key, mixed>  $job
 * @param  array<array-key, mixed>  $etape
 * @return list<string>
 */
function groupeRedisEcartsDeLEtape(string $nom, array $job, array $etape, string $image): array
{
    $ecarts = [];

    if (array_key_exists('if', $etape) || array_key_exists('continue-on-error', $etape)) {
        $ecarts[] = "{$nom} porte une condition ou continue-on-error : le groupe pourrait ne pas tourner, ou tomber sans rien faire tomber.";
    }

    $env = is_array($etape['env'] ?? null) ? $etape['env'] : [];

    foreach (['CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION'] as $variable) {
        if (($env[$variable] ?? null) !== 'redis') {
            $ecarts[] = "{$nom} ne pose pas {$variable} à redis.";
        }
    }

    if (! isset($env['REDIS_HOST'])) {
        $ecarts[] = "{$nom} ne pose pas REDIS_HOST : le groupe chercherait l'hôte de .env.example.";
    }

    $services = is_array($job['services'] ?? null) ? $job['services'] : [];
    $redis = array_filter($services, static fn (mixed $service): bool => is_array($service) && ($service['image'] ?? null) === $image);

    if ($redis === []) {
        $ecarts[] = "Le job de {$nom} n'a pas de service à l'image de la production ({$image}).";
    }

    $extensions = '';

    foreach (is_array($job['steps'] ?? null) ? $job['steps'] : [] as $autre) {
        $liste = data_get($autre, 'with.extensions');

        if (is_array($autre) && is_string($autre['uses'] ?? null) && str_starts_with($autre['uses'], 'shivammathur/setup-php@') && is_string($liste)) {
            $extensions .= ','.$liste;
        }
    }

    if (! in_array('redis', array_map(trim(...), explode(',', $extensions)), true)) {
        $ecarts[] = "Le job de {$nom} n'installe pas l'extension redis (phpredis) par setup-php.";
    }

    return $ecarts;
}

/**
 * Le job de la CI qui lance le groupe, retouché par `$retouche`.
 *
 * @param  Closure(array<array-key, mixed>): array<array-key, mixed>  $retouche
 * @return list<string>
 */
function groupeRedisEcartsApres(Closure $retouche): array
{
    $workflows = groupeRedisWorkflows();
    $ci = $workflows['ci.yml'] ?? [];
    $jobs = is_array($ci['jobs'] ?? null) ? $ci['jobs'] : [];
    $jobs['laravel-tests-mysql'] = $retouche(is_array($jobs['laravel-tests-mysql'] ?? null) ? $jobs['laravel-tests-mysql'] : []);
    $ci['jobs'] = $jobs;
    $workflows['ci.yml'] = $ci;

    return groupeRedisEcartsDeLaCi($workflows, groupeRedisImageDeLaProduction());
}

/**
 * Le job, avec la valeur donnée posée au chemin donné.
 *
 * @param  array<array-key, mixed>  $job
 * @return array<array-key, mixed>
 */
function groupeRedisPoser(array $job, string $chemin, mixed $valeur): array
{
    data_set($job, $chemin, $valeur);
    assert(is_array($job));

    return $job;
}

/**
 * Les étapes d'un job, par rang.
 *
 * @param  array<array-key, mixed>  $job
 * @return array<int, mixed>
 */
function groupeRedisEtapes(array $job): array
{
    $etapes = $job['steps'] ?? [];

    return is_array($etapes) ? array_values($etapes) : [];
}

/**
 * Le rang de l'étape qui lance le groupe dans un job.
 *
 * @param  array<array-key, mixed>  $job
 */
function groupeRedisRangDeLEtape(array $job): int
{
    foreach (groupeRedisEtapes($job) as $rang => $etape) {
        $commande = data_get($etape, 'run');

        if (is_string($commande) && groupeRedisLanceLeGroupe($commande)) {
            return $rang;
        }
    }

    throw new RuntimeException('Aucune étape ne lance le groupe redis.');
}

it('écarte le groupe redis de toute passe qui ne le demande pas', function (): void {
    $configuration = simplexml_load_file(base_path('phpunit.xml'));
    assert($configuration instanceof SimpleXMLElement);

    $exclus = $configuration->xpath('/phpunit/groups/exclude/group');
    $inclus = $configuration->xpath('/phpunit/groups/include/group');
    $exclus = is_array($exclus) ? array_map(strval(...), $exclus) : [];

    expect($exclus)->toContain('redis')
        ->and(is_array($inclus) ? $inclus : [])->toBe([]);
});

it('lie chaque test de tests/Feature/Redis au groupe et à la garde du vrai Redis', function (): void {
    $source = File::get(base_path('tests/Pest.php'));

    expect(preg_match(
        "/pest\\(\\)->group\\(RedisReel::GROUPE\\)\\s*->in\\('".preg_quote(GROUPE_REDIS_DOSSIER, '/')."'\\);/",
        $source,
    ))->toBe(1, 'tests/Pest.php ne range plus tests/Feature/Redis au groupe redis, dont Tests\\TestCase exige un vrai Redis.');

    expect(RedisReel::GROUPE)->toBe('redis');

    $fichiers = File::allFiles(base_path('tests/'.GROUPE_REDIS_DOSSIER));
    expect($fichiers)->not->toBeEmpty();

    // `in()` n'atteint que les fichiers Pest : une classe y échapperait au
    // groupe et à la garde (.ai/rules/tests.md).
    foreach ($fichiers as $fichier) {
        expect(preg_match('/^(?:it|test)\(/m', $fichier->getContents()))->toBe(1, "{$fichier->getRelativePathname()} n'est pas un fichier de tests Pest.")
            ->and(preg_match('/^\s*(?:final\s+|abstract\s+)?class\s/m', $fichier->getContents()))->toBe(0, "{$fichier->getRelativePathname()} déclare une classe.");
    }
});

it('ne range au groupe redis aucun test hors de tests/Feature/Redis', function (): void {
    $dehors = [];

    foreach (File::allFiles(base_path('tests')) as $fichier) {
        // tests/Pest.php lie le dossier au groupe : c'est la liaison que la
        // garde précédente lit.
        if (str_starts_with($fichier->getRelativePathname(), GROUPE_REDIS_DOSSIER.'/') || $fichier->getRelativePathname() === 'Pest.php' || $fichier->getExtension() !== 'php') {
            continue;
        }

        if (preg_match('/->group\([^)]*(?:[\'"]redis[\'"]|RedisReel::GROUPE)|#\[Group\((?:[\'"]redis[\'"]|RedisReel::GROUPE)\)\]/', $fichier->getContents()) === 1) {
            $dehors[] = $fichier->getRelativePathname();
        }
    }

    expect($dehors)->toBe([], 'Ces tests se rangent au groupe redis hors de tests/Feature/Redis, sans la garde du vrai Redis.');
});

it('lance le groupe redis en CI, dans une étape à part, contre un Redis à l’image de la production', function (): void {
    expect(groupeRedisEcartsDeLaCi(groupeRedisWorkflows(), groupeRedisImageDeLaProduction()))->toBe([]);
});

it('refuse une étape du groupe sans service, sans extension, sous condition, ou sur un autre magasin', function (Closure $retouche, string $ecart): void {
    $ecarts = groupeRedisEcartsApres($retouche);

    expect($ecarts)->toHaveCount(1)
        ->and($ecarts[0])->toContain($ecart);
})->with([
    'sans service redis' => [static function (array $job): array {
        Arr::forget($job, 'services.redis');

        return $job;
    }, 'pas de service à l\'image de la production'],
    'service à une autre image' => [static fn (array $job): array => groupeRedisPoser($job, 'services.redis.image', 'redis:8-alpine'), 'pas de service à l\'image de la production'],
    'sans phpredis' => [static function (array $job): array {
        foreach (groupeRedisEtapes($job) as $rang => $etape) {
            $extensions = data_get($etape, 'with.extensions');

            if (is_string($extensions)) {
                $job = groupeRedisPoser($job, "steps.{$rang}.with.extensions", str_replace(', redis', '', $extensions));
            }
        }

        return $job;
    }, 'n\'installe pas l\'extension redis'],
    'sous condition' => [static fn (array $job): array => groupeRedisPoser($job, 'steps.'.groupeRedisRangDeLEtape($job).'.if', "github.event_name == 'push'"), 'porte une condition'],
    'qui peut tomber sans rien faire tomber' => [static fn (array $job): array => groupeRedisPoser($job, 'steps.'.groupeRedisRangDeLEtape($job).'.continue-on-error', true), 'porte une condition ou continue-on-error'],
    'cache sur array' => [static fn (array $job): array => groupeRedisPoser($job, 'steps.'.groupeRedisRangDeLEtape($job).'.env.CACHE_STORE', 'array'), 'ne pose pas CACHE_STORE à redis'],
    'sans hôte' => [static function (array $job): array {
        Arr::forget($job, 'steps.'.groupeRedisRangDeLEtape($job).'.env.REDIS_HOST');

        return $job;
    }, 'ne pose pas REDIS_HOST'],
]);

it('refuse une étape qui retire le groupe, ou qui choisit ses groupes', function (Closure $retouche, string $ecart): void {
    $ecarts = groupeRedisEcartsApres($retouche);

    expect($ecarts)->toHaveCount(1)
        ->and($ecarts[0])->toContain($ecart);
})->with([
    'groupe retiré' => [static function (array $job): array {
        $etapes = groupeRedisEtapes($job);
        unset($etapes[groupeRedisRangDeLEtape($job)]);
        $job['steps'] = array_values($etapes);

        return $job;
    }, '0 étapes lancent le groupe redis'],
    'groupe lancé deux fois' => [static function (array $job): array {
        $etapes = groupeRedisEtapes($job);
        $etapes[] = $etapes[groupeRedisRangDeLEtape($job)];
        $job['steps'] = $etapes;

        return $job;
    }, '2 étapes lancent le groupe redis'],
    'suite principale qui exclut un autre groupe' => [static function (array $job): array {
        $job['steps'] = [...groupeRedisEtapes($job), ['name' => 'Autre passe', 'run' => 'php artisan test -p --exclude-group=lent']];

        return $job;
    }, 'Autre passe choisit ses groupes'],
]);

it('fait échouer en le disant un test du groupe lancé sans vrai Redis', function (Closure $poser, string $raison): void {
    $poser();

    expect(RedisReel::raisonDeNePasTourner())->toContain($raison);

    try {
        RedisReel::exiger();
        $this->fail('RedisReel::exiger() a laissé passer un test sans vrai Redis.');
    } catch (AssertionFailedError $echec) {
        expect($echec->getMessage())->toStartWith('Ce test du groupe redis exige un vrai Redis : ')
            ->toContain($raison)
            ->toContain('php artisan test --group=redis');
    }
})->with([
    'magasin du cache sur array, celui de phpunit.xml' => [static function (): void {
        Config::set('database.redis.client', 'phpredis');
        Config::set('cache.default', 'array');
    }, extension_loaded('redis') ? 'cache.default vaut « array » au lieu de « redis »' : 'l\'extension redis (phpredis) n\'est pas chargée'],
    'cache et données sur la même base' => [static function (): void {
        Config::set('database.redis.client', 'phpredis');
        Config::set(['cache.default' => 'redis', 'session.driver' => 'redis', 'queue.default' => 'redis']);
        Config::set('database.redis.cache.database', '0');
    }, extension_loaded('redis') ? 'les connexions default et cache partagent la base 0' : 'l\'extension redis (phpredis) n\'est pas chargée'],
    'serveur absent' => [static function (): void {
        Config::set('database.redis.client', 'phpredis');
        Config::set(['cache.default' => 'redis', 'session.driver' => 'redis', 'queue.default' => 'redis']);
        Config::set(['database.redis.default.host' => '127.0.0.1', 'database.redis.cache.host' => '127.0.0.1', 'database.redis.default.port' => '1', 'database.redis.default.max_retries' => 0]);
        Redis::purge('default');
    }, extension_loaded('redis') ? 'la connexion default (127.0.0.1:1) ne répond pas' : 'l\'extension redis (phpredis) n\'est pas chargée'],
    'client inconnu' => [static function (): void {
        Config::set('database.redis.client', 'aucun');
    }, 'le client « aucun » n\'est ni phpredis ni predis'],
]);

/*
 * `exiger()` doit passer avant `RefreshDatabase` : la migration touche déjà
 * le magasin du cache (`forgetCachedPermissions()`), et contre un Redis muet
 * chaque test tombait sur l'erreur brute de la connexion, sans son message.
 * Ce test lance un fichier du groupe dans un processus à part, magasins sur
 * Redis comme l'étape de la CI, vers un port où rien n'écoute, et sur une
 * base SQLite en mémoire que rien ne doit atteindre.
 */
it('fait échouer en le disant chaque test du groupe lancé contre un Redis muet, avant toute migration', function (): void {
    $fichier = 'tests/'.GROUPE_REDIS_DOSSIER.'/LecteurDeRedisReelTest.php';
    $processus = new Process(
        [PHP_BINARY, base_path('vendor/bin/pest'), '--group=redis', '--colors=never', $fichier],
        base_path(),
        [
            'CACHE_STORE' => 'redis',
            'SESSION_DRIVER' => 'redis',
            'QUEUE_CONNECTION' => 'redis',
            'REDIS_CLIENT' => 'phpredis',
            'REDIS_HOST' => '127.0.0.1',
            'REDIS_PORT' => '1',
            'REDIS_MAX_RETRIES' => '0',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'PARATEST' => false,
            'TEST_TOKEN' => false,
            'UNIQUE_TEST_TOKEN' => false,
            'LARAVEL_PARALLEL_TESTING' => false,
            'COLUMNS' => '1000',
        ],
        timeout: 120,
    );
    $processus->run();
    $sortie = $processus->getOutput().$processus->getErrorOutput();

    $compte = preg_match('/Tests:\s+(\d+) failed \((\d+) assertions?\)/', $sortie, $resume);
    $attendu = extension_loaded('redis') ? 'la connexion default (127.0.0.1:1) ne répond pas' : 'l\'extension redis (phpredis) n\'est pas chargée';

    expect($processus->isSuccessful())->toBeFalse($sortie)
        ->and($compte)->toBe(1, "Le fichier n'a pas échoué en entier :\n{$sortie}")
        ->and((int) ($resume[1] ?? 0))->toBeGreaterThan(0)
        ->and($resume[2] ?? null)->toBe($resume[1] ?? null, "Un test du groupe est tombé sur une erreur au lieu du message de RedisReel::exiger() :\n{$sortie}")
        ->and(substr_count($sortie, 'Ce test du groupe redis exige un vrai Redis : '.$attendu))->toBe((int) ($resume[1] ?? 0), $sortie);
});

it('refuse de vider un serveur hors de la boucle locale tant que rien ne le déclare jetable', function (): void {
    Config::set('database.redis.default.host', 'redis');
    Config::set('database.redis.cache.host', 'redis');
    $avant = getenv(RedisReel::VARIABLE_DU_SERVEUR_JETABLE);
    putenv(RedisReel::VARIABLE_DU_SERVEUR_JETABLE);

    expect(RedisReel::raisonDuServeur())->toContain('la connexion default vise « redis », hors de la boucle locale');

    Config::set('database.redis.default.host', '127.0.0.1');

    expect(RedisReel::raisonDuServeur())->toContain('la connexion cache vise « redis »');

    Config::set('database.redis.cache.host', 'localhost');

    expect(RedisReel::raisonDuServeur())->toBeNull();

    Config::set('database.redis.cache.host', 'redis');
    putenv(RedisReel::VARIABLE_DU_SERVEUR_JETABLE.'=1');

    try {
        expect(RedisReel::raisonDuServeur())->toBeNull();
    } finally {
        putenv(RedisReel::VARIABLE_DU_SERVEUR_JETABLE.($avant === false ? '' : "={$avant}"));
    }
});

it('refuse de vider un serveur donné par une URL, qui l\'emporte sur l\'hôte', function (): void {
    $avant = getenv(RedisReel::VARIABLE_DU_SERVEUR_JETABLE);
    putenv(RedisReel::VARIABLE_DU_SERVEUR_JETABLE);
    Config::set('database.redis.default.host', '127.0.0.1');
    Config::set('database.redis.cache.host', '127.0.0.1');
    Config::set('database.redis.cache.url', 'redis://redis:6379');

    try {
        expect(RedisReel::raisonDuServeur())->toContain('la connexion cache est donnée par une URL');
    } finally {
        putenv(RedisReel::VARIABLE_DU_SERVEUR_JETABLE.($avant === false ? '' : "={$avant}"));
    }
});
