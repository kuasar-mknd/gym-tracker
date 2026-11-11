<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Goal;
use App\Models\NotificationPreference;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\GoalService;
use App\Support\VersionDeCache;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

/*
 * Le controle de coherence doit trouver ce qu'aucun test ne cherche.
 *
 * Chaque ecart est plante EN BASE, sans passer par Eloquent : c'est justement la
 * facon dont les vrais ecarts apparaissent — une cascade, une suppression en
 * masse, un job rejoue. Passer par les modeles declencherait les observateurs,
 * qui repareraient l'incoherence avant que le controle puisse la voir.
 */

/**
 * Un compte avec une seance faite, une serie, et les records qui vont avec.
 *
 * La séance est terminée : la série de jours ne compte que les séances faites
 * (#2171), et une séance en cours laisserait la série et la date de dernière
 * séance à rien.
 *
 * @return array{0: User, 1: Set}
 */
function compteCoherent(): array
{
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $workout = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subDay(), 'ended_at' => now()->subDay()->addHour()]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);

    $set = Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 10,
        'is_warmup' => false,
    ]);

    return [$user->refresh(), $set];
}

/**
 * Un record d'un type que l'application n'écrit pas, planté en base à côté des
 * siens et posé sur la même série saine : aucun autre contrôle ne le voit.
 */
