<?php

declare(strict_types=1);

use App\Support\Sante\LecteurDesReglagesDeLaBase;
use App\Support\Sante\MesureDeLaBase;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/*
 * `php artisan base:mesurer [--pendant=200] [--json]` (#1668) : le protocole
 * de mesure du 03/09 rejoué depuis l'application. Deux relevés des réglages et
 * des compteurs de la base, séparés par une attente pendant laquelle on saisit
 * des séries, puis les écarts et un verdict. Elle ne fait que lire.
 */

beforeEach(function (): void {
    Sleep::fake();
});

/**
 * Les réglages relevés le 03/09 avant le réglage de la base.
 *
 * @return array<string, string>
 */
function mesureDeLaBaseReglagesDAvant(): array
{
    return [
        'log_bin' => 'ON',
        'sync_binlog' => '1',
        'innodb_flush_log_at_trx_commit' => '1',
        'innodb_redo_log_capacity' => '104857600',
        'innodb_io_capacity' => '10000',
        'innodb_io_capacity_max' => '20000',
        'innodb_flush_method' => 'O_DIRECT',
        'innodb_doublewrite' => 'ON',
    ];
}

/**
 * Les réglages relevés le 03/09 après le réglage de la base.
 *
 * @return array<string, string>
 */
function mesureDeLaBaseReglagesDApres(): array
{
    return [
        ...mesureDeLaBaseReglagesDAvant(),
        'log_bin' => 'OFF',
        'innodb_flush_log_at_trx_commit' => '2',
        'innodb_redo_log_capacity' => '268435456',
        'innodb_io_capacity' => '200',
        'innodb_io_capacity_max' => '1000',
    ];
}

/**
 * Des compteurs, ceux que l'on ne donne pas restant à zéro.
 *
 * @param  array<string, int>  $valeurs
 * @return array<string, string>
 */
function mesureDeLaBaseCompteurs(array $valeurs): array
{
    $compteurs = array_fill_keys(MesureDeLaBase::COMPTEURS, '0');

    foreach ($valeurs as $nom => $valeur) {
        $compteurs[$nom] = (string) $valeur;
    }

    return $compteurs;
}

/**
 * Remplace la lecture de MySQL par les relevés donnés, dans l'ordre : le
 * premier appel de chaque lecture rend le premier relevé, le second le second.
 *
 * @param  list<array{variables: array<string, string>, compteurs: array<string, string>}>  $releves
 */
function mesureDeLaBaseReleves(array $releves): void
{
    app()->instance(LecteurDesReglagesDeLaBase::class, new class($releves) extends LecteurDesReglagesDeLaBase
    {
        private int $variablesLues = 0;

        private int $compteursLus = 0;

        /**
         * @param  list<array{variables: array<string, string>, compteurs: array<string, string>}>  $releves
         */
        public function __construct(private readonly array $releves)
        {
        }

        #[\Override]
        public function lireLesVariables(array $noms): array
        {
            return $this->releves[$this->variablesLues++ % count($this->releves)]['variables'];
        }

        #[\Override]
        public function lireLesCompteurs(array $noms): array
        {
            return $this->releves[$this->compteursLus++ % count($this->releves)]['compteurs'];
        }
    });
}

/**
 * Les deux relevés du 03/09 avant réglage : +145 attentes de verrou et
 * +195 637 ms en 205 s, 10,1 s au maximum, +219 fsync au journal et +418
 * aux données.
 */
function mesureDeLaBaseDuTroisSeptembreAvantReglage(): void
{
    mesureDeLaBaseReleves([
        ['variables' => mesureDeLaBaseReglagesDAvant(), 'compteurs' => mesureDeLaBaseCompteurs([
            'Innodb_row_lock_waits' => 1_200, 'Innodb_row_lock_time' => 400_000, 'Innodb_row_lock_time_max' => 6_000,
            'Innodb_os_log_fsyncs' => 50_000, 'Innodb_data_fsyncs' => 90_000, 'Uptime' => 86_400,
        ])],
        ['variables' => mesureDeLaBaseReglagesDAvant(), 'compteurs' => mesureDeLaBaseCompteurs([
            'Innodb_row_lock_waits' => 1_345, 'Innodb_row_lock_time' => 595_637, 'Innodb_row_lock_time_max' => 10_100,
            'Innodb_os_log_fsyncs' => 50_219, 'Innodb_data_fsyncs' => 90_418, 'Uptime' => 86_605,
        ])],
    ]);
}

