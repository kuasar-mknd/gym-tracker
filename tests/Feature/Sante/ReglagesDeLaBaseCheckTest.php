<?php

declare(strict_types=1);

use App\Support\Sante\LecteurDesReglagesDeLaBase;
use App\Support\Sante\ReglagesDeLaBaseCheck;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/*
 * Sur le disque de production, chaque écriture coûtait 250 à 500 ms de
 * synchronisation, et Pulse ajoutait un convoi de verrous (#1668). Les
 * réglages qui l'ont fait tomber à quelques millisecondes vivent dans la
 * pile déployée : un réalignement de la pile qui les perdrait ne se verrait
 * qu'au ressenti. Le contrôle les relit en production, sans rien écrire.
 */

/**
 * Remplace la lecture de MySQL par les valeurs données.
 *
 * @param  array<string, string>  $variables
 */
function reglagesDeLaBaseLus(array $variables): void
{
    app()->instance(LecteurDesReglagesDeLaBase::class, new class($variables) extends LecteurDesReglagesDeLaBase
    {
        /**
         * @param  array<string, string>  $variables
         */
        public function __construct(private readonly array $variables)
        {
        }

        #[\Override]
        public function lire(): array
        {
            return $this->variables;
        }
    });
}

/**
 * Le contrôle, jugé comme en production.
 */
function reglagesDeLaBaseEnProduction(): ReglagesDeLaBaseCheck
{
    app()->detectEnvironment(fn (): string => 'production');

    return ReglagesDeLaBaseCheck::new();
}

it('ne juge pas les réglages de MySQL hors production, où Sail et la CI gardent les défauts', function (): void {
    app()->instance(LecteurDesReglagesDeLaBase::class, new class() extends LecteurDesReglagesDeLaBase
    {
        #[\Override]
        public function lire(): array
        {
            throw new LogicException('Le contrôle a lu MySQL hors production.');
        }
    });

    $resultat = ReglagesDeLaBaseCheck::new()->run();

    expect($resultat->status->value)->toBe('ok')
        ->and($resultat->shortSummary)->toBe('Non jugé hors production')
        ->and($resultat->notificationMessage)->toContain('ne juge que la production');
});

it('met les réglages de la base au vert en production : journal synchronisé chaque seconde, sans journal binaire, Pulse coupé', function (): void {
    reglagesDeLaBaseLus(['innodb_flush_log_at_trx_commit' => '2', 'log_bin' => 'OFF']);
    Config::set('pulse.enabled', false);

    $resultat = reglagesDeLaBaseEnProduction()->run();

    expect($resultat->status->value)->toBe('ok', $resultat->notificationMessage)
        ->and($resultat->shortSummary)->toBe('flush 2 · log_bin OFF · Pulse coupé')
        ->and($resultat->meta)->toBe([
            'innodb_flush_log_at_trx_commit' => '2',
            'log_bin' => 'OFF',
            'pulse' => false,
        ]);
});

it('met les réglages de la base au rouge quand chaque validation se synchronise sur le disque', function (): void {
    reglagesDeLaBaseLus(['innodb_flush_log_at_trx_commit' => '1', 'log_bin' => 'OFF']);
    Config::set('pulse.enabled', false);

    $resultat = reglagesDeLaBaseEnProduction()->run();

    expect($resultat->status->value)->toBe('failed')
        ->and($resultat->notificationMessage)
        ->toContain('innodb_flush_log_at_trx_commit vaut 1')
        ->toContain('250 à 500 ms')
        ->toContain('--innodb-flush-log-at-trx-commit=2');
});

it('met les réglages de la base au rouge quand le journal binaire est actif', function (): void {
    reglagesDeLaBaseLus(['innodb_flush_log_at_trx_commit' => '2', 'log_bin' => 'ON']);
    Config::set('pulse.enabled', false);

    $resultat = reglagesDeLaBaseEnProduction()->run();

    expect($resultat->status->value)->toBe('failed')
        ->and($resultat->notificationMessage)
        ->toContain('log_bin vaut ON')
        ->toContain('--skip-log-bin');
});

it('met les réglages de la base à l’orange quand Pulse enregistre en production', function (): void {
    reglagesDeLaBaseLus(['innodb_flush_log_at_trx_commit' => '2', 'log_bin' => 'OFF']);
    Config::set('pulse.enabled', true);

    $resultat = reglagesDeLaBaseEnProduction()->run();

    expect($resultat->status->value)->toBe('warning')
        ->and($resultat->shortSummary)->toBe('flush 2 · log_bin OFF · Pulse actif')
        ->and($resultat->notificationMessage)
        ->toContain('Pulse enregistre')
        ->toContain('PULSE_ENABLED=false');
});

it('dit tout ce qui ne va pas, le rouge l’emportant sur l’orange', function (): void {
    reglagesDeLaBaseLus(['innodb_flush_log_at_trx_commit' => '1', 'log_bin' => 'ON']);
    Config::set('pulse.enabled', true);

    $resultat = reglagesDeLaBaseEnProduction()->run();

    expect($resultat->status->value)->toBe('failed')
        ->and($resultat->notificationMessage)
        ->toContain('innodb_flush_log_at_trx_commit vaut 1')
        ->toContain('log_bin vaut ON')
        ->toContain('Pulse enregistre');
});

/*
 * Un réglage que la lecture n'a pas rendu n'est pas un bon réglage : le vert
 * dirait « vérifié » de ce que personne n'a lu.
 */
it('met les réglages de la base à l’orange quand MySQL ne les rend pas, plutôt que de les croire bons', function (): void {
    reglagesDeLaBaseLus(['innodb_flush_log_at_trx_commit' => '2']);
    Config::set('pulse.enabled', false);

    $resultat = reglagesDeLaBaseEnProduction()->run();

    expect($resultat->status->value)->toBe('warning')
        ->and($resultat->shortSummary)->toBe('flush 2 · log_bin ? · Pulse coupé')
        ->and($resultat->notificationMessage)->toContain('log_bin')->toContain('illisible');
});

it('lit les deux réglages dans MySQL, sans rien y écrire', function (): void {
    DB::enableQueryLog();

    $variables = app(LecteurDesReglagesDeLaBase::class)->lire();

    expect(array_keys($variables))->toEqualCanonicalizing(['innodb_flush_log_at_trx_commit', 'log_bin'])
        ->and($variables['innodb_flush_log_at_trx_commit'])->toBeIn(['0', '1', '2'])
        ->and($variables['log_bin'])->toBeIn(['ON', 'OFF']);

    $requetes = array_map(fn (array $requete): string => strtolower(ltrim((string) $requete['query'])), DB::getQueryLog());

    expect($requetes)->toHaveCount(1)
        ->and($requetes[0])->toStartWith('show global variables');
});
