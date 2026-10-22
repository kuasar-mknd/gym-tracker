<?php

declare(strict_types=1);

use App\Actions\Workouts\CreateSetAction;
use App\Models\User;
use App\Models\WorkoutLine;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * La migration qui passe en `ascii` / `ascii_bin` les clés d'idempotence des
 * séries et des lignes (#2097,
 * `database/migrations/2026_10_22_073017_passer_en_ascii_les_cles_d_idempotence_des_series_et_des_lignes.php`).
 * Ce que le schéma doit devenir, `ChaqueCleDIdempotenceDistingueLaCasseTest`
 * le tient ; ce fichier tient le passage : une base d'avant, des clés que
 * `CreateSetAction::idempotencyKey()` refuserait (hors ASCII, espace,
 * caractère de contrôle, vide), sous un parent ou orphelines, un second
 * passage, le retour en arrière, et chacun des deux repris après un arrêt
 * entre les séries et les lignes.
 *
 * Ces cas passent par des ordres DDL, qui valident la transaction de
 * `RefreshDatabase` : ce fichier fait lui-même ce que le `migrate:fresh` qui
 * suivrait referait (`.ai/rules/tests.md`).
 */

/**
 * La migration, chargée depuis son fichier.
 */
function clesAsciiMigration(): Migration
{
    $migration = require database_path('migrations/2026_10_22_073017_passer_en_ascii_les_cles_d_idempotence_des_series_et_des_lignes.php');
    assert($migration instanceof Migration);

    return $migration;
}

/**
 * Joue `up()` de la migration, comme `migrate`.
 */
function clesAsciiMonter(): void
{
    $migration = clesAsciiMigration();
    assert(method_exists($migration, 'up'));

    $migration->up();
}

/**
 * Joue `down()` de la migration, comme `migrate:rollback` : la base d'avant.
 */
function clesAsciiDescendre(): void
{
    $migration = clesAsciiMigration();
    assert(method_exists($migration, 'down'));

    $migration->down();
}

/**
 * Ce que la migration change, et ce qu'elle doit garder : la forme de chaque
 * colonne (type, collation, nullable) et l'ordre de chaque index unique.
 *
 * @return array<string, string|bool>
 */
function clesAsciiEtat(): array
{
    $etat = [];

    foreach (['sets' => 'workout_line_id', 'workout_lines' => 'workout_id'] as $table => $parent) {
        $colonne = collect(Schema::getColumns($table))->firstWhere('name', 'idempotency_key');
        assert(is_array($colonne));

        $etat["{$table}.idempotency_key"] = sprintf('%s %s %s', $colonne['type'], $colonne['collation'], $colonne['nullable'] === true ? 'nullable' : 'obligatoire');
        $etat["{$table} unique (idempotency_key, {$parent})"] = Schema::hasIndex($table, ['idempotency_key', $parent], 'unique');
        $etat["{$table} unique ({$parent}, idempotency_key)"] = Schema::hasIndex($table, [$parent, 'idempotency_key'], 'unique');
    }

    return $etat;
}

/**
 * L'état attendu : celui d'avant la migration, ou celui d'après. Seule la
 * collation change ; la clé reste en tête de son index unique (#2095).
 *
 * @return array<string, string|bool>
 */
function clesAsciiEtatAttendu(bool $apres): array
{
    $colonne = $apres ? 'varchar(64) ascii_bin nullable' : 'varchar(64) utf8mb4_unicode_ci nullable';

    return [
        'sets.idempotency_key' => $colonne,
        'sets unique (idempotency_key, workout_line_id)' => true,
        'sets unique (workout_line_id, idempotency_key)' => false,
        'workout_lines.idempotency_key' => $colonne,
        'workout_lines unique (idempotency_key, workout_id)' => true,
        'workout_lines unique (workout_id, idempotency_key)' => false,
    ];
}

/**
 * Les ordres qui modifient une table ou ses lignes, lancés pendant cette
 * étape.
 *
 * @return list<string>
 */
function clesAsciiOrdresDe(Closure $etape): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    try {
        $etape();
    } finally {
        DB::disableQueryLog();
    }

    return array_values(array_filter(
        array_map(static fn (array $requete): string => (string) $requete['query'], DB::getQueryLog()),
        static fn (string $requete): bool => preg_match('/^\s*(alter|update)\b/i', $requete) === 1,
    ));
}

/**
 * La table que vise chacun de ces ordres, dans l'ordre où ils partent.
 *
 * @param  list<string>  $ordres
 * @return list<string>
 */