/**
 * Les deux relevés du 03/09 après réglage : aucune attente en 69 s, +67 fsync
 * au journal et +161 aux données.
 */
function mesureDeLaBaseDuTroisSeptembreApresReglage(): void
{
    mesureDeLaBaseReleves([
        ['variables' => mesureDeLaBaseReglagesDApres(), 'compteurs' => mesureDeLaBaseCompteurs([
            'Innodb_os_log_fsyncs' => 300, 'Innodb_data_fsyncs' => 700, 'Uptime' => 1_000,
        ])],
        ['variables' => mesureDeLaBaseReglagesDApres(), 'compteurs' => mesureDeLaBaseCompteurs([
            'Innodb_os_log_fsyncs' => 367, 'Innodb_data_fsyncs' => 861, 'Uptime' => 1_069,
        ])],
    ]);
}

/**
 * La sortie de `base:mesurer --json`, décodée.
 *
 * @param  array<string, mixed>  $options
 * @return array<string, mixed>
 */
function mesureDeLaBaseEnJson(array $options = []): array
{
    $code = Artisan::call('base:mesurer', ['--json' => true, ...$options]);
    // Artisan::output() vide le tampon : une seule lecture.
    $sortie = Artisan::output();

    expect($code)->toBe(0, $sortie);

    /** @var array<string, mixed> */
    return json_decode($sortie, true, flags: JSON_THROW_ON_ERROR);
}

it('donne le convoi de verrous sur les relevés du 03/09 avant réglage, à 1 349 ms par attente', function (): void {
    mesureDeLaBaseDuTroisSeptembreAvantReglage();

    $mesure = mesureDeLaBaseEnJson(['--pendant' => 200]);

    expect($mesure['verdict'])->toBe('convoi de verrous')
        ->and($mesure['fenetre_s'])->toBe(205)
        ->and($mesure['attentes_de_verrou'])->toBe([
            'nombre' => 145,
            'ms' => 195_637,
            'ms_par_attente' => 1_349,
            'maximum_ms' => 10_100,
            'maximum_atteint_pendant_la_fenetre' => true,
            'en_cours_au_premier_releve' => 0,
            'en_cours_au_second_releve' => 0,
        ])
        ->and($mesure['fsync_par_seconde'])->toBe(['journal' => 1.07, 'donnees' => 2.04])
        ->and($mesure['ecarts'])->toMatchArray([
            'Innodb_log_waits' => 0,
            'Innodb_buffer_pool_wait_free' => 0,
            'Innodb_os_log_fsyncs' => 219,
            'Innodb_data_fsyncs' => 418,
        ])
        ->and($mesure['reglages'])->toBe(mesureDeLaBaseReglagesDAvant())
        ->and($mesure['non_rendus'])->toBe([]);

    // Le 03/09, c'était les deux à la fois : le convoi l'emporte, la
    // double synchronisation reste dite.
    expect($mesure['raisons'])->toHaveCount(2)
        ->and(data_get($mesure, 'raisons.0'))->toContain('145 attentes de verrou en 205 s')->toContain('1 349 ms par attente')
        ->and(data_get($mesure, 'raisons.1'))->toContain('se synchronise 2 fois sur le stockage');
});

it('écrit la même mesure en lignes lisibles sans --json', function (): void {
    mesureDeLaBaseDuTroisSeptembreAvantReglage();

    expect(Artisan::call('base:mesurer'))->toBe(0);

    expect(Artisan::output())
        ->toContain('Fenêtre : 205 s')
        ->toContain('log_bin ON · sync_binlog 1 · innodb_flush_log_at_trx_commit 1')
        ->toContain('Attentes de verrou : 145, 195 637 ms en tout, 1 349 ms par attente ; maximum 10 100 ms, atteint pendant la fenêtre')
        ->toContain('fsync : 219 au journal (1,07 par seconde), 418 aux données (2,04 par seconde)')
        ->toContain('Verdict : convoi de verrous');
});

