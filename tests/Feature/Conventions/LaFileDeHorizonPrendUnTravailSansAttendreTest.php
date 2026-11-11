<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
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
 */

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
    $refus = [];

    if (! is_int($bloque) || $bloque < 1) {
        $refus[] = sprintf(
            'block_for vaut %s : un entier d’au moins une seconde fait attendre la lecture sur le serveur ; nul, elle rend la main aussitôt, et zéro bloque sans fin.',
            var_export($bloque, true),
        );
    } elseif ($bloque >= $superviseur->timeout || $bloque >= (int) ($connexion['retry_after'] ?? 0)) {
        $refus[] = sprintf(
            'block_for (%d s) doit rester sous le timeout du superviseur (%d s) et le retry_after de la connexion (%s s).',
            $bloque,
            $superviseur->timeout,
            var_export($connexion['retry_after'] ?? null, true),
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

    foreach (ProvisioningPlan::get(MasterSupervisor::name())->toSupervisorOptions() as $environnement => $options) {
        foreach ($options as $nom => $superviseur) {
            assert($superviseur instanceof SupervisorOptions);
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

    expect(fileReglagesRefuses($connexion, $superviseur))->toHaveCount(1)
        ->and(fileReglagesRefuses($connexion, $superviseur)[0])->toStartWith($refus);
})->with([
    'block_for nul, sommeil à zéro : relecture à vide sans fin' => [['block_for' => null, 'retry_after' => 90], 0, 'block_for vaut NULL'],
    'block_for à zéro : blocage sans fin' => [['block_for' => 0, 'retry_after' => 90], 0, 'block_for vaut 0'],
    'block_for en chaîne, venu d’une variable' => [['block_for' => '2', 'retry_after' => 90], 0, 'block_for vaut \'2\''],
    'block_for au timeout du superviseur' => [['block_for' => 60, 'retry_after' => 90], 0, 'block_for (60 s) doit rester sous'],
    'block_for au retry_after' => [['block_for' => 30, 'retry_after' => 30], 0, 'block_for (30 s) doit rester sous'],
    'sommeil du défaut de Horizon' => [['block_for' => 2, 'retry_after' => 90], 3, 'sleep vaut 3'],
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

    expect($instantanes)->toHaveCount(1)
        ->and($instantanes[0]->expression)->toBe('*/5 * * * *')
        ->and(config('horizon.metrics.trim_snapshots.queue'))->toBe(24)
        ->and(config('horizon.metrics.trim_snapshots.job'))->toBe(24)
        ->and((string) end($evenements)->command)->toEndWith('health:schedule-check-heartbeat');
});
