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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/*
 * Ce qu'un record dit de LUI-MEME : d'ou il vient, quand il a ete fait, et ce
 * qui arrive quand la seance qui le porte n'est plus la.
 *
 * Les fichiers voisins verrouillent les CHIFFRES. Ici on verrouille la
 * provenance — `set_id`, `workout_id`, `achieved_at`, `secondary_value` — et
 * les deux chemins qui s'executent seuls en production :
 *
 *  - le travail en file `SyncPersonalRecord`, qui n'a personne derriere lui pour
 *    corriger ce qu'il ecrit ;
 *  - `refreshFor()` sur `Set::deleted`, ou la ligne parente ou la seance ont
 *    deja disparu par CASCADE (#1476).
 *
 * Les series sont posees sans evenement (`serieMuette`) : c'est l'etat qu'une
 * CASCADE laisse derriere elle, et cela evite surtout que le hook `Set::saved`
 * ecrive puis reecrive les records par les deux chemins a la fois — ce
 * recouvrement est justement ce qui rendait ces comportements invisibles.
 */

/** @return array{0: User, 1: Exercise, 2: WorkoutLine} */
function scenePourProvenance(): array
{
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $seance = Workout::factory()->create(['user_id' => $user->id]);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id, 'exercise_id' => $exercice->id]);

    return [$user, $exercice, $ligne];
}

/** Une serie faite, posee sans declencher le moindre hook. */
function serieMuette(WorkoutLine $ligne, float $poids, int $repetitions, bool $echauffement = false): Set
{
    return Set::withoutEvents(fn (): Set => Set::factory()->create([
        'workout_line_id' => $ligne->id,
        'weight' => $poids,
        'reps' => $repetitions,
        'is_warmup' => $echauffement,
        'is_completed' => true,
    ]));
}

function recordProvenance(User $user, string $type): ?PersonalRecord
{
    return PersonalRecord::query()
        ->where('user_id', $user->id)
        ->where('type', $type)
        ->first();
}

/**
 * Le record reconstruit dit d'ou il vient et quand il a ete fait.
 *
 * `recompute()` ne fait pas que corriger : quand rien n'existe, il ETABLIT le
 * record — c'est le chemin de `VerifyDataCoherence`, qui repare un compte dont
 * les records ont ete perdus. Tout ce que la page des records affiche vient
 * alors de cette seule ecriture : le proprietaire, l'exercice, le type, les
 * repetitions sous le chiffre, la seance, la serie, et la date.
 *
 * La date surtout : elle appartient a la serie, pas a la reparation. Un record
 * etabli en juin ne doit pas se mettre a dire septembre parce qu'on a relance
 * un recalcul en septembre.
 */
it('établit un record complet, daté du jour du soulevé', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-12 08:15:00'));

    [$user, $exercice, $ligne] = scenePourProvenance();
    $serie = serieMuette($ligne, 100, 7);

    // Trois mois plus tard, la reparation passe.
    Carbon::setTestNow(Carbon::parse('2026-09-04 21:00:00'));

    expect(PersonalRecord::count())->toBe(0);

    app(PersonalRecordService::class)->recompute($user, $exercice->id);

    $record = recordProvenance($user, 'max_weight');

    expect($record)->not->toBeNull()
        ->and($record?->user_id)->toBe($user->id)
        ->and($record?->exercise_id)->toBe($exercice->id)
        ->and($record?->type->value)->toBe('max_weight')
        ->and((float) $record?->value)->toBe(100.0)
        // Les sept repetitions faites a ce poids, affichees sous le chiffre.
        ->and((float) $record?->secondary_value)->toBe(7.0)
        // La seance d'ou vient le record, et la serie exacte.
        ->and($record?->workout_id)->toBe($ligne->workout_id)
        ->and($record?->set_id)->toBe($serie->id)
        // Le jour du souleve, pas le jour de la reparation.
        ->and($record?->achieved_at?->toDateTimeString())->toBe('2026-06-12 08:15:00');
});