it('ne signale rien sur les relevés du 03/09 après réglage : zéro attente, synchronisation chaque seconde, sans journal binaire', function (): void {
    mesureDeLaBaseDuTroisSeptembreApresReglage();

    $mesure = mesureDeLaBaseEnJson(['--pendant' => 60]);

    expect($mesure['verdict'])->toBe('rien à signaler')
        ->and($mesure['fenetre_s'])->toBe(69)
        ->and($mesure['attentes_de_verrou'])->toMatchArray(['nombre' => 0, 'ms' => 0, 'ms_par_attente' => 0])
        ->and($mesure['fsync_par_seconde'])->toBe(['journal' => 0.97, 'donnees' => 2.33])
        ->and($mesure['raisons'])->toBe([]);
});

it('dit d’une fenêtre sans aucune écriture qu’elle n’a rien mesuré, même sous des réglages qui synchronisent chaque validation', function (): void {
    mesureDeLaBaseReleves([
        ['variables' => mesureDeLaBaseReglagesDAvant(), 'compteurs' => mesureDeLaBaseCompteurs(['Uptime' => 1_000, 'Innodb_os_log_fsyncs' => 40])],
        ['variables' => mesureDeLaBaseReglagesDAvant(), 'compteurs' => mesureDeLaBaseCompteurs(['Uptime' => 1_200, 'Innodb_os_log_fsyncs' => 40])],
    ]);

    $mesure = mesureDeLaBaseEnJson();

    expect($mesure['verdict'])->toBe('rien à signaler')
        ->and($mesure['raisons'])->toHaveCount(1)
        ->and(data_get($mesure, 'raisons.0'))->toContain('rien n\'a été mesuré')->toContain('saisissant des séries');
});

it('dit le coût de validation sans convoi : une synchronisation par validation, ou une attente du stockage', function (array $reglages, array $avant, array $apres, string $raison): void {
    /**
     * @var array<string, string> $reglages
     * @var array<string, int> $avant
     * @var array<string, int> $apres
     */
    mesureDeLaBaseReleves([
        ['variables' => [...mesureDeLaBaseReglagesDApres(), ...$reglages], 'compteurs' => mesureDeLaBaseCompteurs([...$avant, 'Uptime' => 1_000, 'Innodb_os_log_fsyncs' => 100])],
        ['variables' => [...mesureDeLaBaseReglagesDApres(), ...$reglages], 'compteurs' => mesureDeLaBaseCompteurs([...$apres, 'Uptime' => 1_200, 'Innodb_os_log_fsyncs' => 300])],
    ]);

    $mesure = mesureDeLaBaseEnJson();

    expect($mesure['verdict'])->toBe('coût de validation')
        ->and($mesure['raisons'])->toHaveCount(1)
        ->and(data_get($mesure, 'raisons.0'))->toContain($raison);
})->with([
    'journal synchronisé à chaque validation' => [['innodb_flush_log_at_trx_commit' => '1'], [], [], 'se synchronise 1 fois sur le stockage (innodb_flush_log_at_trx_commit = 1)'],
    'journal binaire synchronisé à chaque validation' => [['log_bin' => 'ON'], [], [], 'se synchronise 1 fois sur le stockage (log_bin ON et sync_binlog = 1)'],
    'tampon du journal qui attend' => [[], ['Innodb_log_waits' => 4], ['Innodb_log_waits' => 7], 'Innodb_log_waits +3'],
    'page libre attendue' => [[], ['Innodb_buffer_pool_wait_free' => 0], ['Innodb_buffer_pool_wait_free' => 2], 'Innodb_buffer_pool_wait_free +2'],
    // Neuf attentes de 1 s, ou cinquante attentes de 100 ms, ne font pas un convoi.
    'peu d’attentes longues' => [['innodb_flush_log_at_trx_commit' => '1'], [], ['Innodb_row_lock_waits' => 9, 'Innodb_row_lock_time' => 9_000], 'innodb_flush_log_at_trx_commit = 1'],
    'beaucoup d’attentes courtes' => [['innodb_flush_log_at_trx_commit' => '1'], [], ['Innodb_row_lock_waits' => 50, 'Innodb_row_lock_time' => 5_000], 'innodb_flush_log_at_trx_commit = 1'],
]);

