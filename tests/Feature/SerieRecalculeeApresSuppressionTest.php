<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workout;
use App\Services\StreakService;
use Illuminate\Support\Carbon;

/*
 * #1460 : supprimer une séance ne recalculait rien.
 *
 * `last_workout_at` continuait de pointer une séance disparue — et c'est la
 * seule mémoire de `StreakService`. L'écart calculé à la séance SUIVANTE
 * partait donc d'une date fantôme, et cassait une série pourtant continue.
 */

function seanceLe(User $user, string $date): Workout
{
    return Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::parse($date.' 10:00:00'),
    ]);
}

it('fait reculer la dernière séance quand on supprime la plus récente', function (): void {
    $user = User::factory()->create();

    seanceLe($user, '2026-06-10');
    $laPlusRecente = seanceLe($user, '2026-06-11');

    expect($user->refresh()->last_workout_at?->toDateString())->toBe('2026-06-11');

    $laPlusRecente->delete();

    expect($user->refresh()->last_workout_at?->toDateString())->toBe('2026-06-10');
});

it('raccourcit la série quand la suppression brise la suite', function (): void {
    $user = User::factory()->create();

    // Trois jours consécutifs : la série vaut 3.
    seanceLe($user, '2026-06-09');
    $duMilieu = seanceLe($user, '2026-06-10');
    seanceLe($user, '2026-06-11');

    expect($user->refresh()->current_streak)->toBe(3);

    // Retirer le jour du milieu casse la suite : il ne reste que le 11.
    $duMilieu->delete();

    expect($user->refresh()->current_streak)->toBe(1)
        ->and($user->refresh()->longest_streak)->toBe(1);
});

it('remet tout à zéro quand il ne reste aucune séance', function (): void {
    $user = User::factory()->create();

    $seule = seanceLe($user, '2026-06-11');
    expect($user->refresh()->current_streak)->toBe(1);

    $seule->delete();

    $user->refresh();

    expect($user->last_workout_at)->toBeNull()
        ->and($user->current_streak)->toBe(0)
        ->and($user->longest_streak)->toBe(0);
});

it('ne compte qu’une fois deux séances le même jour', function (): void {
    $user = User::factory()->create();

    seanceLe($user, '2026-06-10');
    seanceLe($user, '2026-06-10');
    seanceLe($user, '2026-06-11');

    app(StreakService::class)->recalculerDepuisLesFaits($user);

    expect($user->refresh()->current_streak)->toBe(2);
});

/**
 * La serie EN COURS et la PLUS LONGUE sont deux choses, et le service les
 * confondait.
 *
 * La boucle remonte le temps depuis la seance la plus recente. Elle avancait
 * `$enCours` des qu'elle trouvait deux jours qui se suivent, sans retenir
 * qu'une rupture avait deja ete franchie plus tot : une vieille serie de trois
 * jours faisait donc remonter la serie « en cours » a trois, alors que la
 * derniere seance etait isolee.
 *
 * Ce n'etait pas theorique. `HandleInertiaRequests` et la ressource Filament
 * lisent tous deux `current_streak`, et `currentStreakFor()` ne corrige rien :
 * il remet a zero une serie PERIMEE de plus d'un jour, il ne revoit jamais un
 * chiffre trop grand a la baisse. Le compte affichait donc une serie qu'il
 * n'avait pas, des la premiere suppression de seance.
 */
it('ne fait pas passer une vieille serie pour la serie en cours', function (): void {
    $user = User::factory()->create();

    // Une serie de trois jours, il y a longtemps.
    seanceLe($user, '2026-06-01');
    seanceLe($user, '2026-06-02');
    seanceLe($user, '2026-06-03');

    // Puis une seance isolee, bien plus tard.
    seanceLe($user, '2026-06-20');

    app(StreakService::class)->recalculerDepuisLesFaits($user);

    expect($user->refresh()->current_streak)->toBe(1)
        ->and($user->longest_streak)->toBe(3);
});

/**
 * Le miroir du cas precedent : quand la serie en cours EST la plus longue, les
 * deux valeurs doivent coincider. Sans cette assertion, un correctif qui poserait
 * `current_streak` a 1 en toutes circonstances passerait le test ci-dessus.
 */
it('fait bien coincider les deux quand la serie en cours est la plus longue', function (): void {
    $user = User::factory()->create();

    seanceLe($user, '2026-06-01');
    seanceLe($user, '2026-06-10');
    seanceLe($user, '2026-06-11');
    seanceLe($user, '2026-06-12');

    app(StreakService::class)->recalculerDepuisLesFaits($user);

    expect($user->refresh()->current_streak)->toBe(3)
        ->and($user->longest_streak)->toBe(3);
});

/**
 * Un record ramene a une seule journee vaut un jour, pas zero.
 *
 * Le record se met a jour DANS la boucle, a chaque paire de jours examinee :
 * quand il ne reste qu'une journee, la boucle n'y passe pas et c'est la valeur
 * de depart qui est ecrite telle quelle. Les autres cas de ce fichier gardent
 * tous au moins deux journees, si bien qu'un depart a zero y serait rattrape
 * par le premier `max()` et ne se verrait pas.
 */
it('ramène le record à un jour quand il ne reste qu’une journée', function (): void {
    $user = User::factory()->create();

    $premier = seanceLe($user, '2026-06-09');
    $second = seanceLe($user, '2026-06-10');
    seanceLe($user, '2026-06-11');

    expect($user->refresh()->longest_streak)->toBe(3);

    $premier->delete();
    $second->delete();

    expect($user->refresh()->current_streak)->toBe(1)
        ->and($user->longest_streak)->toBe(1);
});

/**
 * Le doublon du jour se saute ; il n'arrete pas la lecture.
 *
 * La boucle remonte le temps, et deux seances du meme jour y sont adjacentes.
 * Quand le doublon porte sur le jour le PLUS RECENT, s'arreter au lieu de
 * passer au suivant abandonne tout l'historique anterieur : la serie de deux
 * jours retombe a un. Le cas ou le doublon est en fin de liste ne montre rien,
 * puisqu'il n'y a plus rien a lire apres lui.
 */
it('saute la seconde séance du jour sans cesser de remonter le temps', function (): void {
    $user = User::factory()->create();

    seanceLe($user, '2026-06-10');
    seanceLe($user, '2026-06-11');

    // La seconde du 11, saisie le soir : c'est elle qui ouvre la lecture.
    Workout::factory()->create([
        'user_id' => $user->id,
        'started_at' => Carbon::parse('2026-06-11 20:00:00'),
    ]);

    app(StreakService::class)->recalculerDepuisLesFaits($user);

    expect($user->refresh()->current_streak)->toBe(2)
        ->and($user->longest_streak)->toBe(2);
});