/**
 * Les trois classements sont independants : une seule passe, trois gagnants.
 *
 * Un single lourd tient le poids maximum ; une serie plus legere mais longue
 * tient le 1RM estime et le volume. La serie longue n'est PAS premiere au
 * classement du poids — si le releve des gagnantes s'arretait au premier
 * classement perdu au lieu de passer au suivant, elle ne serait retenue pour
 * rien et deux records sur trois disparaitraient.
 */
it('tient trois records distincts issus de deux séries différentes', function (): void {
    [$user, $exercice, $ligne] = scenePourProvenance();

    // 100 kg en 1 rep : 1RM = 100, volume = 100.
    $single = serieMuette($ligne, 100, 1);
    // 90 kg en 10 reps : 1RM = 90 x (1 + 10/30) = 120, volume = 900.
    $longue = serieMuette($ligne, 90, 10);

    app(PersonalRecordService::class)->recompute($user, $exercice->id);

    expect((float) recordProvenance($user, 'max_weight')?->value)->toBe(100.0)
        ->and(recordProvenance($user, 'max_weight')?->set_id)->toBe($single->id)
        ->and((float) recordProvenance($user, 'max_1rm')?->value)->toBe(120.0)
        ->and(recordProvenance($user, 'max_1rm')?->set_id)->toBe($longue->id)
        ->and((float) recordProvenance($user, 'max_volume_set')?->value)->toBe(900.0)
        ->and(recordProvenance($user, 'max_volume_set')?->set_id)->toBe($longue->id);
});

/**
 * La ligne de seance a disparu : renoncer, pas planter.
 *
 * `Set::deleted` s'execute apres coup. Quand une seance est supprimee, la
 * CASCADE emporte ses lignes ET ses series sans lever le moindre evenement :
 * la serie qu'on tient encore en memoire ne retrouve plus sa ligne. Sans les
 * `?->`, la suppression d'une seance rendrait une erreur a l'utilisateur
 * (#1476).
 */
it('renonce sans casser quand la ligne de séance a disparu', function (): void {
    [$user, $exercice, $ligne] = scenePourProvenance();
    $serie = serieMuette($ligne, 120, 5);

    app(PersonalRecordService::class)->recompute($user, $exercice->id);
    expect((float) recordProvenance($user, 'max_weight')?->value)->toBe(120.0);

    // La CASCADE, telle quelle : la ligne s'en va et emporte ses series.
    DB::table('workout_lines')->where('id', $ligne->id)->delete();

    app(PersonalRecordService::class)->refreshFor($serie);

    // Rien n'a leve, et le record n'a pas ete touche.
    expect((float) recordProvenance($user, 'max_weight')?->value)->toBe(120.0);
});

/**
 * La seance et son proprietaire ont disparu, la ligne est encore en memoire.
 *
 * L'autre moitie du meme defaut : cette fois la ligne est chargee — c'est par
 * elle que l'evenement arrive — mais la seance qui la porte n'est plus en base,
 * donc plus aucun proprietaire ne se laisse etablir. Sans proprietaire il n'y a
 * rien a reconstruire, et la garde doit sortir : passer outre appellerait le
 * recalcul avec un utilisateur nul.
 */
it('renonce sans casser quand le propriétaire ne se laisse plus établir', function (): void {
    [$user, $exercice, $ligne] = scenePourProvenance();
    $serie = serieMuette($ligne, 150, 5);

    app(PersonalRecordService::class)->recompute($user, $exercice->id);
    expect((float) recordProvenance($user, 'max_weight')?->value)->toBe(150.0);

    // La ligne reste en memoire, la seance s'en va : c'est en la redemandant
    // que le service decouvre qu'elle n'est plus la.
    $ligne->unsetRelation('workout');
    $serie->setRelation('workoutLine', $ligne);
    DB::table('workouts')->where('id', $ligne->workout_id)->delete();

    app(PersonalRecordService::class)->refreshFor($serie);

    expect($ligne->exercise_id)->not->toBeNull()
        ->and((float) recordProvenance($user, 'max_weight')?->value)->toBe(150.0);
});

