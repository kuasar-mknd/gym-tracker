<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Process\FakeInvokedProcess;
use Illuminate\Process\InvokedProcess;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\WorkerIdle;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Process;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\MasterSupervisor;
use Laravel\Horizon\ProvisioningPlan;
use Laravel\Horizon\SupervisorOptions;
use Tests\Support\RedisReel;

/*
 * La file de production, contre un vrai Redis (#2151, étape 5) : Horizon sert
 * la connexion `redis` avec les options de son superviseur. Un processus qui
 * trouve la file vide attendait jusqu'au `sleep` du superviseur, trois
 * secondes par défaut, avant de la relire : `block_for` était nul, si bien
 * que la lecture rendait la main aussitôt. Avec `block_for`, la lecture
 * attend sur le serveur et rend le travail dès qu'il arrive.
 */

/**
 * Les options du superviseur que Horizon lance en production.
 */
function redisReelOptionsDuSuperviseur(): SupervisorOptions
{
    /** @var array<string, array<string, SupervisorOptions>> $plan */
    $plan = ProvisioningPlan::get(MasterSupervisor::name())->toSupervisorOptions();
    $options = $plan['production']['supervisor-1'] ?? null;
    assert($options instanceof SupervisorOptions);

    return $options;
}

/**
 * Un autre client, lancé à part, qui fait passer à chacun des instants donnés
 * un travail rangé dans `$attente` dans la file `$file`, comme l'écriture
 * d'une mise en file (la liste, puis son signal `:notify`), et imprime
 * l'heure de chaque passage.
 *
 * @param  list<float>  $instants
 */
function redisReelPousserAuxInstants(string $attente, string $file, array $instants): InvokedProcess|FakeInvokedProcess
{
    $code = <<<'PHP'
        [, $hote, $port, $motDePasse, $base, $attente, $file] = $argv;
        $redis = new Redis();
        $redis->connect($hote, (int) $port);
        if ($motDePasse !== '') {
            $redis->auth($motDePasse);
        }
        $redis->select((int) $base);
        $poussees = [];
        foreach (array_slice($argv, 7) as $instant) {
            while (microtime(true) < (float) $instant) {
                usleep(1000);
            }
            $redis->lMove($attente, $file, 'LEFT', 'RIGHT');
            $redis->rPush($file.':notify', 1);
            $poussees[] = microtime(true);
        }
        fwrite(STDOUT, (string) json_encode($poussees));
        PHP;

    $reglage = static function (string $cle): string {
        $valeur = config("database.redis.default.{$cle}");

        return is_scalar($valeur) ? (string) $valeur : '';
    };

    return Process::start([
        PHP_BINARY, '-r', $code, '--',
        $reglage('host'),
        $reglage('port'),
        $reglage('password'),
        $reglage('database'),
        RedisReel::cleComplete('queues:'.$attente),
        RedisReel::cleComplete('queues:'.$file),
        ...array_map(static fn (float $instant): string => sprintf('%.6F', $instant), $instants),
    ]);
}

/*
 * Deux travaux arrivent dans la file vide : le premier 1,2 s après le début,
 * quand le processus l'a déjà trouvée vide une fois ; le second 3,7 s après,
 * quand une attente de deux secondes sur le serveur a fini sans rien. Sans
 * `block_for`, le premier attend le réveil du processus ; avec `block_for`
 * mais un `sleep`, le second attend la fin du sommeil qui suit l'attente vide ;
 * sans `block_for` ni `sleep`, le processus relit la file à vide sans fin, ce
 * que compte `WorkerIdle`.
 */
it('fait prendre en moins d’une seconde un travail mis sur une file vide, par un processus de Horizon', function (): void {
    $superviseur = redisReelOptionsDuSuperviseur();

    foreach ([1, 2] as $travail) {
        dispatch(static function (): void {
        })->onConnection('redis')->onQueue('redis-reel-attente');
    }

    $pris = [];
    $lecturesVides = 0;
    Event::listen(JobProcessing::class, static function () use (&$pris): void {
        $pris[] = microtime(true);
    });
    Event::listen(WorkerIdle::class, static function () use (&$lecturesVides): void {
        $lecturesVides++;
    });

    $debut = microtime(true);
    $pousseur = redisReelPousserAuxInstants('redis-reel-attente', 'redis-reel', [$debut + 1.2, $debut + 3.7]);

    $travailleur = new Worker(app('queue'), app('events'), app(ExceptionHandler::class), static fn (): bool => false);

    try {
        $travailleur->daemon('redis', 'redis-reel', new WorkerOptions(
            name: 'redis-reel',
            memory: 4096,
            timeout: $superviseur->timeout,
            sleep: $superviseur->sleep,
            maxTries: $superviseur->maxTries,
            maxJobs: 2,
            maxTime: 12,
        ));
    } finally {
        foreach ([SIGQUIT, SIGTERM, SIGINT, SIGUSR2, SIGCONT, SIGALRM] as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }
        pcntl_alarm(0);
    }

    $poussees = json_decode($pousseur->wait()->output(), true);

    expect($poussees)->toBeArray()->toHaveCount(2)
        ->and($pris)->toHaveCount(2);
    assert(is_array($poussees));

    foreach ($poussees as $rang => $poussee) {
        assert(is_float($poussee));

        expect($pris[$rang] - $poussee)->toBeLessThan(1.0, sprintf(
            'Le travail %d a attendu %.2f s dans la file vide (block_for %s, sleep %s du superviseur).',
            $rang + 1,
            $pris[$rang] - $poussee,
            var_export(config('queue.connections.redis.block_for'), true),
            var_export($superviseur->sleep, true),
        ));
    }

    expect($lecturesVides)->toBeLessThan(10, "Le processus a lu la file vide {$lecturesVides} fois en quelques secondes : il tourne à vide au lieu d'attendre sur le serveur.");
});

it('range un instantané des métriques de Horizon par horizon:snapshot, que la page « Metrics » lit', function (): void {
    dispatch(static function (): void {
    })->onConnection('redis')->onQueue('redis-reel');

    new Worker(app('queue'), app('events'), app(ExceptionHandler::class), static fn (): bool => false)
        ->runNextJob('redis', 'redis-reel', new WorkerOptions(sleep: 0, maxTries: 1));

    expect(Artisan::call('horizon:snapshot'))->toBe(0);

    $metriques = app(MetricsRepository::class);

    expect($metriques->measuredQueues())->toContain('redis-reel')
        ->and($metriques->snapshotsForQueue('redis-reel'))->toHaveCount(1);
});