function clesAsciiTablesDe(array $ordres): array
{
    return array_map(
        static fn (string $ordre): string => preg_match('/^\s*(?:alter\s+table|update)\s+`([^`]+)`/i', $ordre, $morceaux) === 1 ? $morceaux[1] : $ordre,
        $ordres,
    );
}

/**
 * Passe en `ascii` / `ascii_bin` la clé d'une seule table, comme la migration
 * la laisse quand l'ordre de l'autre table ne va pas au bout.
 */
function clesAsciiPasserSeule(string $table): void
{
    Schema::withoutForeignKeyConstraints(static function () use ($table): void {
        Schema::table($table, static function (Blueprint $blueprint): void {
            $blueprint->string('idempotency_key', 64)->charset('ascii')->collation('ascii_bin')->nullable()->change();
        });
    });
}

/**
 * Une séance et sa ligne, écrites sans passer par les modèles : le parent
 * d'une série, et la séance, celui d'une ligne.
 *
 * @return array{seance: int, ligne: int, exercice: int}
 */
function clesAsciiUneLigne(): array
{
    $compte = DB::table('users')->insertGetId(['name' => 'Témoin', 'email' => 'temoin-'.bin2hex(random_bytes(4)).'@example.org']);
    $exercice = DB::table('exercises')->insertGetId(['name' => 'Développé', 'user_id' => $compte]);
    $seance = DB::table('workouts')->insertGetId(['user_id' => $compte, 'started_at' => '2026-10-21 18:00:00']);

    return [
        'seance' => $seance,
        'ligne' => DB::table('workout_lines')->insertGetId(['workout_id' => $seance, 'exercise_id' => $exercice]),
        'exercice' => $exercice,
    ];
}

/**
 * Un identifiant que la table n'a pas, pour une ligne qui viserait un parent
 * absent.
 */
function clesAsciiUnAbsent(string $table): int
{
    $plusGrand = DB::table($table)->max('id');

    return (is_numeric($plusGrand) ? (int) $plusGrand : 0) + 1_000;
}

afterEach(function (): void {
    if (DB::getPdo()->inTransaction()) {
        return;
    }

    Schema::withoutForeignKeyConstraints(function (): void {
        foreach (Schema::getTableListing(DB::connection()->getDatabaseName(), false) as $table) {
            if ($table !== 'migrations') {
                DB::table($table)->delete();
            }
        }
    });

    clesAsciiMonter();

    DB::getPdo()->beginTransaction();
});

it('rend à down() la collation de la table, la clé toujours en tête de son index unique', function (): void {
    clesAsciiDescendre();

    expect(clesAsciiEtat())->toBe(clesAsciiEtatAttendu(apres: false));
});

it('passe en ascii_bin les clés d’une base d’avant, sans toucher à leur index', function (): void {
    clesAsciiDescendre();
    clesAsciiMonter();

    expect(clesAsciiEtat())->toBe(clesAsciiEtatAttendu(apres: true));
});

/*
 * Chaque clé passe ce que `CreateSetAction::idempotencyKey()` en garde : une
 * clé que la recherche ne trouverait jamais (aucun en-tête ne la porte
 * jusqu'à elle) s'efface, sa ligne reste. Sans cela, une clé hors ASCII
 * arrêterait la migration au milieu (1366), et une clé à espace finale, que
 * `ascii_bin` compare comme `utf8mb4_unicode_ci` (PAD SPACE), rendrait encore
 * sa série à la clé sans l'espace. Chaque clé a son parent : sous
 * `utf8mb4_unicode_ci`, l'index unique tient « tentative » et « tentative »
 * suivi d'une espace pour une seule.
 */