/**
 * Le proprietaire fourni suffit a reconstruire quand la chaine a disparu.
 *
 * C'est la raison d'etre du parametre `$user` : l'appelant le tient encore
 * (`Set::saved`, `Workout::deleted`) alors que la chaine qui y menait est en
 * train de se defaire. S'il etait ecrase par ce que la chaine rend — plus rien —
 * la reconstruction serait abandonnee et le record de la seance supprimee
 * resterait affiche pour toujours : le defaut de #1476, mot pour mot.
 */
it('reconstruit avec le propriétaire fourni quand la séance a disparu', function (): void {
    [$user, $exercice, $ligne] = scenePourProvenance();
    $lourde = serieMuette($ligne, 200, 5);

    // Une seconde seance, qui elle survivra a la suppression.
    $autre = Workout::factory()->create(['user_id' => $user->id]);
    $autreLigne = WorkoutLine::factory()->create(['workout_id' => $autre->id, 'exercise_id' => $exercice->id]);
    serieMuette($autreLigne, 120, 5);

    app(PersonalRecordService::class)->recompute($user, $exercice->id);
    expect((float) recordProvenance($user, 'max_weight')?->value)->toBe(200.0);

    // La seance qui portait le record s'en va, avec sa ligne et sa serie.
    $ligne->unsetRelation('workout');
    $lourde->setRelation('workoutLine', $ligne);
    DB::table('workouts')->where('id', $ligne->workout_id)->delete();

    app(PersonalRecordService::class)->refreshFor($lourde, $user);

    // Le record redescend a ce qui reste vraiment souleve.
    expect((float) recordProvenance($user, 'max_weight')?->value)->toBe(120.0);
});

/**
 * Les deux entrees publiques acceptent une serie issue d'une COLLECTION.
 *
 * C'est la seule forme ou Laravel arme la garde : `Builder::hydrate` ne pose
 * `preventsLazyLoading` que sur les modeles d'un resultat a plusieurs lignes
 * (`count($items) > 1`). Une serie prise dans un lot — une reparation qui
 * parcourt les series d'un utilisateur, une suppression en masse — refuse donc
 * toute relation qu'on n'a pas chargee, et le service doit se suffire de ce
 * qu'il declare charger.
 *
 * @return \App\Models\Set La serie voulue, hydratee dans un lot.
 */
function serieDansUnLot(Set $voulue, Set $voisine): Set
{
    $lot = Set::query()->whereIn('id', [$voulue->id, $voisine->id])->get();

    return $lot->firstOrFail(fn (Set $serie): bool => $serie->id === $voulue->id);
}

it('synchronise une série prise dans une collection', function (): void {
    [$user, , $ligne] = scenePourProvenance();
    $serie = serieMuette($ligne, 80, 5);
    $voisine = serieMuette($ligne, 20, 5);

    app(PersonalRecordService::class)->syncSetPRs(serieDansUnLot($serie, $voisine));

    expect((float) recordProvenance($user, 'max_weight')?->value)->toBe(80.0);
});

it('reconstruit depuis une série prise dans une collection', function (): void {
    [$user, , $ligne] = scenePourProvenance();
    $serie = serieMuette($ligne, 90, 5);
    $voisine = serieMuette($ligne, 20, 5);

    app(PersonalRecordService::class)->refreshFor(serieDansUnLot($serie, $voisine));

    expect((float) recordProvenance($user, 'max_weight')?->value)->toBe(90.0);
});

/**
 * Les preferences de notification sont lues une fois, pas une fois par record.
 *
 * Trois records sont evalues a chaque serie cochee, et chacun demande si
 * l'utilisateur veut etre prevenu. La question se pose sur une relation
 * chargee ou, a defaut, par une requete — donc sans chargement prealable, une
 * serie cochee coute trois lectures de plus, sur le chemin le plus frequent de
 * l'application.
 */
