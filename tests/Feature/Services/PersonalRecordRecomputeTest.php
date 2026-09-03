<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\PersonalRecordService;
use Illuminate\Support\Facades\DB;

/*
 * `recompute()` reconstruit les records a partir des series qui restent.
 *
 * C'est le chemin emprunte quand une serie est corrigee ou supprimee, et depuis
 * #1476 quand une seance entiere disparait. Trois de ses comportements
 * n'etaient couverts par rien : le volume qu'il calcule, les bornes qui decident
 * qu'une serie compte, et le filtre par type qui permet un recalcul partiel.
 */

/**
 * Un utilisateur, un exercice, et de quoi lui poser des series.
 *
 * @return array{0: User, 1: Exercise, 2: WorkoutLine}
 */
function contexteDeRecords(): array
{
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);

    return [$user, $exercise, $line];
}

function serie(WorkoutLine $line, ?float $poids, ?int $repetitions): Set
{
    return Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => $poids,
        'reps' => $repetitions,
        'is_warmup' => false,
    ]);
}

function recordDeType(User $user, string $type): ?PersonalRecord
{
    return PersonalRecord::query()
        ->where('user_id', $user->id)
        ->where('type', $type)
        ->first();
}

/**
 * Le volume d'une serie est un produit, pas un quotient.
 *
 * Ce test verrouille le chiffre que la page des records affiche : 100 kg pour 10
 * repetitions valent 1000, pas 10.
 *
 * Il ne tue PAS pour autant le mutant qui remplace la multiplication par une
 * division dans `processUpdates`, et je l'ai verifie en posant la mutation :
 * les cinq tests passent quand meme. La raison est la meme que pour le mutant
 * deja annote plus haut dans ce service — `Set::saved` appelle la
 * synchronisation PUIS `refreshRecordsHeldBy`, qui recalcule et reecrit la valeur
 * dans la meme sauvegarde. Ce que le premier chemin ecrit de travers, le second
 * le corrige avant que quiconque puisse le lire.
 *
 * Le chiffre merite d'etre garde malgre tout : c'est le seul endroit qui dise ce
 * que « volume d'une serie » veut dire, et un test le dira encore le jour ou les
 * deux chemins cesseront de se recouvrir.
 */
it('calcule le volume d’une série comme un produit', function (): void {
    [$user, , $line] = contexteDeRecords();

    serie($line, 100, 10);

    expect((float) recordDeType($user, 'max_volume_set')?->value)->toBe(1000.0);
});

/**
 * Les bornes qui decident qu'une serie compte.
 *
 * La requete de reconstruction ne retient que `weight > 0` et `reps > 0`. Les
 * deux bornes etaient libres : un kilo pile pouvait cesser de compter, et zero
 * repetition pouvait se mettre a compter, sans qu'aucun test ne bouge.
 */
it('retient une série d’un kilo, qui reste une série', function (): void {
    [$user, $exercise, $line] = contexteDeRecords();

    serie($line, 1, 5);

    app(PersonalRecordService::class)->recompute($user, $exercise->id);

    expect((float) recordDeType($user, 'max_weight')?->value)->toBe(1.0);
});

it('écarte une série sans aucune répétition', function (): void {
    [$user, $exercise, $line] = contexteDeRecords();

    // Un poids saisi, mais aucune repetition : rien n'a ete souleve.
    serie($line, 200, 0);

    app(PersonalRecordService::class)->recompute($user, $exercise->id);

    expect(recordDeType($user, 'max_weight'))->toBeNull();
});

/**
 * Le recalcul partiel ne doit pas s'arreter au premier type ecarte.
 *
 * `recompute()` accepte une liste de types et saute les autres. La mutation qui
 * remplace ce saut par un arret survivait : avec un filtre qui ne retient que le
 * DERNIER des trois types, elle ne recalcule plus rien du tout.
 *
 * Le filtre porte donc sur `max_volume_set`, troisieme et dernier. Un filtre sur
 * le premier type ne separerait rien — la boucle s'arreterait apres l'avoir
 * traite, exactement comme elle l'aurait fait en continuant.
 */
it('recalcule un type demandé même s’il vient après d’autres', function (): void {
    [$user, $exercise, $line] = contexteDeRecords();

    $grosse = serie($line, 100, 10);   // volume 1000
    serie($line, 50, 10);              // volume 500

    expect((float) recordDeType($user, 'max_volume_set')?->value)->toBe(1000.0);

    // Retiree sans evenement : c'est `recompute` qu'on teste, pas l'observateur.
    Set::withoutEvents(fn () => $grosse->delete());

    app(PersonalRecordService::class)->recompute($user, $exercise->id, ['max_volume_set']);

    expect((float) recordDeType($user, 'max_volume_set')?->value)->toBe(500.0);
});

/**
 * Le record reconstruit doit pointer sur la serie qui le detient desormais.
 *
 * `set_id` et `workout_id` sont ce qui relie un record a la seance ou il a ete
 * fait. Les laisser sur la serie disparue donnerait un record correct en valeur
 * et faux en provenance — et c'est justement un record detache qui a produit le
 * defaut de #1476.
 */
