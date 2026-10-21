<?php

declare(strict_types=1);

use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Models\WorkoutTemplate;
use App\Support\IdentifiantsDesRoutes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

/*
 * Un paramètre de route qui désigne un modèle n'admet qu'un identifiant écrit
 * en chiffres (#2049).
 *
 * La liaison implicite cherche `where id = '12abc'`, et MySQL convertit la
 * chaîne en nombre : `/workouts/12abc`, `/workouts/012` ou
 * `/api/v1/sets/12x` ouvraient ou modifiaient la séance 12 et la série 12,
 * sous une autre adresse que la leur. Les copies hors ligne se rangent par
 * adresse et n'admettent que `[1-9][0-9]*` (resources/js/Utils/pagesGardees.js) :
 * une page servie sous l'autre forme ne se reconnaît plus. Le motif
 * d'`IdentifiantsDesRoutes`, posé sur chaque route une fois chargée, répond
 * 404 avant la base.
 */

dataset('adresses dont l’identifiant n’est pas un nombre', [
    'la séance, lettres après' => [fn (Workout $seance, Set $serie, WorkoutTemplate $modele): array => ['get', "/workouts/{$seance->id}abc"]],
    'la séance, zéro devant' => [fn (Workout $seance, Set $serie, WorkoutTemplate $modele): array => ['get', "/workouts/0{$seance->id}"]],
    'la série, par l’API' => [fn (Workout $seance, Set $serie, WorkoutTemplate $modele): array => ['patchJson', "/api/v1/sets/{$serie->id}x"]],
    'le modèle à modifier' => [fn (Workout $seance, Set $serie, WorkoutTemplate $modele): array => ['get', "/templates/{$modele->id}z/edit"]],
]);

it('répond 404 à une adresse dont l’identifiant n’est pas un nombre', function (Closure $adresse): void {
    $compte = User::factory()->create();
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'ended_at' => null]);
    $serie = Set::factory()->create(['workout_line_id' => WorkoutLine::factory()->create(['workout_id' => $seance->id])->id, 'reps' => 5]);
    $modele = WorkoutTemplate::factory()->create(['user_id' => $compte->id]);
    /** @var array{0: 'get'|'patchJson', 1: string} $requete */
    $requete = $adresse($seance, $serie, $modele);
    [$methode, $chemin] = $requete;

    $reponse = $methode === 'get'
        ? actingAs($compte)->get($chemin)
        : actingAs($compte)->patchJson($chemin, ['reps' => 8]);

    $reponse->assertNotFound();

    expect($serie->refresh()->reps)->toBe(5);
})->with('adresses dont l’identifiant n’est pas un nombre');

/*
 * Toute route de l'application dont l'action reçoit un modèle par la liaison
 * implicite porte le motif sur ce paramètre : une route ajoutée plus tard, ou
 * un paramètre renommé, échoue ici jusqu'à ce que son nom entre dans la liste.
 * Les routes des paquets (le panneau, ses exports et ses imports) suivent
 * leurs propres règles, et ne sont pas lues.
 */
it('pose le motif numérique sur chaque paramètre de route lié à un modèle', function (): void {
    $sansMotif = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->getActionName(), 'App\\')) {
            continue;
        }

        foreach ($route->signatureParameters() as $parametre) {
            assert($parametre instanceof ReflectionParameter);
            $type = $parametre->getType();

            if (! $type instanceof ReflectionNamedType || $type->isBuiltin() || ! is_a($type->getName(), Model::class, true)) {
                continue;
            }

            $noms = array_values(array_filter($route->parameterNames(), is_string(...)));
            $nom = array_find($noms, fn (string $nomDansLAdresse): bool => in_array($nomDansLAdresse, [$parametre->getName(), Str::snake($parametre->getName())], true));

            if ($nom === null) {
                continue;
            }

            if (($route->wheres[$nom] ?? null) !== IdentifiantsDesRoutes::MOTIF) {
                $sansMotif[] = "{$route->uri()} ({$nom})";
            }
        }
    }

    expect($sansMotif)->toBe([]);
});
