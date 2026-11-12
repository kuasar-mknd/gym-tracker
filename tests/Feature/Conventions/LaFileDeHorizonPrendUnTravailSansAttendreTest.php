<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\SupervisorOptions;

/*
 * Horizon sert la file de production (`worker` lance `php artisan horizon`,
 * sur la connexion `redis`). Un processus qui trouve la file vide la relit
 * après le `sleep` de son superviseur, et la lecture n'attend sur le serveur
 * que si la connexion porte `block_for` (`RedisQueue::retrieveNextJob()`).
 * `block_for` était nul et `sleep` absent, donc de trois secondes
 * (`SupervisorOptions`) : un travail neuf attendait jusqu'à trois secondes
 * dans une file vide (#2151, étape 5).
 *
 * `block_for` à deux secondes et `sleep` à zéro : la lecture attend sur le
 * serveur et rend le travail dès son arrivée, et un processus ne dort jamais
 * entre deux lectures. Les deux vont ensemble : `sleep` à zéro sans
 * `block_for` relirait la file à vide sans fin ; `block_for` à zéro
 * bloquerait sans fin, et SIGTERM attendrait le travail suivant (documentation
 * des files de Laravel 13). La valeur s'écrit en dur : une variable vide
 * donnerait zéro. Le blocage reste sous le `timeout` du superviseur et le
 * `retry_after` de la connexion.
 *
 * La configuration résolue ne suffit pas à tenir « en dur » : les tests ne
 * posent aucune variable, et `(int) env('REDIS_QUEUE_BLOCK_FOR', 2)` y vaut
 * 2. La garde lit donc aussi ce qu'écrivent config/queue.php et
 * config/horizon.php : un entier, sans `env()`.
 */

/**
 * L'expression écrite d'une clé d'un fichier de configuration, telle quelle,
 * trouvée par son chemin de tableaux (`connections`, `redis`, `block_for`),
 * chaque bloc à son retrait (le fichier est formaté par Pint). `null` quand
 * le chemin ne s'y lit pas.
 *
 * @param  non-empty-list<string>  $chemin
 */
function fileExpressionEcrite(string $source, array $chemin): ?string
{
    $lignes = explode("\n", $source);
    $debut = 0;
    $fin = count($lignes);
    $retrait = '';
    $cle = array_pop($chemin);

    foreach ($chemin as $bloc) {
        $ouverture = null;

        for ($rang = $debut; $rang < $fin; $rang++) {
            if (preg_match('/^'.preg_quote($retrait.'    ', '/').'\''.preg_quote($bloc, '/').'\'\s*=>\s*\[\s*$/', $lignes[$rang]) === 1) {
                $ouverture = $rang;

                break;
            }
        }

        if ($ouverture === null) {
            return null;
        }

        $debut = $ouverture + 1;
        $retrait .= '    ';

        for ($rang = $debut; $rang < $fin; $rang++) {
            if (preg_match('/^'.preg_quote($retrait, '/').'\],?\s*$/', $lignes[$rang]) === 1) {
                $fin = $rang;

                break;
            }
        }
    }

    for ($rang = $debut; $rang < $fin; $rang++) {
        if (preg_match('/^'.preg_quote($retrait.'    ', '/').'\''.preg_quote($cle, '/').'\'\s*=>\s*(.*?),?\s*$/', $lignes[$rang], $trouve) === 1) {
            return $trouve[1];
        }
    }

    return null;
}

/**
 * Ce que la garde refuse dans ce qu'écrivent config/queue.php et
 * config/horizon.php : `block_for` de la connexion `redis` et `sleep` de
 * chaque superviseur doivent être des entiers écrits en dur.
 *
 * @return list<string>
 */
function fileEcrituresRefusees(string $queue, string $horizon): array
{
    $refus = [];
    $bloque = fileExpressionEcrite($queue, ['connections', 'redis', 'block_for']);

    if ($bloque === null) {
        $refus[] = 'config/queue.php n’écrit pas block_for dans la connexion redis.';
    } elseif (preg_match('/^\d+$/', $bloque) !== 1) {
        $refus[] = sprintf('config/queue.php écrit block_for « %s » : un entier en dur, car une variable vide donnerait zéro, qui bloque sans fin.', $bloque);
    }

    if (fileExpressionEcrite($horizon, ['defaults', 'supervisor-1', 'sleep']) === null) {
        $refus[] = 'config/horizon.php n’écrit pas sleep dans defaults.supervisor-1 : Horizon dormirait trois secondes après chaque lecture vide.';
    }

    foreach (explode("\n", $horizon) as $rang => $ligne) {
        if (preg_match('/^\s*\'sleep\'\s*=>\s*(.*?),?\s*$/', $ligne, $trouve) === 1 && preg_match('/^\d+$/', $trouve[1]) !== 1) {
            $refus[] = sprintf('config/horizon.php écrit sleep « %s » à la ligne %d : un entier en dur, sans variable.', $trouve[1], $rang + 1);
        }
    }

    return $refus;
}

