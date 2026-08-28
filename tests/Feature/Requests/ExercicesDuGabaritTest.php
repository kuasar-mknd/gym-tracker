<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Support\Facades\DB;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

/** @return list<string> */
function requetesSurExercices(callable $geste): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();

    $geste();

    $requetes = array_map(fn (array $e): string => (string) $e['query'], DB::getQueryLog());
    DB::disableQueryLog();

    return array_values(array_filter(
        $requetes,
        fn (string $sql): bool => str_contains($sql, 'from `exercises`') && str_contains($sql, 'user_id')
    ));
}

/**
 * `Rule::exists` sur `exercises.*.id` emettait UNE requete par element.
 *
 * `DatabasePresenceVerifier::getCount()` construit une requete neuve a chaque
 * appel, sans memoisation, et le chemin groupe n'est jamais emprunte quand le
 * joker eclate le tableau en attributs scalaires. Douze exercices dans un
 * gabarit = douze requetes identiques en forme, emises avant le controleur et
 * avant tout `authorize()`.
 */
it('ne pose qu’une requête, quel que soit le nombre d’exercices', function (): void {
    $user = User::factory()->create();
    $exercices = Exercise::factory()->count(12)->create(['user_id' => $user->id, 'type' => 'strength']);

    $charge = [
        'name' => 'Haut du corps',
        'exercises' => $exercices->map(fn (Exercise $e): array => ['id' => $e->id])->all(),
    ];

    $requetes = requetesSurExercices(function () use ($user, $charge): void {
        $this->actingAs($user)->postJson(route('api.v1.workout-templates.store'), $charge)->assertCreated();
    });

    expect($requetes)->toHaveCount(1);
});

it('refuse l’exercice d’un autre compte', function (): void {
    $user = User::factory()->create();
    $intrus = Exercise::factory()->create(['user_id' => User::factory()->create()->id, 'type' => 'strength']);
    $sien = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);

    $this->actingAs($user)
        ->postJson(route('api.v1.workout-templates.store'), [
            'name' => 'Mélange',
            'exercises' => [['id' => $sien->id], ['id' => $intrus->id]],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('exercises.1.id');
});

it('accepte un exercice du catalogue global', function (): void {
    $user = User::factory()->create();
    $global = Exercise::factory()->create(['user_id' => null, 'type' => 'strength']);

    $this->actingAs($user)
        ->postJson(route('api.v1.workout-templates.store'), [
            'name' => 'Catalogue',
            'exercises' => [['id' => $global->id]],
        ])
        ->assertCreated();
});

it('borne le nombre d’exercices d’un gabarit', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson(route('api.v1.workout-templates.store'), [
            'name' => 'Trop',
            'exercises' => array_fill(0, 51, ['id' => 1]),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('exercises');
});