it('juge le convoi aux deux seuils : dix attentes, deux cents millisecondes par attente', function (int $attentes, int $millisecondes, string $verdict): void {
    mesureDeLaBaseReleves([
        ['variables' => mesureDeLaBaseReglagesDApres(), 'compteurs' => mesureDeLaBaseCompteurs(['Uptime' => 1_000])],
        ['variables' => mesureDeLaBaseReglagesDApres(), 'compteurs' => mesureDeLaBaseCompteurs([
            'Uptime' => 1_200, 'Innodb_row_lock_waits' => $attentes, 'Innodb_row_lock_time' => $millisecondes,
        ])],
    ]);

    expect(mesureDeLaBaseEnJson()['verdict'])->toBe($verdict);
})->with([
    'pile aux seuils' => [10, 2_000, 'convoi de verrous'],
    'une attente de moins' => [9, 1_800, 'rien à signaler'],
    'une milliseconde de moins en tout' => [10, 1_999, 'rien à signaler'],
]);

/*
 * InnoDB compte une attente de verrou quand elle commence
 * (Innodb_row_lock_waits), et n'ajoute sa durée à Innodb_row_lock_time
 * qu'à sa fin. Les attentes que la durée de la fenêtre couvre sont donc celles
 * qui y ont fini : les commencées, plus celles en cours au premier relevé,
 * moins celles en cours au second (Innodb_row_lock_current_waits, une jauge).
 */
