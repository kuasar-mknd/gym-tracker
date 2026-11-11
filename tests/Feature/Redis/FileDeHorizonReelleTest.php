<?php

declare(strict_types=1);

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Process\InvokedProcess;
use Illuminate\Queue\Events\JobProcessing;
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
    $options = ProvisioningPlan::get(MasterSupervisor::name())->toSupervisorOptions()['production']['supervisor-1'] ?? null;
    assert($options instanceof SupervisorOptions);

    return $options;
}

/**
 * Un autre client, lancé à part, qui fait passer à l'instant donné le travail
 * rangé dans `$attente` dans la file `$file`, comme l'écriture d'une mise en
 * file (la liste, puis son signal `:notify`), et imprime l'heure de son
 * passage.
 */
function redisReelPousserALInstant(string $attente, string $file, float $instant): InvokedProcess
{
    $code = <<<'PHP'
        [, $hote, $port, $motDePasse, $base, $attente, $file, $instant] = $argv;
        $redis = new Redis();
        $redis->connect($hote, (int) $port);
        if ($motDePasse !== '') {
            $redis->auth($motDePasse);
        }
        $redis->select((int) $base);
        while (microtime(true) < (float) $instant) {
            usleep(1000);
        }
        $redis->lMove($attente, $file, 'LEFT', 'RIGHT');
        $redis->rPush($file.':notify', 1);
        echo json_encode(microtime(true));
        PHP;

    $motDePasse = config('database.redis.default.password');

    return Process::start([
        PHP_BINARY, '-r', $code, '--',
        (string) config('database.redis.default.host'),
        (string) config('database.redis.default.port'),
        is_string($motDePasse) ? $motDePasse : '',
        (string) config('database.redis.default.database'),
        RedisReel::cleComplete('queues:'.$attente),
        RedisReel::cleComplete('queues:'.$file),
        sprintf('%.6F', $instant),
    ]);
}

it('fait prendre en moins d’une seconde un travail mis sur une file vide, par un processus de Horizon', function (): void {
    $superviseur = redisReelOptionsDuSuperviseur();
    dispatch(static function (): void {
    })->onConnection('redis')->onQueue('redis-reel-attente');

    $pris = null;
    Event::listen(JobProcessing::class, static function () use (&$pris): void {
        $pris = microtime(true);
    });

    // Le travail arrive 1,2 s après le début : le processus a déjà trouvé la
    // file vide une fois, et attend sur le serveur ou dort.
    $pousseur = redisReelPousserALInstant('redis-reel-attente', 'redis-reel', microtime(true) + 1.2);

    $travailleur = new Worker(app('queue'), app('events'), app(ExceptionHandler::class), static fn (): bool => false);

    try {
        $travailleur->daemon('redis', 'redis-reel', new WorkerOptions(
            name: 'redis-reel',
            memory: 4096,
            timeout: $superviseur->timeout,
            sleep: $superviseur->sleep,
            maxTries: $superviseur->maxTries,
            maxJobs: 1,
            maxTime: 10,
        ));
    } finally {
        foreach ([SIGQUIT, SIGTERM, SIGINT, SIGUSR2, SIGCONT, SIGALRM] as $signal) {
            pcntl_signal($signal, SIG_DFL);
        }
        pcntl_alarm(0);
    }

    $poussee = json_decode($pousseur->wait()->output(), true);

    expect($poussee)->toBeFloat()
        ->and($pris)->toBeFloat();
    assert(is_float($poussee) && is_float($pris));

    expect($pris - $poussee)->toBeLessThan(1.0, sprintf(
        'Le travail a attendu %.2f s dans la file vide (block_for %s, sleep %s du superviseur).',
        $pris - $poussee,
        var_export(config('queue.connections.redis.block_for'), true),
        var_export($superviseur->sleep, true),
    ));
});

it('range un instantané des métriques de Horizon par horizon:snapshot, que la page « Metrics » lit', function (): void {
    dispatch(static function (): void {
    })->onConnection('redis')->onQueue('redis-reel');

    (new Worker(app('queue'), app('events'), app(ExceptionHandler::class), static fn (): bool => false))
        ->runNextJob('redis', 'redis-reel', new WorkerOptions(sleep: 0, maxTries: 1));

    expect(Artisan::call('horizon:snapshot'))->toBe(0);

    $metriques = app(MetricsRepository::class);

    expect($metriques->measuredQueues())->toContain('redis-reel')
        ->and($metriques->snapshotsForQueue('redis-reel'))->toHaveCount(1);
});
