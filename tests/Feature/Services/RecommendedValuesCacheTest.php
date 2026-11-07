<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\RecommendedValuesService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Un exercice fait hier, trois series au poids demande, et la ligne du jour
 * qui attend sa recommandation.
 *
 * @return array{0: User, 1: Exercise, 2: WorkoutLine, 3: WorkoutLine} l'utilisateur, l'exercice,
 *                                                                     la ligne d'hier, la ligne du jour
 */
function historiquePourRecommandation(float $poids = 50.0): array
{
    $user = User::factory()->create();
    $exercice = Exercise::factory()->create();

    $hier = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()->subDay()]);
    $ligneHier = WorkoutLine::factory()->create(['workout_id' => $hier->id, 'exercise_id' => $exercice->id]);
    Set::factory()->count(3)->create(['workout_line_id' => $ligneHier->id, 'weight' => $poids, 'reps' => 8]);

    $aujourdhui = Workout::factory()->create(['user_id' => $user->id, 'started_at' => now()]);
    $ligneDuJour = WorkoutLine::factory()->create(['workout_id' => $aujourdhui->id, 'exercise_id' => $exercice->id]);

    return [$user, $exercice, $ligneHier, $ligneDuJour];
}

/**
 * Les requetes qui relisent l'historique pendant l'action.
 *
 * Seule `workout_lines` porte l'historique : la seance courante est relue par
 * son identifiant, ce qui ne depend pas du cache.
 *
 * @param  callable(): mixed  $action
 * @return list<string>
 */
function requetesSurLHistoriqueDesLignes(callable $action): array
{
    $lues = [];

    DB::listen(function (QueryExecuted $requete) use (&$lues): void {
        if (str_contains($requete->sql, 'workout_lines')) {
            $lues[] = $requete->sql;
        }
    });

    $action();

    return $lues;
}

/**
 * La version des recommandations du compte 7, telle que le cache la garde.
 */
function versionDesRecommandationsEnCache(): string
{
    $version = Cache::get('recommended_values:version:7');

    return is_string($version) ? $version : '';
}

it('tire une version neuve, un ULID, tant qu’aucune série n’a invalidé les recommandations, et la garde', function (): void {
    // Plus de version zéro : une version évincée repartait de zéro, et
    // retombait sur les entrées encore vivantes de ses numéros d'avant (#2151).
    $cle = RecommendedValuesService::cleDeCache(7, 3, 9);
    $version = versionDesRecommandationsEnCache();

    expect(Str::isUlid($version))->toBeTrue()
        ->and($cle)->toBe("recommended_values:7:v{$version}:3:9")
        ->and(RecommendedValuesService::cleDeCache(7, 3, 9))->toBe($cle);
});

it('ne reprend jamais dans la clef une version d’une release d’avant, quel que soit le type rendu par le cache', function (mixed $version): void {
    // L'ancien code numérotait les versions : Redis rend son compteur en
    // chaîne, là où le tableau des tests garde l'entier. Une fois évincé, ce
    // compteur repartait de zéro et remontait vers des numéros déjà servis :
    // seul un ULID tiré par l'application nomme désormais une clef (#2151).
    Cache::put('recommended_values:version:7', $version);

    $cle = RecommendedValuesService::cleDeCache(7, 3, 9);

    expect($cle)->not->toBe('recommended_values:7:v7:3:9')
        ->and(Str::isUlid(versionDesRecommandationsEnCache()))->toBeTrue()
        ->and($cle)->toBe('recommended_values:7:v'.versionDesRecommandationsEnCache().':3:9');
})->with([
    'entier' => [7],
    'chaîne' => ['7'],
    'flottant' => [7.0],
    'chaîne décimale' => ['7.0'],
]);

it('remplace une version illisible par une version neuve, jamais par zéro', function (): void {
    Cache::put('recommended_values:version:7', 'corrompue');

    $cle = RecommendedValuesService::cleDeCache(7, 3, 9);

    expect($cle)->not->toBe('recommended_values:7:v0:3:9')
        ->and(Str::isUlid(versionDesRecommandationsEnCache()))->toBeTrue()
        ->and($cle)->toBe('recommended_values:7:v'.versionDesRecommandationsEnCache().':3:9');
});