it('rapporte la durée des attentes de verrou aux attentes finies dans la fenêtre, pas aux commencées', function (array $avant, array $apres, array $attendu, string $verdict, ?string $raison): void {
    /**
     * @var array<string, int> $avant
     * @var array<string, int> $apres
     * @var array<string, int> $attendu
     */
    mesureDeLaBaseReleves([
        ['variables' => mesureDeLaBaseReglagesDApres(), 'compteurs' => mesureDeLaBaseCompteurs([...$avant, 'Uptime' => 1_000, 'Innodb_os_log_fsyncs' => 100])],
        ['variables' => mesureDeLaBaseReglagesDApres(), 'compteurs' => mesureDeLaBaseCompteurs([...$apres, 'Uptime' => 1_200, 'Innodb_os_log_fsyncs' => 300])],
    ]);

    $mesure = mesureDeLaBaseEnJson();

    expect($mesure['attentes_de_verrou'])->toMatchArray($attendu)
        ->and($mesure['verdict'])->toBe($verdict);

    if ($raison === null) {
        expect($mesure['raisons'])->toBe([]);
    } else {
        expect(array_filter((array) $mesure['raisons'], fn (mixed $texte): bool => is_string($texte) && str_contains($texte, $raison)))->toHaveCount(1);
    }
})->with([
    // La sonde du 11/11 sur MySQL 8.4 : une attente de 8 s, commencée avant
    // le premier relevé, finie pendant la fenêtre.
    'une attente commencée avant la fenêtre, finie pendant' => [
        ['Innodb_row_lock_waits' => 14, 'Innodb_row_lock_time' => 19_749, 'Innodb_row_lock_current_waits' => 1],
        ['Innodb_row_lock_waits' => 14, 'Innodb_row_lock_time' => 27_669, 'Innodb_row_lock_current_waits' => 0],
        ['nombre' => 1, 'ms' => 7_920, 'ms_par_attente' => 7_920, 'en_cours_au_premier_releve' => 1, 'en_cours_au_second_releve' => 0],
        'rien à signaler',
        'Innodb_row_lock_current_waits valait 1 au premier relevé',
    ],
    'une attente encore en cours au second relevé, sans durée' => [
        ['Innodb_row_lock_waits' => 3, 'Innodb_row_lock_time' => 600],
        ['Innodb_row_lock_waits' => 4, 'Innodb_row_lock_time' => 600, 'Innodb_row_lock_current_waits' => 1],
        ['nombre' => 0, 'ms' => 0, 'ms_par_attente' => 0, 'en_cours_au_premier_releve' => 0, 'en_cours_au_second_releve' => 1],
        'rien à signaler',
        'Innodb_row_lock_current_waits valait 1 au second relevé',
    ],
    // Les deux compteurs d'un relevé ne se lisent pas au même instant : des
    // millisecondes comptées prouvent au moins une attente finie.
    'des millisecondes sans attente finie, lues entre deux compteurs' => [
        ['Innodb_row_lock_waits' => 3, 'Innodb_row_lock_time' => 600],
        ['Innodb_row_lock_waits' => 3, 'Innodb_row_lock_time' => 650],
        ['nombre' => 1, 'ms' => 50, 'ms_par_attente' => 50],
        'rien à signaler',
        null,
    ],
    'une attente en cours au second relevé, pas encore comptée commencée' => [
        ['Innodb_row_lock_waits' => 3, 'Innodb_row_lock_time' => 600],
        ['Innodb_row_lock_waits' => 3, 'Innodb_row_lock_time' => 600, 'Innodb_row_lock_current_waits' => 1],
        ['nombre' => 0, 'ms' => 0, 'ms_par_attente' => 0],
        'rien à signaler',
        'Innodb_row_lock_current_waits valait 1 au second relevé',
    ],
    // Au seuil, l'attente en cours fait basculer le verdict, dans un sens ou
    // dans l'autre.
    'dix commencées dont une encore en cours : neuf finies, pas de convoi' => [
        [],
        ['Innodb_row_lock_waits' => 10, 'Innodb_row_lock_time' => 2_000, 'Innodb_row_lock_current_waits' => 1],
        ['nombre' => 9, 'ms' => 2_000, 'ms_par_attente' => 222],
        'rien à signaler',
        'Innodb_row_lock_current_waits valait 1 au second relevé',
    ],
    'neuf commencées et une d’avant la fenêtre : dix finies, un convoi qui le dit' => [
        ['Innodb_row_lock_current_waits' => 1],
        ['Innodb_row_lock_waits' => 9, 'Innodb_row_lock_time' => 2_000],
        ['nombre' => 10, 'ms' => 2_000, 'ms_par_attente' => 200],
        'convoi de verrous',
        'une attente commencée avant la fenêtre et finie pendant compte toute sa durée',
    ],
]);

it('écrit les attentes en cours à l’un des relevés dans les lignes lisibles, et rien quand il n’y en a pas', function (): void {
    mesureDeLaBaseReleves([
        ['variables' => mesureDeLaBaseReglagesDApres(), 'compteurs' => mesureDeLaBaseCompteurs(['Uptime' => 1_000, 'Innodb_row_lock_current_waits' => 1])],
        ['variables' => mesureDeLaBaseReglagesDApres(), 'compteurs' => mesureDeLaBaseCompteurs([
            'Uptime' => 1_200, 'Innodb_row_lock_waits' => 2, 'Innodb_row_lock_time' => 7_920, 'Innodb_row_lock_current_waits' => 2,
        ])],
    ]);

    expect(Artisan::call('base:mesurer'))->toBe(0)
        ->and(Artisan::output())->toContain('Attentes de verrou : 1, 7 920 ms en tout, 7 920 ms par attente ; maximum 0 ms ; en cours : 1 au premier relevé, 2 au second.');

    mesureDeLaBaseDuTroisSeptembreAvantReglage();

    expect(Artisan::call('base:mesurer'))->toBe(0)
        ->and(Artisan::output())->not->toContain('en cours :');
});

