<?php

declare(strict_types=1);

use App\Actions\Workouts\FetchWorkoutsIndexAction;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Une séance à une date posée, sans les écouteurs d'une séance : ce que ces tests lisent n'en dépend pas. */
function seancePourIndex(User $user, string $quand, string $nom = 'Seance', bool $faite = false): Workout
{
    $fabrique = $faite ? Workout::factory()->faite() : Workout::factory();

    return Workout::withoutEvents(fn (): Workout => $fabrique->create([
        'user_id' => $user->id,
        'name' => $nom,
        'started_at' => Carbon::parse($quand),
        'ended_at' => Carbon::parse($quand)->addHour(),
    ]));
}

it('pagine par vingt et precharge les lignes, leur exercice et leur compte de series', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-06-25 12:00:00'));
    $user = User::factory()->create();

    // Vingt-et-une seances : la vingt-et-unieme est celle qui rend la borne
    // visible. La plus recente porte les lignes, c'est donc elle qu'on retrouve
    // en tete (`latest('started_at')`).
    for ($jour = 1; $jour <= 20; $jour++) {
        seancePourIndex($user, sprintf('2026-06-%02d 10:00:00', $jour));
    }
    $derniere = seancePourIndex($user, '2026-06-21 10:00:00');
    $workoutLine = WorkoutLine::factory()->create(['workout_id' => $derniere->id]);
    Set::factory()->count(3)->create(['workout_line_id' => $workoutLine->id]);

    $workouts = app(FetchWorkoutsIndexAction::class)->execute($user)['workouts']();

    // Vingt par page, pas dix-neuf ni vingt-et-une.
    expect($workouts->perPage())->toBe(20);
    expect($workouts->total())->toBe(21);
    expect($workouts->items())->toHaveCount(20);

    $premiere = $workouts->items()[0];
    expect($premiere->id)->toBe($derniere->id);

    // Le prechargement, qui n'etait verifie nulle part : sans lui la page
    // affiche exactement la meme chose, en une requete par seance. C'est la
    // seule assertion qui distingue `with([...])` de `with([])`.
    expect($premiere->relationLoaded('workoutLines'))->toBeTrue();

    // `firstOrFail()` et non `first()` : la ligne DOIT etre la. Avec `first()`
    // une collection vide rendrait `null` et les deux assertions suivantes
    // planteraient sur une erreur de type au lieu de dire ce qui manque.
    $ligneChargee = $premiere->workoutLines->firstOrFail();
    expect($ligneChargee->relationLoaded('exercise'))->toBeTrue();

    // `withCount('sets')` : trois series posees, pas une quantite tiree.
    expect($ligneChargee->sets_count)->toBe(3);
});

it('départage par identifiant décroissant les séances qui commencent à la même seconde', function (): void {
    $user = User::factory()->create();
    Workout::factory()->count(22)->create(['user_id' => $user->id, 'started_at' => '2026-08-10 08:00:00']);

    DB::enableQueryLog();
    app(FetchWorkoutsIndexAction::class)->execute($user)['workouts']();
    $tris = collect(DB::getQueryLog())->map(fn (array $entree): string => $entree['query'])->filter(fn (string $sql): bool => str_contains($sql, 'from `workouts`') && str_contains($sql, 'order by'));

    expect($tris)->toHaveCount(1)
        ->and($tris->first())->toContain('order by `started_at` desc, `id` desc');
});