it('rattache le record reconstruit à la série qui le détient', function (): void {
    [$user, $exercise, $line] = contexteDeRecords();

    $lourde = serie($line, 200, 3);
    $legere = serie($line, 120, 3);

    expect(recordDeType($user, 'max_weight')?->set_id)->toBe($lourde->id);

    Set::withoutEvents(fn () => $lourde->delete());

    app(PersonalRecordService::class)->recompute($user, $exercise->id);

    $record = recordDeType($user, 'max_weight');

    expect((float) $record?->value)->toBe(120.0)
        ->and($record?->set_id)->toBe($legere->id)
        ->and($record?->workout_id)->toBe($line->workout_id);
});

/**
 * Corriger une série ne recalcule que les records qu'elle détenait.
 *
 * `refreshRecordsHeldBy` relève les types portés par la série puis ne reconstruit
 * que ceux-là. Ce relevé passait par un aiguillage défensif — instance
 * d'énumération, sinon chaîne, sinon chaîne vide — dont la moitié était morte :
 * `pluck()` applique le cast et rend toujours l'énumération. Mesuré avant de
 * supprimer.
 *
 * Si le relevé rendait une liste vide, la méthode sortirait sans rien
 * reconstruire et une valeur corrigée resterait gonflée pour toujours — c'est
 * exactement ce que ce chemin existe pour empêcher.
 */
it('rebâtit le record quand la série qui le détenait est corrigée', function (): void {
    [$user, , $line] = contexteDeRecords();

    $serie = serie($line, 200, 3);

    expect((float) recordDeType($user, 'max_weight')?->value)->toBe(200.0);

    // La correction d'une faute de frappe : 200 devient 100.
    $serie->update(['weight' => 100]);

    expect((float) recordDeType($user, 'max_weight')?->value)->toBe(100.0);
});

function seriesPour(User $user, Exercise $exercise, int $combien): void
{
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);

    for ($i = 1; $i <= $combien; $i++) {
        Set::factory()->create([
            'workout_line_id' => $line->id,
            'weight' => 50 + $i,
            'reps' => 5,
            'is_warmup' => false,
        ]);
    }
}

/** @return list<string> */
function requetesDe(callable $geste): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $geste();

    $requetes = array_map(fn (array $entree): string => (string) $entree['query'], DB::getQueryLog());
    DB::disableQueryLog();

    return array_values($requetes);
}

/** @return list<string> */
function lecturesDeSeries(User $user, Exercise $exercise): array
{
    $requetes = requetesDe(fn () => app(PersonalRecordService::class)->recompute($user, $exercise->id));

    return array_values(array_filter($requetes, fn (string $sql): bool => preg_match('/from `?sets`?/', $sql) === 1));
}

it('ne rapatrie qu’une ligne, quel que soit l’historique', function (): void {
    $exercise = Exercise::factory()->create(['type' => 'strength']);

    $modeste = User::factory()->create();
    seriesPour($modeste, $exercise, 3);
    $surPetit = lecturesDeSeries($modeste, $exercise);

    $fourni = User::factory()->create();
    seriesPour($fourni, $exercise, 60);
    $surGros = lecturesDeSeries($fourni, $exercise);

    // Compter les requêtes ne dirait rien : l'ancien code en faisait autant.
    expect($surPetit)->not->toBeEmpty()
        ->and($surGros)->toHaveCount(count($surPetit));

    // La borne, c'est le filtre de rang : la requête ne rend que les lignes
    // gagnantes, trois au plus. Et aucune liste `in (…)` ne suit, dont la
    // longueur grandissait avec l'historique.
    foreach ($surGros as $sql) {
        expect($sql)->toContain('rang_max_weight = 1');
    }

    expect(array_filter($surGros, fn (string $sql): bool => str_contains($sql, ' in (')))->toBeEmpty();

    $record = fn (User $user): ?PersonalRecord => PersonalRecord::where('user_id', $user->id)
        ->where('exercise_id', $exercise->id)
        ->where('type', 'max_weight')
        ->first();

    expect($record($modeste)?->value)->toEqual(53.0)
        ->and($record($fourni)?->value)->toEqual(110.0);
});

it('ne reconstruit rien quand la série supprimée ne détenait aucun record', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['type' => 'strength']);
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);

    $lourde = Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 200, 'reps' => 5, 'is_warmup' => false]);
    $legere = Set::factory()->create(['workout_line_id' => $line->id, 'weight' => 40, 'reps' => 5, 'is_warmup' => false]);

    app(PersonalRecordService::class)->recompute($user, $exercise->id);

    $avant = PersonalRecord::where('user_id', $user->id)->where('type', 'max_weight')->first();
    expect($avant?->set_id)->toBe($lourde->id);

    $requetes = requetesDe(fn () => $legere->delete());

    // La table des séries n'est pas relue : le recalcul n'a pas été déclenché.
    $relectures = array_filter($requetes, fn (string $sql): bool => preg_match('/from `?sets`?/', $sql) === 1 && preg_match('/join `?workout_lines`?/', $sql) === 1);

    expect($relectures)->toBeEmpty()
        ->and(PersonalRecord::where('user_id', $user->id)->where('type', 'max_weight')->first()?->set_id)
        ->toBe($lourde->id);
});