it('dit « non rendu » du maximum quand le premier relevé ne l’a pas rendu', function (): void {
    $avant = mesureDeLaBaseCompteurs(['Uptime' => 1_000]);
    unset($avant['Innodb_row_lock_time_max']);

    mesureDeLaBaseReleves([
        ['variables' => mesureDeLaBaseReglagesDApres(), 'compteurs' => $avant],
        ['variables' => mesureDeLaBaseReglagesDApres(), 'compteurs' => mesureDeLaBaseCompteurs(['Uptime' => 1_200, 'Innodb_row_lock_time_max' => 900])],
    ]);

    $mesure = mesureDeLaBaseEnJson();

    expect($mesure['attentes_de_verrou'])->toMatchArray(['maximum_ms' => null, 'maximum_atteint_pendant_la_fenetre' => false])
        ->and($mesure['non_rendus'])->toBe(['Innodb_row_lock_time_max']);
});

it('ne compte pas le maximum d’avant la fenêtre comme une attente de la fenêtre', function (): void {
    mesureDeLaBaseReleves([
        ['variables' => mesureDeLaBaseReglagesDApres(), 'compteurs' => mesureDeLaBaseCompteurs(['Uptime' => 1_000, 'Innodb_row_lock_time_max' => 10_100])],
        ['variables' => mesureDeLaBaseReglagesDApres(), 'compteurs' => mesureDeLaBaseCompteurs(['Uptime' => 1_200, 'Innodb_row_lock_time_max' => 10_100])],
    ]);

    expect(mesureDeLaBaseEnJson()['attentes_de_verrou'])->toMatchArray(['maximum_ms' => 10_100, 'maximum_atteint_pendant_la_fenetre' => false]);

    Artisan::call('base:mesurer');
    expect(Artisan::output())->toContain('maximum 10 100 ms, atteint avant la fenêtre');
});

it('attend --pendant secondes entre les deux relevés, 200 par défaut', function (array $options, int $secondes): void {
    mesureDeLaBaseReleves([
        ['variables' => mesureDeLaBaseReglagesDApres(), 'compteurs' => mesureDeLaBaseCompteurs(['Uptime' => 1_000])],
        ['variables' => mesureDeLaBaseReglagesDApres(), 'compteurs' => mesureDeLaBaseCompteurs(['Uptime' => 1_000 + $secondes])],
    ]);

    expect(Artisan::call('base:mesurer', $options))->toBe(0);

    Sleep::assertSequence([Sleep::for($secondes)->seconds()]);
})->with([
    'par défaut' => [[], 200],
    'une minute' => [['--pendant' => '60'], 60],
    'la borne basse' => [['--pendant' => '10'], 10],
    'la borne haute' => [['--pendant' => '3600'], 3_600],
]);

it('refuse un --pendant hors bornes ou qui n’est pas un nombre entier de secondes, sans rien lire ni attendre', function (string $pendant): void {
    app()->instance(LecteurDesReglagesDeLaBase::class, new class() extends LecteurDesReglagesDeLaBase
    {
        #[\Override]
        public function lireLesVariables(array $noms): array
        {
            throw new LogicException('La commande a lu la base malgré un --pendant refusé.');
        }

        #[\Override]
        public function lireLesCompteurs(array $noms): array
        {
            throw new LogicException('La commande a lu la base malgré un --pendant refusé.');
        }
    });

    expect(Artisan::call('base:mesurer', ['--pendant' => $pendant]))->toBe(2)
        ->and(Artisan::output())->toBe("« pendant » attend un nombre entier de secondes, de 10 à 3 600.\n");

    Sleep::assertNeverSlept();
})->with([
    'sous la borne' => '9',
    'zéro' => '0',
    'négatif' => '-200',
    'au-dessus de la borne' => '3601',
    'un mot' => 'longtemps',
    'une unité' => '200s',
    'une décimale' => '200.5',
    'vide' => '',
    'un saut de ligne en queue' => "200\n",
]);