/**
 * Ce que la garde refuse dans le réglage d'une connexion de file et d'un
 * superviseur de Horizon.
 *
 * @param  array<array-key, mixed>  $connexion
 * @return list<string>
 */
function fileReglagesRefuses(array $connexion, SupervisorOptions $superviseur): array
{
    $bloque = $connexion['block_for'] ?? null;
    $relance = $connexion['retry_after'] ?? null;
    $refus = [];

    if (! is_int($bloque) || $bloque < 1) {
        $refus[] = sprintf(
            'block_for vaut %s : un entier d’au moins une seconde fait attendre la lecture sur le serveur ; nul, elle rend la main aussitôt, et zéro bloque sans fin.',
            var_export($bloque, true),
        );
    } elseif ($bloque >= $superviseur->timeout || ! is_int($relance) || $bloque >= $relance) {
        $refus[] = sprintf(
            'block_for (%d s) doit rester sous le timeout du superviseur (%d s) et le retry_after de la connexion (%s s).',
            $bloque,
            $superviseur->timeout,
            var_export($relance, true),
        );
    }

    if ((int) $superviseur->sleep !== 0) {
        $refus[] = sprintf(
            'sleep vaut %s pour %s : après chaque lecture vide, le processus dort autant avant de relire la file.',
            var_export($superviseur->sleep, true),
            $superviseur->name,
        );
    }

    return $refus;
}

/**
 * Les superviseurs que Horizon lance, par environnement.
 *
 * @return array<string, SupervisorOptions>
 */
function fileSuperviseursDeHorizon(): array
{
    $superviseurs = [];

    /** @var array<string, array<string, SupervisorOptions>> $plan */
    $plan = ProvisioningPlan::get(MasterSupervisor::name())->toSupervisorOptions();

    foreach ($plan as $environnement => $options) {
        foreach ($options as $nom => $superviseur) {
            $superviseurs["{$environnement}.{$nom}"] = $superviseur;
        }
    }

    return $superviseurs;
}

it('fait attendre sur le serveur la lecture de la file, sans jamais dormir entre deux lectures', function (): void {
    $connexion = config('queue.connections.redis');
    assert(is_array($connexion));

    $superviseurs = fileSuperviseursDeHorizon();
    expect($superviseurs)->toHaveKey('production.supervisor-1');

    foreach ($superviseurs as $nom => $superviseur) {
        expect($superviseur->connection)->toBe('redis')
            ->and(fileReglagesRefuses($connexion, $superviseur))->toBe([], "Le superviseur {$nom} ne prend pas un travail neuf dès son arrivée.");
    }
});

it('refuse un blocage nul, nul en secondes, trop long, ou un sommeil entre deux lectures', function (array $connexion, int $sommeil, string $refus): void {
    $superviseur = SupervisorOptions::fromArray([
        'name' => 'essai',
        'connection' => 'redis',
        'timeout' => 60,
        'sleep' => $sommeil,
    ]);

    $refuses = fileReglagesRefuses($connexion, $superviseur);

    expect($refuses)->toHaveCount(1)
        ->and(str_starts_with($refuses[0] ?? '', $refus))->toBeTrue(implode("\n", $refuses));
})->with([
    'block_for nul, sommeil à zéro : relecture à vide sans fin' => [['block_for' => null, 'retry_after' => 90], 0, 'block_for vaut NULL'],
    'block_for à zéro : blocage sans fin' => [['block_for' => 0, 'retry_after' => 90], 0, 'block_for vaut 0'],
    'block_for en chaîne, venu d’une variable' => [['block_for' => '2', 'retry_after' => 90], 0, 'block_for vaut \'2\''],
    'block_for au timeout du superviseur' => [['block_for' => 60, 'retry_after' => 90], 0, 'block_for (60 s) doit rester sous'],
    'block_for au retry_after' => [['block_for' => 30, 'retry_after' => 30], 0, 'block_for (30 s) doit rester sous'],
    'sommeil du défaut de Horizon' => [['block_for' => 2, 'retry_after' => 90], 3, 'sleep vaut 3'],
]);