it('oublie la recommandation déjà servie dès qu’une série est enregistrée', function (): void {
    [, , $ligneHier, $ligneDuJour] = historiquePourRecommandation(50.0);
    $service = app(RecommendedValuesService::class);

    expect($service->getRecommendedValues($ligneDuJour)['weight'])->toBe(50.0);

    // L'utilisateur reprend la seance d'hier et y ajoute quatre series a 80 kg :
    // 80 devient le poids le plus frequent, et c'est celui qu'il doit revoir.
    Set::factory()->count(4)->create(['workout_line_id' => $ligneHier->id, 'weight' => 80.0, 'reps' => 8]);

    expect($service->getRecommendedValues($ligneDuJour)['weight'])->toBe(80.0);
});

it('sert la recommandation en cache pendant cinq minutes, puis la recalcule', function (): void {
    [, , $ligneHier, $ligneDuJour] = historiquePourRecommandation(50.0);
    $service = app(RecommendedValuesService::class);

    expect($service->getRecommendedValues($ligneDuJour)['weight'])->toBe(50.0);

    /*
     * Corrige directement en base : aucun modele n'est sauvegarde, donc rien
     * n'invalide le cache. Seule son echeance peut encore le faire tomber, ce
     * qui rend l'echeance observable.
     */
    DB::table('sets')->where('workout_line_id', $ligneHier->id)->update(['weight' => 80.0]);

    $this->travel(299)->seconds();
    expect($service->getRecommendedValues($ligneDuJour)['weight'])->toBe(50.0);

    $this->travel(1)->seconds();
    expect($service->getRecommendedValues($ligneDuJour)['weight'])->toBe(80.0);
});

it('complète une ligne venue d’une collection sans charger sa séance à la volée', function (): void {
    [, , $ligneHier, $ligneDuJour] = historiquePourRecommandation(50.0);

    // Hydratée avec d'autres, comme le fait une ressource qui sérialise une
    // liste, une ligne refuse tout chargement paresseux hors production : le
    // service doit demander la séance explicitement, pas la laisser venir.
    $relue = WorkoutLine::query()->whereKey([$ligneHier->id, $ligneDuJour->id])->get()->firstWhere('id', $ligneDuJour->id);
    assert($relue instanceof WorkoutLine);

    expect(app(RecommendedValuesService::class)->getRecommendedValues($relue)['weight'])->toBe(50.0);
});

it('ne relit aucun historique quand toutes les recommandations du lot sont en cache', function (): void {
    [$user, , , $ligneDuJour] = historiquePourRecommandation(50.0);
    $service = app(RecommendedValuesService::class);

    $service->batchRecommendedValues(new Collection([$ligneDuJour]), $user->id);

    $lues = requetesSurLHistoriqueDesLignes(
        fn (): array => $service->batchRecommendedValues(new Collection([$ligneDuJour]), $user->id)
    );

    expect($lues)->toBe([]);
});

it('sert le lot en cache pendant cinq minutes, puis le recalcule', function (): void {
    [$user, $exercice, $ligneHier, $ligneDuJour] = historiquePourRecommandation(50.0);
    $service = app(RecommendedValuesService::class);
    $lignes = new Collection([$ligneDuJour]);

    expect($service->batchRecommendedValues($lignes, $user->id)[$exercice->id]['weight'])->toBe(50.0);

    DB::table('sets')->where('workout_line_id', $ligneHier->id)->update(['weight' => 80.0]);

    $this->travel(299)->seconds();
    expect($service->batchRecommendedValues($lignes, $user->id)[$exercice->id]['weight'])->toBe(50.0);

    $this->travel(1)->seconds();
    expect($service->batchRecommendedValues($lignes, $user->id)[$exercice->id]['weight'])->toBe(80.0);
});

it('ne va pas en base pour une ligne qui ne porte aucune séance', function (): void {
    // `resolveWorkout()` écarte ce cas avant la requête : sans séance il n'y a
    // ni date de référence ni propriétaire, donc rien à chercher.
    $orpheline = WorkoutLine::factory()->make(['workout_id' => null]);

    $requetes = [];
    DB::listen(function (QueryExecuted $requete) use (&$requetes): void {
        $requetes[] = $requete->sql;
    });

    $valeurs = app(RecommendedValuesService::class)
        ->batchRecommendedValues(new Collection([$orpheline]), 1);

    expect($valeurs)->toBe([])
        ->and($requetes)->toBe([]);
});