it('rend avec --json une seule ligne de JSON, et rien d’autre', function (): void {
    mesureDeLaBaseDuTroisSeptembreAvantReglage();

    expect(Artisan::call('base:mesurer', ['--json' => true]))->toBe(0);

    $sortie = Artisan::output();

    expect(substr_count($sortie, "\n"))->toBe(1)
        ->and($sortie)->toEndWith("\n")
        ->and(json_decode($sortie, true))->toBeArray()->toHaveKey('verdict', 'convoi de verrous');
});

/*
 * Un redémarrage remet les compteurs à zéro : l'écart serait négatif, ou
 * compterait ce qui s'est passé avant. La commande refuse de conclure.
 */
it('refuse de conclure quand la base a redémarré pendant la fenêtre, sans jamais rendre un écart négatif', function (int $uptimeAvant, int $uptimeApres): void {
    mesureDeLaBaseReleves([
        ['variables' => mesureDeLaBaseReglagesDAvant(), 'compteurs' => mesureDeLaBaseCompteurs(['Uptime' => $uptimeAvant, 'Innodb_row_lock_waits' => 1_200])],
        ['variables' => mesureDeLaBaseReglagesDAvant(), 'compteurs' => mesureDeLaBaseCompteurs(['Uptime' => $uptimeApres, 'Innodb_row_lock_waits' => 3])],
    ]);

    foreach ([['--json' => true], []] as $options) {
        expect(Artisan::call('base:mesurer', $options))->toBe(1);
        $sortie = Artisan::output();

        expect($sortie)->toContain('La base a redémarré pendant la fenêtre')->toContain('relancer la mesure');
        expect($sortie)->not->toContain('-1197');
        expect($sortie)->not->toContain('verdict');
    }
})->with([
    'Uptime qui baisse' => [86_400, 150],
    // Démarrée 50 s avant le premier relevé, redémarrée pendant l'attente :
    // Uptime monte, mais de moins que l'attente.
    'Uptime qui monte de moins que l’attente' => [50, 150],
    // Uptime compte en secondes entières et en perd une au plus : pour 200 s
    // d'attente, 198 s ne suffisent plus.
    'Uptime qui monte de l’attente moins deux secondes' => [1_000, 1_198],
]);

it('accepte une fenêtre d’une seconde de moins que l’attente, la seconde qu’Uptime peut perdre', function (): void {
    mesureDeLaBaseReleves([
        ['variables' => mesureDeLaBaseReglagesDApres(), 'compteurs' => mesureDeLaBaseCompteurs(['Uptime' => 1_000])],
        ['variables' => mesureDeLaBaseReglagesDApres(), 'compteurs' => mesureDeLaBaseCompteurs(['Uptime' => 1_199])],
    ]);

    expect(mesureDeLaBaseEnJson()['fenetre_s'])->toBe(199);
});

it('refuse de conclure quand un compteur recule sans redémarrage', function (): void {
    mesureDeLaBaseReleves([
        ['variables' => mesureDeLaBaseReglagesDAvant(), 'compteurs' => mesureDeLaBaseCompteurs(['Uptime' => 1_000, 'Innodb_data_fsyncs' => 500])],
        ['variables' => mesureDeLaBaseReglagesDAvant(), 'compteurs' => mesureDeLaBaseCompteurs(['Uptime' => 1_200, 'Innodb_data_fsyncs' => 400])],
    ]);

    expect(Artisan::call('base:mesurer', ['--json' => true]))->toBe(1)
        ->and(Artisan::output())->toBe("Innodb_data_fsyncs a reculé pendant la fenêtre (500 puis 400) : l'écart ne veut rien dire, relancer la mesure.\n");
});