it('efface les clés que la recherche ne trouverait jamais, et garde les autres telles quelles', function (): void {
    clesAsciiDescendre();

    $cles = [
        'minuscules' => 'tentative',
        'capitales' => 'TENTATIVE',
        'UUID en capitales' => '5F1E7A2C-2097-4D3B-8C1A-00000000000A',
        'clé dérivée d’un modèle' => 'modele-serie:3:1',
        'soixante-quatre caractères' => str_repeat('z', 64),
        'ponctuation de l’ASCII imprimable' => '!"#$%&\'()*+,-./:;<=>?@[\]^_`{|}~',
        'espace finale' => 'tentative ',
        'espace en tête' => ' tentative',
        'espace au milieu' => 'une tentative',
        'vide' => '',
        'é' => 'clé',
        'signe kelvin, une capitale sans casse ASCII' => "\u{212A}",
        's long' => "\u{017F}",
        'pleine chasse' => 'ＴＥＮＴＡＴＩＶＥ',
        'emoji' => "\u{1F4AA}",
        'tabulation' => "ten\ttative",
        'saut de ligne final' => "tentative\n",
        'DEL' => "tentative\x7F",
    ];
    $series = [];
    $lignes = [];

    foreach ($cles as $nom => $cle) {
        $parent = clesAsciiUneLigne();
        $series[$nom] = DB::table('sets')->insertGetId(['workout_line_id' => $parent['ligne'], 'idempotency_key' => $cle]);
        $lignes[$nom] = DB::table('workout_lines')->insertGetId(['workout_id' => $parent['seance'], 'exercise_id' => $parent['exercice'], 'idempotency_key' => $cle]);
    }

    $avant = ['sets' => DB::table('sets')->count(), 'workout_lines' => DB::table('workout_lines')->count()];

    clesAsciiMonter();

    $attendues = array_map(static fn (string $cle): ?string => CreateSetAction::idempotencyKey(['idempotency_key' => $cle]), $cles);

    expect(array_map(static fn (int $id): mixed => DB::table('sets')->where('id', $id)->value('idempotency_key'), $series))->toBe($attendues)
        ->and(array_map(static fn (int $id): mixed => DB::table('workout_lines')->where('id', $id)->value('idempotency_key'), $lignes))->toBe($attendues)
        ->and(array_filter($attendues, static fn (?string $cle): bool => $cle !== null))->toHaveCount(6)
        ->and(['sets' => DB::table('sets')->count(), 'workout_lines' => DB::table('workout_lines')->count()])->toBe($avant)
        ->and(clesAsciiEtat())->toBe(clesAsciiEtatAttendu(apres: true));
});

it('ne rend plus à une clé la série d’une clé d’avant qui n’en diffère que par une espace finale', function (): void {
    clesAsciiDescendre();

    $parent = clesAsciiUneLigne();
    $ancienne = DB::table('sets')->insertGetId(['workout_line_id' => $parent['ligne'], 'idempotency_key' => 'tentative ']);

    clesAsciiMonter();

    $ligne = WorkoutLine::query()->findOrFail($parent['ligne']);
    $serie = app(CreateSetAction::class)->execute(User::query()->firstOrFail(), $ligne, ['weight' => 80, 'reps' => 8, 'idempotency_key' => 'tentative']);

    expect($serie->id)->not->toBe($ancienne)
        ->and($serie->idempotency_key)->toBe('tentative')
        ->and($ligne->sets()->count())->toBe(2);
});

it('va au bout quand une série ou une ligne n’a plus de parent', function (): void {
    clesAsciiDescendre();

    // Des lignes sans parent, écrites clés coupées : une archive restaurée,
    // ou une écriture faite hors d'Eloquent.
    $ligneAbsente = clesAsciiUnAbsent('workout_lines');
    $seanceAbsente = clesAsciiUnAbsent('workouts');
    $exercice = clesAsciiUneLigne()['exercice'];

    Schema::withoutForeignKeyConstraints(static function () use ($ligneAbsente, $seanceAbsente, $exercice): void {
        DB::table('sets')->insert(['workout_line_id' => $ligneAbsente, 'idempotency_key' => 'clé']);
        DB::table('workout_lines')->insert(['workout_id' => $seanceAbsente, 'exercise_id' => $exercice, 'idempotency_key' => 'tentative ']);
    });

    clesAsciiMonter();

    expect(clesAsciiEtat())->toBe(clesAsciiEtatAttendu(apres: true))
        ->and(DB::table('sets')->where('workout_line_id', $ligneAbsente)->sole()->idempotency_key)->toBeNull()
        ->and(DB::table('workout_lines')->where('workout_id', $seanceAbsente)->sole()->idempotency_key)->toBeNull();
});

it('ne change rien à une base déjà passée', function (): void {
    expect(clesAsciiOrdresDe(clesAsciiMonter(...)))->toBe([]);
});

/*
 * Chaque table passe par son propre ALTER, validé seul, et celui de la seconde
 * peut ne pas aller au bout (un processus tué pendant le déploiement, une
 * attente de verrou, un disque plein pendant la copie) : la migration n'est
 * pas notée comme jouée, et le `migrate` suivant la rejoue sur une base à
 * moitié passée. Chaque table se juge sur sa propre collation : les séries,
 * déjà passées, ne reçoivent aucun ordre, et les lignes passent entières,
 * leurs clés hors du motif effacées.
 */