it('ne relit pas les préférences de notification une fois par record', function (): void {
    // L'envoi lui-meme relit le destinataire pour choisir ses canaux : on
    // l'ecarte pour ne compter que les lectures du service.
    Notification::fake();

    [$user, , $ligne] = scenePourProvenance();

    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'type' => 'personal_record',
        'is_enabled' => true,
    ]);

    $serie = serieMuette($ligne, 70, 5);

    $relue = Set::findOrFail($serie->id);
    $relu = User::findOrFail($user->id);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(PersonalRecordService::class)->syncSetPRs($relue, $relu);

    $requetes = array_map(fn (array $entree): string => (string) $entree['query'], DB::getQueryLog());
    DB::disableQueryLog();

    $lectures = array_filter(
        $requetes,
        fn (string $sql): bool => preg_match('/from `?notification_preferences`?/', $sql) === 1
    );

    expect($lectures)->toHaveCount(1)
        // Et les trois records ont bien ete evalues, chacun avec sa question.
        ->and(PersonalRecord::where('user_id', $user->id)->count())->toBe(3);

    Notification::assertSentToTimes($relu, PersonalRecordAchieved::class, 3);
});

/**
 * Un echauffement coche reste un echauffement.
 *
 * L'interface cree chaque serie decochee puis l'utilisateur la coche ; un
 * echauffement fait est donc coche comme les autres. Ce qui l'ecarte, c'est
 * `is_warmup`, seul et independamment du reste — sans quoi la barre a vide
 * d'un jour de forme deviendrait un record personnel.
 */
it('ne fait aucun record d’un échauffement, même coché', function (): void {
    [, , $ligne] = scenePourProvenance();

    app(PersonalRecordService::class)->syncSetPRs(serieMuette($ligne, 500, 5, echauffement: true));

    expect(PersonalRecord::count())->toBe(0);
});

/**
 * Un kilo est un poids. Zero n'en est pas un.
 *
 * La borne du travail en file doit ecarter « rien de saisi » et « zero », et
 * rien d'autre : un premier haltere a un kilo est un vrai record, et c'est
 * souvent le premier qu'on etablit.
 */
it('fait un record d’une série d’un kilo', function (): void {
    [$user, , $ligne] = scenePourProvenance();

    app(PersonalRecordService::class)->syncSetPRs(serieMuette($ligne, 1, 5));

    expect((float) recordProvenance($user, 'max_weight')?->value)->toBe(1.0);
});

/**
 * Le volume d'une serie est un produit, sur le chemin du travail en file aussi.
 *
 * Le fichier voisin verrouille deja ce chiffre, mais par un chemin ou
 * `refreshRecordsHeldBy` repasse derriere et reecrit tout : ce que le travail
 * en file ecrivait de travers y etait corrige avant qu'on puisse le lire. En
 * production le travail est mis en file et s'execute SEUL — ce qu'il ecrit est
 * ce que l'utilisateur voit.
 */
it('compte le volume d’une série comme un produit quand le travail passe seul', function (): void {
    [$user, , $ligne] = scenePourProvenance();

    app(PersonalRecordService::class)->syncSetPRs(serieMuette($ligne, 100, 10));

    expect((float) recordProvenance($user, 'max_volume_set')?->value)->toBe(1000.0)
        // 100 x (1 + 10/30), arrondi au centieme.
        ->and((float) recordProvenance($user, 'max_1rm')?->value)->toBe(133.33)
        // Le poids souleve, retenu sous le 1RM estime.
        ->and((float) recordProvenance($user, 'max_1rm')?->secondary_value)->toBe(100.0)
        // Les repetitions faites a ce poids, retenues sous le poids maximum.
        ->and((float) recordProvenance($user, 'max_weight')?->secondary_value)->toBe(10.0);
});
