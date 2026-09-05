<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

test('creating set creates max weight pr', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    $data = [
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 5,
    ];

    postJson(route('api.v1.sets.store'), $data)->assertCreated();

    assertDatabaseHas('personal_records', [
        'user_id' => $user->id,
        'exercise_id' => $exercise->id,
        'type' => 'max_weight',
        'value' => 100,
    ]);
});

test('creating set creates max volume pr', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    // Volume = 100 * 10 = 1000
    $data = [
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 10,
    ];

    postJson(route('api.v1.sets.store'), $data)->assertCreated();

    assertDatabaseHas('personal_records', [
        'user_id' => $user->id,
        'exercise_id' => $exercise->id,
        'type' => 'max_volume_set',
        'value' => 1000,
    ]);
});

test('updating set updates pr', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    $response = postJson(route('api.v1.sets.store'), [
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 5,
    ]);
    $setId = $response->json('data.id');

    // Update to higher weight
    putJson(route('api.v1.sets.update', $setId), [
        'weight' => 120,
        'reps' => 5,
    ])->assertOk();

    assertDatabaseHas('personal_records', [
        'user_id' => $user->id,
        'exercise_id' => $exercise->id,
        'type' => 'max_weight',
        'value' => 120,
    ]);
});

test('validation prevents invalid set creation and no pr created', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    // Invalid weight
    $data = [
        'workout_line_id' => $line->id,
        'weight' => 'invalid',
        'reps' => 10,
    ];

    postJson(route('api.v1.sets.store'), $data)->assertUnprocessable();

    assertDatabaseMissing('personal_records', [
        'user_id' => $user->id,
        'exercise_id' => $exercise->id,
    ]);
});

test('authorization prevents pr update on other users line', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    Sanctum::actingAs($user);

    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);
    $line = WorkoutLine::factory()->create([
        'workout_id' => $workout->id,
        'exercise_id' => $exercise->id,
    ]);

    $data = [
        'workout_line_id' => $line->id,
        'weight' => 200,
        'reps' => 10,
    ];

    postJson(route('api.v1.sets.store'), $data)->assertUnprocessable()->assertJsonValidationErrors(['workout_line_id']);

    assertDatabaseMissing('personal_records', [
        'exercise_id' => $exercise->id,
        'type' => 'max_weight',
        'value' => 200,
    ]);
});

/*
 * Le trophee de record se decide sur `personal_record` dans la reponse.
 *
 * `Show.vue` affiche le badge sur `v-if="set.personal_record"`, et la valeur vient
 * de la reponse du PATCH de la serie. Le contrat tient a un fil : `SetResource`
 * ecrit `new PersonalRecordResource($this->whenLoaded('personalRecord'))`, et
 * `JsonResource` n'implemente pas `PotentiallyMissing` — la cle n'est donc pas
 * retiree du tableau. J'ai cru que cela produisait un objet de champs nuls, donc
 * vrai en JavaScript, donc un trophee sur n'importe quelle serie validee. Mesure
 * faite : la reponse rend bien `null`, il n'y a pas de defaut.
 *
 * Ces deux tests restent parce que le contrat, lui, n'etait ecrit nulle part —
 * le template testait `set.personal_record || set.personalRecord`, ce qui dit
 * assez que personne n'etait sur de la forme recue. Ils tiennent les deux sens :
 * une serie sans record rend null, une serie qui en detient un rend l'objet.
 */

/**
 * Une serie qui detient un record, et une qui n'en detient aucun.
 *
 * La lourde gagne sur les trois mesures a la fois — poids, 1RM et volume — pour
 * que la legere n'en garde aucune. Sans cela la legere pourrait detenir le 1RM
 * avec un nombre de repetitions different, et le test ne dirait plus rien.
 *
 * @return array{0: User, 1: Set, 2: Set}
 */
function serieAvecEtSansRecord(): array
{
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create(['user_id' => $user->id, 'type' => 'strength']);
    $workout = Workout::factory()->create(['user_id' => $user->id]);
    $line = WorkoutLine::factory()->create(['workout_id' => $workout->id, 'exercise_id' => $exercise->id]);

    $lourde = Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 100,
        'reps' => 10,
        'is_warmup' => false,
    ]);

    $legere = Set::factory()->create([
        'workout_line_id' => $line->id,
        'weight' => 50,
        'reps' => 5,
        'is_warmup' => false,
    ]);

    return [$user, $lourde, $legere];
}

it('rend null pour une série qui ne détient aucun record', function (): void {
    [$user, $lourde, $legere] = serieAvecEtSansRecord();

    // La lourde detient bien les records : sans cela, la serie legere pourrait
    // rendre null pour la mauvaise raison.
    expect($lourde->fresh()?->personalRecord)->not->toBeNull();

    $reponse = $this->actingAs($user)
        ->patchJson(route('api.v1.sets.update', ['set' => $legere->id]), ['is_completed' => true]);

    $reponse->assertOk();

    expect($reponse->json('data.personal_record'))->toBeNull();
});

it('rend le record pour une série qui en détient un', function (): void {
    [$user, $lourde] = serieAvecEtSansRecord();

    $reponse = $this->actingAs($user)
        ->patchJson(route('api.v1.sets.update', ['set' => $lourde->id]), ['is_completed' => true]);

    $reponse->assertOk();

    expect($reponse->json('data.personal_record'))->toBeArray()
        ->and($reponse->json('data.personal_record.id'))->not->toBeNull();
});