it('dit « non rendu » d’un nom que la base n’a pas rendu, au lieu de planter ou de le croire nul', function (): void {
    $variables = mesureDeLaBaseReglagesDApres();
    unset($variables['innodb_flush_method']);
    $avant = mesureDeLaBaseCompteurs(['Uptime' => 1_000]);
    $apres = mesureDeLaBaseCompteurs(['Uptime' => 1_200]);
    unset($avant['Innodb_log_waits'], $apres['Innodb_log_waits'], $apres['Innodb_row_lock_time']);

    mesureDeLaBaseReleves([['variables' => $variables, 'compteurs' => $avant], ['variables' => $variables, 'compteurs' => $apres]]);

    $mesure = mesureDeLaBaseEnJson();

    expect($mesure['non_rendus'])->toBe(['innodb_flush_method', 'Innodb_row_lock_time', 'Innodb_log_waits'])
        ->and(data_get($mesure, 'reglages.innodb_flush_method'))->toBe('non rendu')
        ->and(data_get($mesure, 'ecarts.Innodb_log_waits'))->toBeNull()
        ->and($mesure['attentes_de_verrou'])->toMatchArray(['nombre' => 0, 'ms' => null, 'ms_par_attente' => null])
        ->and($mesure['verdict'])->toBe('rien à signaler');

    expect(Artisan::call('base:mesurer'))->toBe(0)
        ->and(Artisan::output())
        ->toContain('innodb_flush_method non rendu')
        ->toContain('Attentes de verrou : 0, non rendu en tout')
        ->toContain('Attentes du journal (Innodb_log_waits) : non rendu')
        ->toContain('Non rendus : innodb_flush_method, Innodb_row_lock_time, Innodb_log_waits');
});

it('refuse de conclure quand la base ne rend pas Uptime, sans lequel la fenêtre ne se date pas', function (): void {
    $compteurs = mesureDeLaBaseCompteurs([]);
    unset($compteurs['Uptime']);

    mesureDeLaBaseReleves([
        ['variables' => mesureDeLaBaseReglagesDApres(), 'compteurs' => $compteurs],
        ['variables' => mesureDeLaBaseReglagesDApres(), 'compteurs' => $compteurs],
    ]);

    expect(Artisan::call('base:mesurer', ['--json' => true]))->toBe(1)
        ->and(Artisan::output())->toBe("La base n'a pas rendu Uptime : la fenêtre ne se date pas, et un redémarrage ne se verrait pas.\n");
});

/*
 * Sur la vraie base de la CI (MySQL 8.4 et 9.7, la matrice existante) :
 * chaque nom lu par la commande est rendu, et elle n'envoie que des SHOW.
 */
it('trouve chaque réglage et chaque compteur dans la base qui tourne', function (): void {
    $lecteur = app(LecteurDesReglagesDeLaBase::class);

    $variables = $lecteur->lireLesVariables(MesureDeLaBase::VARIABLES);
    $compteurs = $lecteur->lireLesCompteurs(MesureDeLaBase::COMPTEURS);

    expect(array_keys($variables))->toEqualCanonicalizing(MesureDeLaBase::VARIABLES)
        ->and(array_keys($compteurs))->toEqualCanonicalizing(MesureDeLaBase::COMPTEURS)
        ->and(array_filter($compteurs, fn (string $valeur): bool => ! ctype_digit($valeur)))->toBe([]);
});

it('ne fait que lire : la commande, sur la vraie base, n’envoie que quatre SHOW GLOBAL', function (): void {
    // Sleep::fake() ne fait pas vieillir le serveur : le second relevé
    // avance Uptime de l'attente, comme une vraie attente l'aurait fait.
    app()->instance(LecteurDesReglagesDeLaBase::class, new class() extends LecteurDesReglagesDeLaBase
    {
        private int $releves = 0;

        #[\Override]
        public function lireLesCompteurs(array $noms): array
        {
            $compteurs = parent::lireLesCompteurs($noms);

            if ($this->releves++ === 1) {
                $compteurs['Uptime'] = (string) ((int) $compteurs['Uptime'] + 200);
            }

            return $compteurs;
        }
    });

    $requetes = [];
    DB::listen(function (QueryExecuted $requete) use (&$requetes): void {
        $requetes[] = strtolower(ltrim($requete->sql));
    });

    $code = Artisan::call('base:mesurer', ['--json' => true]);

    expect($code)->toBe(0, Artisan::output());

    expect($requetes)->toHaveCount(4)
        ->and(array_values(array_filter($requetes, fn (string $sql): bool => ! str_starts_with($sql, 'show global variables where') && ! str_starts_with($sql, 'show global status where'))))->toBe([]);
});
