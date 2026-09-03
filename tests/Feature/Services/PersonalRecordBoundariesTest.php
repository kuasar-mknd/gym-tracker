<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\NotificationPreference;
use App\Models\PersonalRecord;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Notifications\PersonalRecordAchieved;
use App\Services\PersonalRecordService;
use Illuminate\Support\Facades\Notification;

/*
 * Les bornes de PersonalRecordService, chacune signalee par un mutant survivant
 * et aucune couverte (#1446).
 *
 * Elles ont en commun de ne rien changer au CHIFFRE affiche — seulement a la
 * date, au nombre de lignes en base, ou au fait qu'une notification part ou non.
 * C'est-a-dire tout ce qu'on ne remarque qu'en cliquant, ou qu'on remarque une
 * fois de trop dans sa poche.
 *
 * A SAVOIR AVANT DE LIRE : les records sont deja calcules par le hook
 * `Set::saved` (AppServiceProvider::registerSetEvents), qui lance
 * SyncPersonalRecord en SYNCHRONE en environnement de test, puis
 * `refreshRecordsHeldBy`. Creer une serie suffit donc ; les appels explicites au
 * service ci-dessous ne servent qu'a rendre l'intention lisible, et sont des
 * non-operations quand le record est deja a jour. Ne pas les lire comme le
 * declencheur.
 */

/**
 * Un utilisateur qui a demande les notifications de record, et un exercice.
 *
 * @return array{User, Exercise}
 */
function contexteRecord(): array
{
    $user = User::factory()->create();

    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'type' => 'personal_record',
        'is_enabled' => true,
    ]);

    return [$user, Exercise::factory()->create(['type' => 'strength'])];
}

/**
 * Une serie dans sa propre seance, pour que chaque record ait une seance a lui.
 */
function serieDe(User $user, Exercise $exercise, float $poids, int $reps = 5): Set
{
    $workout = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);
    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    return Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => $poids,
        'reps' => $reps,
        'is_warmup' => false,
    ]);
}

/**
 * Egaler son record n'est pas le battre.
 *
 * `update()` sort tot sur `$value <= $pr->value`. Passe en `<`, egaler devient
 * battre : la date du record est reecrite et une notification « nouveau
 * record ! » repart. L'utilisateur recoit une felicitation pour avoir refait ce
 * qu'il faisait deja, et la date a laquelle il a etabli son record est perdue.
 */
it('n’annonce pas un nouveau record quand on ne fait qu’égaler', function (): void {
    Notification::fake();
    [$user, $exercise] = contexteRecord();

    app(PersonalRecordService::class)->syncSetPRs(serieDe($user, $exercise, 100));

    $record = PersonalRecord::where('type', 'max_weight')->firstOrFail();
    $dateInitiale = $record->achieved_at;

    Notification::assertSentToTimes($user, PersonalRecordAchieved::class, 3);

    // Meme poids, meme repetitions : rien n'est battu.
    app(PersonalRecordService::class)->syncSetPRs(serieDe($user, $exercise, 100));

    expect(PersonalRecord::where('type', 'max_weight')->firstOrFail()->achieved_at->equalTo($dateInitiale))
        ->toBeTrue();

    // Toujours trois, celles du premier passage : rien de neuf a annoncer.
    Notification::assertSentToTimes($user, PersonalRecordAchieved::class, 3);
});

/**
 * Battre son record met a jour la ligne, il ne s'en cree pas une seconde.
 *
 * `$pr ??= new PersonalRecord(...)` ne cree que si aucune ligne n'existe. Le
 * mutant remplacait l'affectation conditionnelle par une comparaison, ce qui
 * laissait `$pr` a null et faisait naitre une ligne de plus a chaque record
 * battu. Le chiffre affiche resterait juste — c'est l'historique qui doublerait.
 */
it('met à jour le record au lieu d’en créer un second', function (): void {
    Notification::fake();
    [$user, $exercise] = contexteRecord();

    $service = app(PersonalRecordService::class);

    $service->syncSetPRs(serieDe($user, $exercise, 50));
    $service->syncSetPRs(serieDe($user, $exercise, 60));

    // Trois types suivis — max_weight, max_1rm, max_volume_set — donc trois
    // lignes, pas six.
    expect(PersonalRecord::count())->toBe(3)
        ->and((float) PersonalRecord::where('type', 'max_weight')->firstOrFail()->value)->toBe(60.0);
});

/*
 * PAS DE TEST ICI POUR `secondary_value` NI `workout_id`, ET C'EST DELIBERE.
 *
 * Le tri des survivants classait les deux `RemoveArrayItem` de la ligne 81
 * comme des comportements non verifies. Verification faite, ils sont
 * EQUIVALENTS : `recompute()` remplit les memes cinq champs quelques lignes plus
 * bas (ligne 179), et il s'execute dans la meme sauvegarde via
 * `refreshRecordsHeldBy`. Retirer la cle de la ligne 81 est donc ecrase
 * immediatement.
 *
 * Un test aurait passe — il passait, je l'avais ecrit — en revendiquant une
 * couverture qu'il n'avait pas : c'est le second chemin qui ecrivait la valeur
 * qu'il asserait. Les deux mutants portent desormais un @pest-mutate-ignore
 * avec cette raison, dans le service.
 */

/**
 * Une serie a zero kilo ne compte pas, et corriger un poids a zero retire le
 * record qu'elle avait etabli.
 *
 * `recompute()` filtre sur `where('sets.weight', '>', 0)`. Passe en `>= 0`, une
 * serie corrigee a zero redevient candidate et garde son record — le chiffre
 * errone reste affiche apres correction, ce qui est precisement le defaut que
 * PersonalRecordCorrectionTest existe pour empecher, sur un chemin qu'il ne
 * couvrait pas.
 */
it('retire le record quand le poids qui l’a établi est corrigé à zéro', function (): void {
    Notification::fake();
    [$user, $exercise] = contexteRecord();

    $serie = serieDe($user, $exercise, 100);

    app(PersonalRecordService::class)->syncSetPRs($serie);

    expect((float) PersonalRecord::where('type', 'max_weight')->firstOrFail()->value)->toBe(100.0);

    $serie->update(['weight' => 0]);

    app(PersonalRecordService::class)->recompute($user, $exercise->id, ['max_weight']);

    expect(PersonalRecord::where('type', 'max_weight')->exists())->toBeFalse();
});