it('reprend un passage interrompu après les séries', function (): void {
    clesAsciiDescendre();

    $parent = clesAsciiUneLigne();
    $serie = DB::table('sets')->insertGetId(['workout_line_id' => $parent['ligne'], 'idempotency_key' => 'tentative']);
    clesAsciiPasserSeule('sets');
    $refusee = DB::table('workout_lines')->insertGetId(['workout_id' => $parent['seance'], 'exercise_id' => $parent['exercice'], 'idempotency_key' => 'clé']);
    $gardee = DB::table('workout_lines')->insertGetId(['workout_id' => $parent['seance'], 'exercise_id' => $parent['exercice'], 'idempotency_key' => 'Autre']);

    expect(clesAsciiEtat()['sets.idempotency_key'])->toBe('varchar(64) ascii_bin nullable')
        ->and(clesAsciiEtat()['workout_lines.idempotency_key'])->toBe('varchar(64) utf8mb4_unicode_ci nullable')
        ->and(clesAsciiTablesDe(clesAsciiOrdresDe(clesAsciiMonter(...))))->toBe(['workout_lines', 'workout_lines'])
        ->and(clesAsciiEtat())->toBe(clesAsciiEtatAttendu(apres: true))
        ->and(DB::table('workout_lines')->where('id', $refusee)->value('idempotency_key'))->toBeNull()
        ->and(DB::table('workout_lines')->where('id', $gardee)->value('idempotency_key'))->toBe('Autre')
        ->and(DB::table('sets')->where('id', $serie)->value('idempotency_key'))->toBe('tentative');
});

/*
 * Le retour en arrière ne choisit pas laquelle oublier de deux clés qu'une
 * collation insensible à la casse confondrait : l'index unique de l'ancienne
 * collation les refuse côte à côte, et `down()` s'arrête sur ce refus, la
 * table comme elle était.
 */
it('arrête down() sur deux clés d’un même parent que seule la casse distingue', function (): void {
    $parent = clesAsciiUneLigne();
    DB::table('sets')->insert(['workout_line_id' => $parent['ligne'], 'idempotency_key' => 'tentative']);
    DB::table('sets')->insert(['workout_line_id' => $parent['ligne'], 'idempotency_key' => 'TENTATIVE']);

    expect(clesAsciiDescendre(...))->toThrow(UniqueConstraintViolationException::class)
        ->and(clesAsciiEtat()['sets.idempotency_key'])->toBe('varchar(64) ascii_bin nullable')
        ->and(DB::table('sets')->where('workout_line_id', $parent['ligne'])->orderBy('id')->pluck('idempotency_key')->all())->toBe(['tentative', 'TENTATIVE']);
});

/*
 * Le retour en arrière s'arrête de même entre les deux tables : sur deux clés
 * de ligne que seule la casse distingue, `down()` a déjà rendu sa collation
 * aux séries quand l'ordre des lignes tombe (1062), et la migration reste
 * notée comme jouée. L'une des deux clés retirée, le `migrate:rollback`
 * suivant ne repasse que les lignes.
 */
it('reprend un retour en arrière arrêté sur les lignes', function (): void {
    $parent = clesAsciiUneLigne();
    DB::table('workout_lines')->insert(['workout_id' => $parent['seance'], 'exercise_id' => $parent['exercice'], 'idempotency_key' => 'tentative']);
    $doublon = DB::table('workout_lines')->insertGetId(['workout_id' => $parent['seance'], 'exercise_id' => $parent['exercice'], 'idempotency_key' => 'TENTATIVE']);

    expect(clesAsciiDescendre(...))->toThrow(UniqueConstraintViolationException::class)
        ->and(clesAsciiEtat()['sets.idempotency_key'])->toBe('varchar(64) utf8mb4_unicode_ci nullable')
        ->and(clesAsciiEtat()['workout_lines.idempotency_key'])->toBe('varchar(64) ascii_bin nullable');

    DB::table('workout_lines')->where('id', $doublon)->delete();

    expect(clesAsciiTablesDe(clesAsciiOrdresDe(clesAsciiDescendre(...))))->toBe(['workout_lines'])
        ->and(clesAsciiEtat())->toBe(clesAsciiEtatAttendu(apres: false));
});