it('écrit en dur block_for et sleep, sans variable qu’une valeur vide ferait tomber à zéro', function (): void {
    expect(fileEcrituresRefusees(File::get(base_path('config/queue.php')), File::get(base_path('config/horizon.php'))))->toBe([]);
});

it('refuse block_for ou sleep lus d’une variable, ou absents', function (string $fichier, string $avant, string $apres, string $refus): void {
    $queue = File::get(base_path('config/queue.php'));
    $horizon = File::get(base_path('config/horizon.php'));
    $retouche = $fichier === 'queue' ? $queue : $horizon;
    expect(substr_count($retouche, $avant))->toBe(1, "« {$avant} » ne se trouve plus une seule fois dans config/{$fichier}.php.");
    $retouche = str_replace($avant, $apres, $retouche);

    $refuses = $fichier === 'queue' ? fileEcrituresRefusees($retouche, $horizon) : fileEcrituresRefusees($queue, $retouche);

    expect($refuses)->toHaveCount(1)
        ->and(str_starts_with($refuses[0] ?? '', $refus))->toBeTrue(implode("\n", $refuses));
})->with([
    'block_for venu d’une variable, la forme de l’issue' => ['queue', "'block_for' => 2,", "'block_for' => (int) env('REDIS_QUEUE_BLOCK_FOR', 2),", 'config/queue.php écrit block_for « (int) env('],
    'block_for venu d’une variable, sur plusieurs lignes' => ['queue', "'block_for' => 2,", "'block_for' => (int) env(\n                'REDIS_QUEUE_BLOCK_FOR',\n                2,\n            ),", 'config/queue.php écrit block_for « (int) env('],
    'block_for retiré de la connexion redis' => ['queue', "            'block_for' => 2,\n", '', 'config/queue.php n’écrit pas block_for'],
    'sleep des défauts venu d’une variable' => ['horizon', "'sleep' => 0,", "'sleep' => (int) env('HORIZON_SLEEP', 0),", 'config/horizon.php écrit sleep « (int) env('],
    'sleep des défauts retiré' => ['horizon', "            'sleep' => 0,\n", '', 'config/horizon.php n’écrit pas sleep'],
    'sleep de la production venu d’une variable' => ['horizon', "                'balanceCooldown' => 3,\n", "                'balanceCooldown' => 3,\n                'sleep' => (int) env('HORIZON_SLEEP', 0),\n", 'config/horizon.php écrit sleep « (int) env('],
]);

it('accepte un blocage de deux secondes sans sommeil', function (): void {
    $superviseur = SupervisorOptions::fromArray(['name' => 'essai', 'connection' => 'redis', 'timeout' => 60, 'sleep' => 0]);

    expect(fileReglagesRefuses(['block_for' => 2, 'retry_after' => 90], $superviseur))->toBe([]);
});

/*
 * La page « Metrics » de Horizon ne lit que les instantanés que range
 * `horizon:snapshot`, et rien ne le lançait : elle restait vide. Horizon en
 * garde `trim_snapshots` par file et par travail ; toutes les cinq minutes,
 * le rythme de sa documentation, vingt-quatre instantanés couvrent deux
 * heures. Hors du moniteur, comme les tâches de santé : 288 passages par
 * jour, trois lignes chacun, et son absence se lit sur la page elle-même.
 */
it('range un instantané des métriques de Horizon toutes les cinq minutes, avant le battement du planificateur', function (): void {
    $evenements = array_values(app(Schedule::class)->events());
    $instantanes = array_values(array_filter(
        $evenements,
        static fn (Event $evenement): bool => preg_match("/artisan'?\\s+horizon:snapshot\$/", (string) $evenement->command) === 1,
    ));

    $dernier = end($evenements);
    assert($dernier instanceof Event);

    expect($instantanes)->toHaveCount(1)
        ->and($instantanes[0]->expression)->toBe('*/5 * * * *')
        ->and(config('horizon.metrics.trim_snapshots.queue'))->toBe(24)
        ->and(config('horizon.metrics.trim_snapshots.job'))->toBe(24)
        ->and((string) $dernier->command)->toEndWith('health:schedule-check-heartbeat');
});