function recordDeTypeHeritePlanteEnBase(User $user, Set $set, string $type): int
{
    return DB::table('personal_records')->insertGetId([
        'user_id' => $user->id,
        'exercise_id' => $set->workoutLine->exercise_id,
        'workout_id' => $set->workoutLine->workout_id,
        'set_id' => $set->id,
        'type' => $type,
        'value' => 100,
        'achieved_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('ne signale rien quand tout concorde', function (): void {
    compteCoherent();

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(0)
        ->expectsOutputToContain('Aucun écart');
});

it('ne tient plus de total par utilisateur : le volume soulevé se lit dans les séances', function (): void {
    [$user] = compteCoherent();

    expect(Schema::hasColumn('users', 'total_volume'))->toBeFalse()
        ->and($user->volumeSouleve())->toBe((float) DB::table('workouts')->where('user_id', $user->id)->sum('workout_volume'));

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(0)
        ->doesntExpectOutputToContain('utilisateurs');
});

it('signale un volume de séance qui a dérivé', function (): void {
    [, $set] = compteCoherent();

    $idSeance = $set->workoutLine->workout_id;

    DB::table('workouts')->where('id', $idSeance)->update(['workout_volume' => 42]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain("séance {$idSeance}");
});

/**
 * L'ecart de #1476, celui que le controle existe pour voir.
 */
it('signale un record qui ne pointe sur aucune série', function (): void {
    [$user, $set] = compteCoherent();

    expect(PersonalRecord::query()->where('user_id', $user->id)->count())->toBeGreaterThan(0);

    // `ON DELETE SET NULL` : la ligne survit a la serie, simplement detachee.
    DB::table('sets')->where('id', $set->id)->delete();

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain('ne pointe sur aucune série');
});

it('signale un record dont la valeur ne correspond pas à sa série', function (): void {
    [$user] = compteCoherent();

    $record = PersonalRecord::query()
        ->where('user_id', $user->id)
        ->where('type', 'max_weight')
        ->firstOrFail();

    DB::table('personal_records')->where('id', $record->id)->update(['value' => 777]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain("record {$record->id}");
});

/**
 * L'enum garde quatre types que plus rien n'écrit (#1811), faute de savoir si
 * la production en porte. Retirer un cas tant qu'une ligne le porte ferait
 * lever une ValueError à chaque relecture par le modèle : le tableau de bord du
 * compte, la reconstruction des records de l'exercice. Le contrôle nocturne
 * doit donc le dire avant, en nommant le type et le nombre de lignes.
 */
it('signale les records d’un type que l’application ne tient plus, avec leur type et leur nombre', function (): void {
    [$user, $set] = compteCoherent();

    recordDeTypeHeritePlanteEnBase($user, $set, 'strength');
    recordDeTypeHeritePlanteEnBase($user, $set, '1RM');

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain('2 type des records')
        ->expectsOutputToContain("type '1RM', que l'application ne tient plus : 1 record(s) sur 1 compte(s)")
        ->expectsOutputToContain("type 'strength', que l'application ne tient plus : 1 record(s) sur 1 compte(s)")
        // Posés sur une série saine, ils ne doivent rien faire signaler d'autre.
        ->expectsOutputToContain('2 écart(s)');

    // Sans `--repair`, le contrôle ne fait que lire : les deux lignes restent.
    expect(DB::table('personal_records')->where('user_id', $user->id)->whereIn(DB::raw('CAST(type AS BINARY)'), ['strength', '1RM'])->count())->toBe(2);
});

/**
 * `--limit` borne les exemples cités. Lus ligne à ligne et triés par type, six
 * records '1RM' remplissaient les cinq places, et le seul 'cardio' disparaissait
 * dans « … et 2 autre(s) » : or 'cardio', le seul type hérité sans équivalent
 * parmi les types suivis, est celui qui décide entre archiver et convertir. Le
 * contrôle décrit donc chaque TYPE, avec son nombre de records et de comptes.
 */
it('nomme chaque type hérité même quand un autre type dépasse la limite d’exemples', function (): void {
    [$user, $set] = compteCoherent();
    recordDeTypeHeritePlanteEnBase($user, $set, 'cardio');
    recordDeTypeHeritePlanteEnBase($user, $set, '1RM');

    for ($i = 0; $i < 5; $i++) {
        [$autreCompte, $autreSerie] = compteCoherent();
        recordDeTypeHeritePlanteEnBase($autreCompte, $autreSerie, '1RM');
    }

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain("type 'cardio', que l'application ne tient plus : 1 record(s) sur 1 compte(s)")
        ->expectsOutputToContain("type '1RM', que l'application ne tient plus : 6 record(s) sur 6 compte(s)")
        ->expectsOutputToContain('2 type des records')
        ->doesntExpectOutputToContain('non cité');
});

/**
 * Le contrôle doit tenir APRÈS le retrait des cas hérités : il lit la colonne
 * brute, sans le cast qui lèverait sur une valeur que l'enum ne connaît pas.
 */
it('signale aussi un type que l’enum ne connaît pas, sans passer par le cast', function (): void {
    [$user, $set] = compteCoherent();

    recordDeTypeHeritePlanteEnBase($user, $set, 'inconnu');

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain("type 'inconnu', que l'application ne tient plus : 1 record(s) sur 1 compte(s)");
});

/**
 * La colonne est en utf8mb4_unicode_ci : insensible à la casse, et PAD SPACE,
 * qui ignore les espaces finales. Pour MySQL, 'MAX_WEIGHT' et 'max_weight '
 * valent 'max_weight' ; pour `PersonalRecordType::from()`, ce sont des valeurs
 * inconnues, et le modèle ne peut pas relire la ligne. Le contrôle doit voir
 * exactement les lignes que le cast refuse, donc comparer octet par octet.
 */
it('signale un type que la collation de la colonne confond avec un type suivi', function (string $typeAltere): void {
    [$user] = compteCoherent();

    $idRecord = PersonalRecord::query()
        ->where('user_id', $user->id)
        ->where('type', 'max_weight')
        ->firstOrFail()
        ->id;

    DB::table('personal_records')->where('id', $idRecord)->update(['type' => $typeAltere]);

    // La ligne est bien de celles que le modèle ne relit pas.
    expect(fn (): mixed => PersonalRecord::query()->findOrFail($idRecord)->type)
        ->toThrow(ValueError::class);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain("type '{$typeAltere}', que l'application ne tient plus : 1 record(s) sur 1 compte(s)");
})->with([
    'en majuscules' => ['MAX_WEIGHT'],
    'avec une espace finale' => ['max_weight '],
]);

it('laisse passer les records des trois types suivis', function (): void {
    [$user] = compteCoherent();

    expect(DB::table('personal_records')->where('user_id', $user->id)->pluck('type')->all())
        ->toEqualCanonicalizing(['max_weight', 'max_1rm', 'max_volume_set']);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(0)
        ->expectsOutputToContain('OK type des records');
});

it('signale une date de dernière séance en retard', function (): void {
    [$user] = compteCoherent();

    DB::table('users')->where('id', $user->id)->update(['last_workout_at' => null]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain("utilisateur {$user->id}");
});

/**
 * Le panneau laissait donner une séance à un autre compte sans toucher à ses
 * lignes ni à ses séries (#1933). Il ne le permet plus, mais une séance
 * réattribuée avant reste incohérente, et les lectures par compte filtrent sur
 * les copies. Le contrôle compte les SÉANCES, et dit combien de lignes et de
 * séries chacune laisse à un autre compte.
 */
it('signale une séance réattribuée dont les lignes et les séries sont restées à l’ancien compte', function (): void {
    [$ancien, $set] = compteCoherent();
    $idSeance = $set->workoutLine->workout_id;

    $secondeLigne = WorkoutLine::factory()->create([
        'workout_id' => $idSeance,
        'exercise_id' => $set->workoutLine->exercise_id,
    ]);
    Set::factory()->create(['workout_line_id' => $secondeLigne->id, 'weight' => 50, 'reps' => 5]);

    $nouveau = User::factory()->create();
    DB::table('workouts')->where('id', $idSeance)->update(['user_id' => $nouveau->id]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain('1 propriétaire recopié sur les lignes de séance')
        ->expectsOutputToContain("séance {$idSeance} (compte {$nouveau->id}) : 2 ligne(s) dont la copie vaut {$ancien->id}")
        ->expectsOutputToContain('1 propriétaire recopié sur les séries')
        ->expectsOutputToContain("séance {$idSeance} (compte {$nouveau->id}) : 2 série(s) dont la copie vaut {$ancien->id}");
});

/**
 * Une copie vide échappe à toute lecture filtrée par compte. `<>` la laisserait
 * passer, puisque NULL n'est différent de rien.
 */
it('signale une copie du propriétaire restée vide', function (): void {
    [$user, $set] = compteCoherent();
    $idSeance = $set->workoutLine->workout_id;

    DB::table('sets')->where('id', $set->id)->update(['user_id' => null]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain('OK propriétaire recopié sur les lignes de séance')
        ->expectsOutputToContain("séance {$idSeance} (compte {$user->id}) : 1 série(s) dont la copie vaut NULL")
        ->expectsOutputToContain('1 écart(s)');
});

/*
 * La date recopiée sur les lignes (#1513). `workout_lines.workout_started_at`
 * recopie le début de la séance, pour qu'un index serve à la fois le filtre et
 * l'ordre des lectures par compte (#1601) : les statistiques fenêtrées et les
 * valeurs proposées. Deux écrivains la tiennent, la copie
 * posée à l'enregistrement de la ligne et la propagation quand la séance
 * change de date ; aucun contrôle ne la comparait au début de sa séance, et
 * une ligne écrite en base sans elle, ou restée à l'ancienne date, sortait de
 * ces lectures sans que rien ne le dise.
 */

/**
 * Le début d'une séance tel que la base le tient, la valeur que la copie doit
 * porter.
 */
function coherenceDebutDeLaSeance(int $idSeance): string
{
    $debut = DB::table('workouts')->where('id', $idSeance)->value('started_at');

    return is_string($debut) ? $debut : '';
}

it('signale une date de séance recopiée sur les lignes qui s’écarte du début de la séance, ou qui reste vide', function (): void {
    [, $set] = compteCoherent();
    $idSeance = $set->workoutLine->workout_id;

    $vide = WorkoutLine::factory()->create([
        'workout_id' => $idSeance,
        'exercise_id' => $set->workoutLine->exercise_id,
    ]);
    DB::table('workout_lines')->where('id', $set->workout_line_id)->update(['workout_started_at' => '2026-01-01 08:00:00']);
    DB::table('workout_lines')->where('id', $vide->id)->update(['workout_started_at' => null]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain('1 date de séance recopiée sur ses lignes')
        ->expectsOutputToContain(sprintf('séance %d (début %s) : 2 ligne(s) dont la copie vaut NULL, 2026-01-01 08:00:00', $idSeance, coherenceDebutDeLaSeance($idSeance)))
        ->expectsOutputToContain('1 écart(s)');
});

it('laisse passer une date recopiée égale au début de sa séance', function (): void {
    [, $set] = compteCoherent();

    expect(DB::table('workout_lines')->where('id', $set->workout_line_id)->value('workout_started_at'))
        ->toBe(coherenceDebutDeLaSeance($set->workoutLine->workout_id));

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(0)
        ->expectsOutputToContain('OK date de séance recopiée sur ses lignes');
});

/*
 * La réparation passe par l'écrivain de la propagation,
 * `Workout::recopierLeDebutSurSesLignes()`, celui que la séance appelle quand
 * elle change de date : aucun écrivain ne s'ajoute. Elle ne touche que les
 * séances en écart, et oublie ensuite les statistiques et les valeurs
 * proposées de leur compte, qui se lisent les unes et les autres sur la
 * copie.
 */
it('recopie par --repair le début de la séance sur ses lignes, par l’écrivain de la propagation, sur les seules séances en écart', function (): void {
    [$user, $set] = compteCoherent();
    [$voisin] = compteCoherent();
    $idSeance = $set->workoutLine->workout_id;

    DB::table('workout_lines')->where('id', $set->workout_line_id)->update(['workout_started_at' => '2026-01-01 08:00:00']);

    $versions = static fn (User $compte): array => [
        VersionDeCache::lire("stats-version.seances.{$compte->id}"),
        VersionDeCache::lire("recommended_values:version:{$compte->id}"),
    ];
    $avant = $versions($user);
    $avantDuVoisin = $versions($voisin);

    $requetes = coherenceRequetesPendant(function (): void {
        $this->artisan('app:verify-data-coherence', ['--repair' => true])
            ->expectsOutputToContain('1 séance(s) dont le début a été recopié sur les lignes')
            ->expectsOutputToContain('OK date de séance recopiée sur ses lignes')
            ->assertExitCode(0)
            ->run();
    });

    $miseAJour = array_values(array_filter($requetes, static fn (string $sql): bool => str_starts_with($sql, 'update `workout_lines`')));
    $apres = $versions($user);
    $apresDuVoisin = $versions($voisin);

    expect(DB::table('workout_lines')->where('id', $set->workout_line_id)->value('workout_started_at'))->toBe(coherenceDebutDeLaSeance($idSeance))
        ->and($miseAJour)->toHaveCount(1)
        ->and($miseAJour[0] ?? '')->toContain('`workout_id` = ?')
        ->and($apres[0])->not->toBe($avant[0])
        ->and($apres[1])->not->toBe($avant[1])
        ->and($apresDuVoisin)->toBe($avantDuVoisin);
});

/*
 * L'heure que le fuseau de l'application saute au passage à l'heure d'été
 * (2026-03-29 02:30:00 n'existe pas à Europe/Paris). Seule une écriture hors du
 * cast la pose en base (constructeur de requêtes, migration, import, fuseau
 * changé depuis l'écriture) ; relue par le cast, elle devient 03:30 CEST. La
 * copie recopie l'heure murale que la base tient, celle que le contrôle compare
 * en SQL : recopiée depuis l'instant relu, elle valait 03:30, et le contrôle
 * restait en erreur après chaque `--repair`, qui la comptait pourtant comme
 * recopiée. Seul ce contrôle est jugé ici : la date de dernière séance du
 * compte, relue par le cast, a son propre écart à cette heure.
 */
it('recopie par --repair l’heure murale d’un début que l’heure d’été saute, et le contrôle repasse au vert', function (): void {
    [, $set] = compteCoherent();
    $idSeance = $set->workoutLine->workout_id;

    DB::table('workouts')->where('id', $idSeance)->update(['started_at' => '2026-03-29 02:30:00', 'started_at_offset' => null]);
    DB::table('workout_lines')->where('id', $set->workout_line_id)->update(['workout_started_at' => '2026-01-01 08:00:00']);

    $this->artisan('app:verify-data-coherence', ['--repair' => true])
        ->expectsOutputToContain('1 séance(s) dont le début a été recopié sur les lignes');

    expect(DB::table('workout_lines')->where('id', $set->workout_line_id)->value('workout_started_at'))->toBe('2026-03-29 02:30:00');

    $this->artisan('app:verify-data-coherence')
        ->expectsOutputToContain('OK date de séance recopiée sur ses lignes');
});

it('recopie l’heure murale d’un début que l’heure d’été saute sur une ligne qui s’enregistre', function (): void {
    [, $set] = compteCoherent();
    $idSeance = $set->workoutLine->workout_id;

    DB::table('workouts')->where('id', $idSeance)->update(['started_at' => '2026-03-29 02:30:00', 'started_at_offset' => null]);
    DB::table('workout_lines')->where('id', $set->workout_line_id)->update(['workout_started_at' => '2026-03-29 02:30:00']);

    $ligne = WorkoutLine::factory()->create([
        'workout_id' => $idSeance,
        'exercise_id' => $set->workoutLine->exercise_id,
    ]);

    expect(DB::table('workout_lines')->where('id', $ligne->id)->value('workout_started_at'))->toBe('2026-03-29 02:30:00');

    $this->artisan('app:verify-data-coherence')
        ->expectsOutputToContain('OK date de séance recopiée sur ses lignes');
});

/*
 * L'heure que le passage à l'heure d'hiver répète (#2159) : les deux
 * occurrences s'écrivent 02:30:00 avec leur décalage, et la copie, à l'heure
 * murale, vaut 02:30:00 dans l'une comme dans l'autre.
 */
it('recopie l’heure murale d’un début pris dans l’une ou l’autre occurrence de l’heure que l’heure d’hiver répète', function (int $decalage): void {
    [, $set] = compteCoherent();
    $idSeance = $set->workoutLine->workout_id;

    DB::table('workouts')->where('id', $idSeance)->update(['started_at' => '2026-10-25 02:30:00', 'started_at_offset' => $decalage]);
    DB::table('workout_lines')->where('id', $set->workout_line_id)->update(['workout_started_at' => '2026-01-01 08:00:00']);

    $this->artisan('app:verify-data-coherence', ['--repair' => true])
        ->expectsOutputToContain('1 séance(s) dont le début a été recopié sur les lignes');

    $ligne = WorkoutLine::factory()->create([
        'workout_id' => $idSeance,
        'exercise_id' => $set->workoutLine->exercise_id,
    ]);

    expect(DB::table('workout_lines')->whereIn('id', [$set->workout_line_id, $ligne->id])->pluck('workout_started_at')->all())
        ->toBe(['2026-10-25 02:30:00', '2026-10-25 02:30:00']);

    $this->artisan('app:verify-data-coherence')
        ->expectsOutputToContain('OK date de séance recopiée sur ses lignes');
})->with(['première occurrence (CEST)' => [120], 'seconde occurrence (CET)' => [60]]);

it('ne réécrit par --repair aucune ligne quand chaque date recopiée est juste', function (): void {
    compteCoherent();

    $requetes = coherenceRequetesPendant(function (): void {
        $this->artisan('app:verify-data-coherence', ['--repair' => true])
            ->expectsOutputToContain('0 séance(s) dont le début a été recopié sur les lignes')
            ->assertExitCode(0)
            ->run();
    });

    expect(array_values(array_filter($requetes, static fn (string $sql): bool => str_starts_with($sql, 'update `workout_lines`'))))->toBe([]);
});

/**
 * Un controle qu'on ne peut pas ramener au vert finit desactive.
 *
 * La reparation n'invente rien : elle appelle le meme `recompute()` que
 * l'application execute quand une serie disparait.
 */
it('reconstruit les records détachés et repasse au vert', function (): void {
    [$user, $set] = compteCoherent();

    // Une seconde serie, plus legere, qui reprendra le record.
    Set::factory()->create([
        'workout_line_id' => $set->workout_line_id,
        'weight' => 60,
        'reps' => 10,
        'is_warmup' => false,
    ]);

    DB::table('sets')->where('id', $set->id)->delete();

    // Supprimer une serie en base fait aussi deriver les volumes, que rien ne
    // decremente sur ce chemin. On les remet a leur valeur reelle pour que le
    // test porte sur le seul ecart qui l'interesse — le controle, lui, signale
    // bien les deux, et c'est ce qu'on veut de lui.
    DB::table('workouts')->where('id', $set->workoutLine->workout_id)->update(['workout_volume' => 600]);

    $this->artisan('app:verify-data-coherence')->assertExitCode(1);

    $this->artisan('app:verify-data-coherence --repair')
        ->assertExitCode(0)
        ->expectsOutputToContain('reconstruit');

    $valeur = PersonalRecord::query()
        ->where('user_id', $user->id)
        ->where('type', 'max_weight')
        ->value('value');

    expect(is_numeric($valeur) ? (float) $valeur : null)->toBe(60.0);
});

/**
 * Le compte affiche etait celui des lignes RENDUES, or `--limit` bornait la
 * requete : cinq cents utilisateurs derivant s'annoncaient « 5 ». Un controle
 * nocturne qui sous-estime l'etendue d'une derive est pire que muet, puisqu'il
 * a l'air d'avoir mesure.
 */
it('annonce le nombre d’écarts, pas le nombre d’exemples cités', function (): void {
    $derivants = [];

    for ($i = 0; $i < 7; $i++) {
        [$user] = compteCoherent();
        $derivants[] = $user->id;
    }

    DB::table('workouts')->whereIn('user_id', $derivants)->update(['workout_volume' => 9999]);

    $this->artisan('app:verify-data-coherence', ['--limit' => 2])
        ->assertExitCode(1)
        ->expectsOutputToContain('7 volume des séances')
        ->expectsOutputToContain('… et 5 autre(s), non cité(s)');
});

/**
 * Le controle des volumes utilisateurs ne descend plus jusqu'aux series : une
 * seance qui ment sur les siennes doit rester vue par l'autre controle.
 */
it('voit encore une séance qui ment sur ses séries', function (): void {
    [$user, $set] = compteCoherent();
    $idSeance = $set->workoutLine->workout_id;

    // La seance ET l'utilisateur portent le meme chiffre faux : leur somme
    // concorde, seule la comparaison aux series peut les demasquer.
    DB::table('workouts')->where('id', $idSeance)->update(['workout_volume' => 4242]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain("séance {$idSeance}");
});

/**
 * Le controle voisin compare la valeur du record au poids de sa serie ; il ne
 * demande jamais si cette serie comptait. Un record pose sur une serie jamais
 * cochee — le defaut corrige par #1615 — n'est pas DETACHE, donc il passait
 * les deux controles existants et survivait a la reparation.
 */
it('signale un record assis sur une série jamais cochée', function (): void {
    [$user, $set] = compteCoherent();

    DB::table('sets')->where('id', $set->id)->update(['is_completed' => false]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain("de l'utilisateur {$user->id} s'appuie sur une série qui ne compte pas");
});

it('signale un record assis sur un échauffement', function (): void {
    [, $set] = compteCoherent();

    DB::table('sets')->where('id', $set->id)->update(['is_warmup' => true]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain("s'appuie sur une série qui ne compte pas");
});

it('reconstruit un record assis sur une série inéligible', function (): void {
    [$user, $set] = compteCoherent();

    // Une seconde serie, faite celle-la, plus legere : c'est elle qui doit
    // porter le record une fois la premiere ecartee.
    Set::factory()->create([
        'workout_line_id' => $set->workout_line_id,
        'weight' => 40,
        'reps' => 10,
        'is_warmup' => false,
        'is_completed' => true,
    ]);

    DB::table('sets')->where('id', $set->id)->update(['is_completed' => false]);

    $this->artisan('app:verify-data-coherence', ['--repair' => true]);

    $record = DB::table('personal_records')
        ->where('user_id', $user->id)
        ->where('type', 'max_weight')
        ->value('value');

    expect(is_numeric($record) ? (float) $record : null)->toBe(40.0);
});

/**
 * Le controle voyait ces deux-la, la reparation non.
 *
 * Un record dont la VALEUR diverge d'une serie pourtant valide n'est ni
 * detache ni inegible : il sortait de la selection de `reconstruireLesRecords()`.
 * Et `last_workout_at` etait controle sans jamais etre repare. Les deux sont
 * apparus en production le 31/08, apres un `--repair` qui les a laisses rouges.
 */
it('reconstruit un record dont la valeur ne correspond plus à sa série', function (): void {
    [$user, $set] = compteCoherent();

    // La valeur du record ment, mais sa serie est valide : ni detache, ni
    // inegible. C'est le cas qu'aucune reparation ne couvrait.
    DB::table('personal_records')
        ->where('user_id', $user->id)
        ->where('type', 'max_weight')
        ->update(['value' => 500]);

    $this->artisan('app:verify-data-coherence', ['--repair' => true]);

    $record = DB::table('personal_records')
        ->where('user_id', $user->id)
        ->where('type', 'max_weight')
        ->value('value');

    expect(is_numeric($record) ? (float) $record : null)->toBe((float) $set->weight);
});

it('refait la date de dernière séance quand elle pointe dans le vide', function (): void {
    [$user, $set] = compteCoherent();

    $reelle = $set->workoutLine->workout->started_at;

    // Une date en avance de trois mois sur la derniere seance : c'est ce qu'une
    // suppression anterieure a #1460 laissait derriere elle.
    DB::table('users')->where('id', $user->id)->update([
        'last_workout_at' => $reelle->copy()->addMonths(3),
    ]);

    $this->artisan('app:verify-data-coherence', ['--repair' => true]);

    $stockee = DB::table('users')->where('id', $user->id)->value('last_workout_at');

    expect(is_string($stockee) ? $stockee : null)->toBe($reelle->toDateTimeString());
});

it('repasse au vert après réparation', function (): void {
    [$user, $set] = compteCoherent();

    DB::table('personal_records')->where('user_id', $user->id)->where('type', 'max_weight')->update(['value' => 500]);
    DB::table('users')->where('id', $user->id)->update([
        'last_workout_at' => $set->workoutLine->workout->started_at->copy()->addMonths(3),
    ]);
    DB::table('workouts')->where('id', $set->workoutLine->workout_id)->update(['workout_volume' => 42]);

    $this->artisan('app:verify-data-coherence', ['--repair' => true]);

    // Une seconde passe, sans réparer : plus rien ne doit être signalé.
    $this->artisan('app:verify-data-coherence')->assertExitCode(0);
});

/**
 * Les requêtes SQL envoyées pendant l'appel, dans l'ordre. L'écoute reste
 * posée après lui, sur un tableau que plus rien ne lit.
 *
 * @return list<string>
 */
function coherenceRequetesPendant(callable $appel): array
{
    $requetes = [];

    DB::listen(static function (QueryExecuted $requete) use (&$requetes): void {
        $requetes[] = $requete->sql;
    });

    $appel();

    return $requetes;
}

/**
 * Les requêtes qui verrouillent ou réécrivent une séance.
 *
 * @param  list<string>  $requetes
 * @return list<string>
 */
function coherenceEcrituresDesSeances(array $requetes): array
{
    return array_values(array_filter(
        $requetes,
        static fn (string $sql): bool => preg_match('/^update `workouts`|from `workouts`.* for update$/i', $sql) === 1,
    ));
}

/*
 * La réparation du volume passe par l'écrivain de la séance (#1513). Elle
 * refaisait la somme par sa propre requête et l'écrivait sans verrou : une
 * série enregistrée entre sa lecture et son écriture repartait sous l'ancien
 * total. `Workout::recalculerLeVolume()` relit le total sous le verrou de la
 * ligne, comme à chaque série enregistrée.
 */
it('recale par --repair un volume faussé en base, par l’écrivain de la séance et sous son verrou', function (): void {
    [, $set] = compteCoherent();
    $idSeance = $set->workoutLine->workout_id;

    DB::table('workouts')->where('id', $idSeance)->update(['workout_volume' => 42]);

    $requetes = coherenceRequetesPendant(function (): void {
        $this->artisan('app:verify-data-coherence', ['--repair' => true])
            ->expectsOutputToContain('1 séance(s) dont le volume a été recalé')
            ->expectsOutputToContain('OK volume des séances')
            ->assertExitCode(0)
            ->run();
    });

    $volume = DB::table('workouts')->where('id', $idSeance)->value('workout_volume');

    expect(is_numeric($volume) ? (float) $volume : null)->toBe(1000.0)
        ->and(array_values(array_filter(coherenceEcrituresDesSeances($requetes), static fn (string $sql): bool => str_ends_with($sql, 'for update'))))
        ->toHaveCount(1);
});

it('ne verrouille ni ne réécrit par --repair une séance dont le volume est juste', function (): void {
    compteCoherent();

    $requetes = coherenceRequetesPendant(function (): void {
        $this->artisan('app:verify-data-coherence', ['--repair' => true])
            ->expectsOutputToContain('0 séance(s) dont le volume a été recalé')
            ->assertExitCode(0)
            ->run();
    });

    expect(coherenceEcrituresDesSeances($requetes))->toBe([]);
});

/*
 * L'avancement d'un objectif (#1513) : sa valeur actuelle, sa barre et son
 * atteinte. Le contrôle les compare au calcul de leur écrivain,
 * `GoalService::syncGoals()`, sans rien écrire. Le panneau laissait taper la
 * valeur et la date, et rien ne voyait un objectif que son calcul démentait.
 */

/**
 * Un objectif de chaque sorte sur le compte de `compteCoherent()`, tenus par
 * leur écrivain : la charge et le volume sur l'exercice de sa série (record de
 * 100 kg, séance de 1000), la fréquence sur sa séance faite, une mensuration
 * qu'aucune pesée ne porte.
 *
 * @return array{weight: Goal, volume: Goal, frequency: Goal, measurement: Goal}
 */
function coherenceObjectifsTenus(User $user, Set $set): array
{
    $exercice = $set->workoutLine->exercise_id;
    $objectifs = [
        'weight' => Goal::factory()->create(['user_id' => $user->id, 'type' => 'weight', 'exercise_id' => $exercice, 'start_value' => 50, 'target_value' => 150]),
        'volume' => Goal::factory()->create(['user_id' => $user->id, 'type' => 'volume', 'exercise_id' => $exercice, 'start_value' => 0, 'target_value' => 800]),
        'frequency' => Goal::factory()->create(['user_id' => $user->id, 'type' => 'frequency', 'start_value' => 0, 'target_value' => 10]),
        'measurement' => Goal::factory()->create(['user_id' => $user->id, 'type' => 'measurement', 'measurement_type' => 'weight', 'start_value' => 90, 'target_value' => 80]),
    ];

    app(GoalService::class)->syncGoals($user);

    return array_map(static fn (Goal $objectif): Goal => $objectif->refresh(), $objectifs);
}

it('laisse passer les objectifs que leur écrivain tient', function (): void {
    [$user, $set] = compteCoherent();
    $objectifs = coherenceObjectifsTenus($user, $set);

    // Le calcul a bien tourné : un objectif atteint, les autres en chemin.
    expect($objectifs['weight']->current_value)->toBe(100.0)
        ->and($objectifs['volume']->completed_at)->not->toBeNull()
        ->and($objectifs['frequency']->current_value)->toBe(1.0);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(0)
        ->expectsOutputToContain('OK avancement des objectifs');
});

it('signale un objectif dont la valeur et la barre stockées s’écartent de leur calcul, sans rien écrire', function (): void {
    [$user, $set] = compteCoherent();
    $objectif = coherenceObjectifsTenus($user, $set)['weight'];

    DB::table('goals')->where('id', $objectif->id)->update(['current_value' => 999, 'progress_pct' => 7]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain('1 avancement des objectifs')
        ->expectsOutputToContain("objectif {$objectif->id} du compte {$user->id} : stocké 999 (7 %, non atteint), calculé 100 (50 %, non atteint)")
        ->expectsOutputToContain('1 écart(s)');

    $stocke = DB::table('goals')->where('id', $objectif->id)->first(['current_value', 'progress_pct']);

    expect(get_object_vars($stocke ?? new stdClass()))->toEqual(['current_value' => 999, 'progress_pct' => 7]);
});

/*
 * La barre se compare à part de la valeur : une barre fausse sous une valeur
 * juste est un écart, que le cas précédent, où les deux s'écartent, ne
 * distingue pas. Au centième près, comme la valeur et les autres contrôles.
 */
it('signale un objectif dont seule la barre stockée s’écarte de son calcul', function (): void {
    [$user, $set] = compteCoherent();
    $objectif = coherenceObjectifsTenus($user, $set)['weight'];

    DB::table('goals')->where('id', $objectif->id)->update(['progress_pct' => 7]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain('1 avancement des objectifs')
        ->expectsOutputToContain("objectif {$objectif->id} du compte {$user->id} : stocké 100 (7 %, non atteint), calculé 100 (50 %, non atteint)");
});

it('laisse passer une barre stockée à moins d’un centième de son calcul', function (): void {
    [$user, $set] = compteCoherent();
    $objectif = coherenceObjectifsTenus($user, $set)['weight'];

    DB::table('goals')->where('id', $objectif->id)->update(['progress_pct' => 50.004]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(0)
        ->expectsOutputToContain('OK avancement des objectifs');
});

it('signale un objectif que la base dit atteint ou non atteint à tort', function (string $sorte, ?string $atteintLe, string $attendu): void {
    [$user, $set] = compteCoherent();
    $objectif = coherenceObjectifsTenus($user, $set)[$sorte];

    DB::table('goals')->where('id', $objectif->id)->update(['completed_at' => $atteintLe]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain('1 avancement des objectifs')
        ->expectsOutputToContain("objectif {$objectif->id} du compte {$user->id} : {$attendu}");
})->with([
    'atteint, la date effacée' => ['volume', null, 'stocké 1000 (100 %, non atteint), calculé 1000 (100 %, atteint)'],
    'en chemin, une date posée' => ['weight', '2026-01-01 10:00:00', 'stocké 100 (50 %, atteint), calculé 100 (50 %, non atteint)'],
]);

/*
 * Le contrôle ne répare pas : `syncGoals()` est l'écrivain, et il recalcule
 * l'objectif à la prochaine séance, série ou mesure du compte, ou au prochain
 * enregistrement de l'objectif. Seul un compte dont la réparation refait des
 * records voit ses objectifs recalculés (cas suivant) ; celui-ci n'en a aucun
 * à refaire.
 */
it('ne touche pas à l’objectif signalé sous --repair', function (): void {
    [$user, $set] = compteCoherent();
    $objectif = coherenceObjectifsTenus($user, $set)['weight'];

    DB::table('goals')->where('id', $objectif->id)->update(['current_value' => 999]);

    $this->artisan('app:verify-data-coherence', ['--repair' => true])
        ->assertExitCode(1)
        ->expectsOutputToContain('1 avancement des objectifs');

    $valeur = DB::table('goals')->where('id', $objectif->id)->value('current_value');

    expect(is_numeric($valeur) ? (float) $valeur : null)->toBe(999.0);
});

/*
 * Le record de poids maximum est la source de l'objectif de charge. Quand
 * `--repair` refait des records, il recalcule les objectifs de leurs comptes
 * par leur écrivain, comme l'application après chaque recalcul de records
 * (#1953). Sans cela, la réparation corrigeait le record, le contrôle signalait
 * aussitôt l'objectif qui lisait l'ancien, et la commande sortait en erreur sur
 * un écart qu'elle venait de créer, sans le recaler (#1513). Deux chemins
 * refont des records : un record en écart de sa série, et un record d'un type
 * que l'application ne tient plus, que la base confond avec le poids maximum.
 */
it('recalcule par --repair les objectifs des comptes dont il refait les records', function (string $type): void {
    [$user, $set] = compteCoherent();
    $objectif = coherenceObjectifsTenus($user, $set)['weight'];

    // Un poids maximum faux, 500 au lieu des 100 de la série, que l'objectif lit.
    DB::table('personal_records')->where('user_id', $user->id)->where('type', 'max_weight')->update(['type' => $type, 'value' => 500]);
    app(GoalService::class)->syncGoals($user);

    expect($objectif->refresh()->current_value)->toBe(500.0)
        ->and($objectif->completed_at)->not->toBeNull();

    $this->artisan('app:verify-data-coherence', ['--repair' => true])
        ->expectsOutputToContain('OK avancement des objectifs')
        ->expectsOutputToContain('1 compte(s) dont les objectifs ont été recalculés sur leurs records refaits')
        ->assertExitCode(0)
        ->run();

    $objectif->refresh();

    expect($objectif->current_value)->toBe(100.0)
        ->and((float) $objectif->progress_pct)->toBe(50.0)
        ->and($objectif->completed_at)->toBeNull();
})->with([
    'un record en écart de sa série' => ['max_weight'],
    'un record d’un type que l’application ne tient plus' => ['MAX_WEIGHT'],
]);

/**
 * Un record qui désigne une série existante, éligible et de même poids, mais
 * pas la meilleure : l'état que laissaient deux synchronisations croisées du
 * même exercice (#1984), la plus lente écrivant 100 kg par-dessus 105.
 *
 * @return array{0: User, 1: Set, 2: PersonalRecord}
 */
function coherenceRecordResteSousLaMeilleureSerie(): array
{
    [$user, $legere] = compteCoherent();

    $lourde = Set::factory()->create([
        'workout_line_id' => $legere->workout_line_id,
        'weight' => 105,
        'reps' => 10,
        'is_warmup' => false,
    ]);

    DB::table('personal_records')
        ->where('user_id', $user->id)
        ->where('type', 'max_weight')
        ->update(['value' => 100, 'secondary_value' => 10, 'set_id' => $legere->id]);

    $record = PersonalRecord::query()->where('user_id', $user->id)->where('type', 'max_weight')->firstOrFail();

    return [$user, $lourde, $record];
}

/*
 * Les trois contrôles de records demandent si la série d'un record existe, si
 * elle compte et si elle porte son poids, jamais si c'est la meilleure : ils
 * disaient OK sur un record resté sous la meilleure série (#1984).
 */
it('signale un record resté sous la meilleure série éligible, que les autres contrôles laissent passer', function (): void {
    [$user, $lourde, $record] = coherenceRecordResteSousLaMeilleureSerie();

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain('OK records rattachés à une série existante')
        ->expectsOutputToContain('OK valeur des records')
        ->expectsOutputToContain('OK records assis sur une série éligible')
        ->expectsOutputToContain('1 records égaux à la meilleure série éligible')
        ->expectsOutputToContain("record {$record->id} (max_weight) de l'utilisateur {$user->id}, exercice {$record->exercise_id} : annonce 100.00, la meilleure série ({$lourde->id}) donne 105")
        ->expectsOutputToContain('1 écart(s)');
});

it('remonte par --repair un record resté sous la meilleure série éligible', function (): void {
    [$user, $lourde] = coherenceRecordResteSousLaMeilleureSerie();

    $this->artisan('app:verify-data-coherence', ['--repair' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('1 exercice(s) reconstruit(s)');

    $record = PersonalRecord::query()->where('user_id', $user->id)->where('type', 'max_weight')->firstOrFail();

    expect((float) $record->value)->toBe(105.0)
        ->and($record->set_id)->toBe($lourde->id);
});

/*
 * La réparation remonte le record vers une série que sa synchronisation a
 * déjà annoncée : la plus lente des deux l'avait écrasé ensuite. Elle n'annonce
 * rien (#2185), là où la reconstruction des écritures de l'application annonce
 * le record qu'elle donne au-dessus de sa valeur à une autre série.
 */
it('n’annonce rien du record que --repair remonte vers la meilleure série', function (): void {
    [$user, $lourde] = coherenceRecordResteSousLaMeilleureSerie();
    NotificationPreference::factory()->create(['user_id' => $user->id, 'type' => 'personal_record', 'is_enabled' => true, 'is_push_enabled' => true]);
    Notification::fake();

    $this->artisan('app:verify-data-coherence', ['--repair' => true])->assertExitCode(0);

    expect(PersonalRecord::query()->where('user_id', $user->id)->where('type', 'max_weight')->value('set_id'))->toBe($lourde->id);

    Notification::assertNothingSentTo($user);
});

/*
 * Le contrôle de valeur ne juge que le poids maximal. Un 1RM ou un volume resté
 * à une valeur que plus aucune série ne donne passait donc aussi : la
 * comparaison à la meilleure série le voit, dans les deux sens.
 */
it('signale un record de 1RM que plus aucune série ne donne, et --repair le refait', function (): void {
    [$user] = compteCoherent();

    DB::table('personal_records')->where('user_id', $user->id)->where('type', 'max_1rm')->update(['value' => 999]);

    $this->artisan('app:verify-data-coherence')
        ->assertExitCode(1)
        ->expectsOutputToContain('OK valeur des records')
        ->expectsOutputToContain('1 records égaux à la meilleure série éligible');

    $this->artisan('app:verify-data-coherence', ['--repair' => true])->assertExitCode(0);

    $valeur = DB::table('personal_records')->where('user_id', $user->id)->where('type', 'max_1rm')->value('value');

    expect(is_numeric($valeur) ? (float) $valeur : null)->toBe(133.33);
});

/*
 * `--repair` retire les records d'un type que l'application ne tient plus, et
 * refait leur exercice depuis ses séries, AVANT de reconstruire les records
 * détachés ou en écart (#1811). Cette reconstruction relit les records de
 * l'exercice par le modèle, qui lève sur une valeur que l'enum ne connaît pas :
 * un poids maximum écrit 'MAX_WEIGHT' et détaché de sa série faisait tomber la
 * réparation entière (`ValueError`), et rien ne pouvait plus la ramener au
 * vert. Le retrait n'annonce rien, comme le reste de la réparation (#2185).
 */
it('retire par --repair un poids maximum écrit en capitales et détaché, sans lever, et le refait depuis sa série sans l’annoncer', function (): void {
    [$user, $set] = compteCoherent();
    NotificationPreference::factory()->create(['user_id' => $user->id, 'type' => 'personal_record', 'is_enabled' => true, 'is_push_enabled' => true]);

    DB::table('personal_records')
        ->where('user_id', $user->id)
        ->where('type', 'max_weight')
        ->update(['type' => 'MAX_WEIGHT', 'set_id' => null]);

    Notification::fake();

    $this->artisan('app:verify-data-coherence', ['--repair' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("1 record(s) d'un type que l'application ne tient plus retiré(s), sur 1 exercice(s) refait(s) depuis leurs séries")
        ->expectsOutputToContain('OK type des records');

    $record = PersonalRecord::query()->where('user_id', $user->id)->where('type', 'max_weight')->sole();

    expect(DB::table('personal_records')->whereRaw('CAST(type AS BINARY) = ?', ['MAX_WEIGHT'])->count())->toBe(0)
        ->and((float) $record->value)->toBe(100.0)
        ->and($record->set_id)->toBe($set->id);

    Notification::assertNothingSentTo($user);
});

it('retire par --repair un record d’un type non suivi, et le contrôle repasse au vert', function (string $type, bool $alterationDUnTypeSuivi): void {
    [$user, $set] = compteCoherent();

    if ($alterationDUnTypeSuivi) {
        // L'index unique suit la collation : la variante prend la place du
        // type suivi qu'elle imite.
        DB::table('personal_records')->where('user_id', $user->id)->where('type', 'max_weight')->update(['type' => $type]);
    } else {
        recordDeTypeHeritePlanteEnBase($user, $set, $type);
    }

    $this->artisan('app:verify-data-coherence', ['--repair' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("1 record(s) d'un type que l'application ne tient plus retiré(s), sur 1 exercice(s) refait(s) depuis leurs séries")
        ->expectsOutputToContain('OK type des records');

    expect(DB::table('personal_records')->where('user_id', $user->id)->pluck('type')->all())
        ->toEqualCanonicalizing(['max_weight', 'max_1rm', 'max_volume_set']);
})->with([
    '1RM' => ['1RM', false],
    'strength' => ['strength', false],
    'cardio' => ['cardio', false],
    'volume' => ['volume', false],
    'un type inconnu' => ['inconnu', false],
    'le poids maximum en majuscules' => ['MAX_WEIGHT', true],
    'le poids maximum avec une espace finale' => ['max_weight ', true],
]);

/*
 * Chaque couple (compte, exercice) se retire sous son propre verrou : la
 * réparation additionne ce que chacun a retiré, et compte les exercices
 * refaits.
 */
it('additionne par --repair les records retirés de chaque exercice refait', function (): void {
    [$premier, $seriePremier] = compteCoherent();
    [$second, $serieSecond] = compteCoherent();

    recordDeTypeHeritePlanteEnBase($premier, $seriePremier, 'cardio');
    recordDeTypeHeritePlanteEnBase($second, $serieSecond, '1RM');
    recordDeTypeHeritePlanteEnBase($second, $serieSecond, 'volume');

    $this->artisan('app:verify-data-coherence', ['--repair' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("3 record(s) d'un type que l'application ne tient plus retiré(s), sur 2 exercice(s) refait(s) depuis leurs séries")
        ->expectsOutputToContain('OK type des records');

    foreach ([$premier, $second] as $compte) {
        expect(DB::table('personal_records')->where('user_id', $compte->id)->pluck('type')->all())
            ->toEqualCanonicalizing(['max_weight', 'max_1rm', 'max_volume_set']);
    }
});

/*
 * Sans rien à retirer, la réparation le dit, et ne refait aucun exercice de
 * plus que les autres réparations.
 */
it('dit par --repair qu’il n’y a aucun record d’un type non suivi à retirer', function (): void {
    compteCoherent();

    $this->artisan('app:verify-data-coherence', ['--repair' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain("OK aucun record d'un type que l'application ne tient plus")
        ->doesntExpectOutputToContain('retiré(s)');
});
