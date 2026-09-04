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

it('numérote la clef de cache à zéro tant qu’aucune série n’a invalidé les recommandations', function (): void {
    expect(RecommendedValuesService::cleDeCache(7, 3, 9))->toBe('recommended_values:7:v0:3:9');
});

it('reprend la version en cours dans la clef, quel que soit le type rendu par le cache', function (mixed $version): void {
    // Le magasin de cache ne garantit pas le type : Redis rend une chaine la ou
    // le tableau garde un entier. La clef, elle, doit rester la meme, sinon
    // deux chemins de lecture cherchent a deux endroits differents.
    Cache::put('recommended_values:version:7', $version);

    expect(RecommendedValuesService::cleDeCache(7, 3, 9))->toBe('recommended_values:7:v7:3:9');
})->with([
    'entier' => [7],
    'chaîne' => ['7'],
    'flottant' => [7.0],
    'chaîne décimale' => ['7.0'],
]);

it('retombe sur la version zéro quand le cache rend une version illisible', function (): void {
    Cache::put('recommended_values:version:7', 'corrompue');

    expect(RecommendedValuesService::cleDeCache(7, 3, 9))->toBe('recommended_values:7:v0:3:9');
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

it('recommande pour une ligne relue seule, sans sa séance', function (): void {
    [, , , $ligneDuJour] = historiquePourRecommandation(50.0);

    // Relue sans sa seance : c'est ce que tient la ressource qui serialise une
    // ligne. Le service ne peut donc pas supposer la relation deja chargee, il
    // doit aller la chercher.
    $relue = WorkoutLine::findOrFail($ligneDuJour->id);

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
