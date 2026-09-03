<?php

declare(strict_types=1);

use App\Models\Exercise;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\Workout;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

uses(RefreshDatabase::class);

// Happy Path Tests

test('user can list personal records', function (): void {
    $user = User::factory()->create();

    // Un exercice par record : un utilisateur ne peut avoir qu'UN record d'un
    // type donne sur un exercice donne, et la fabrique tire son type parmi
    // deux valeurs — trois records sur le meme exercice se percutaient.
    foreach (Exercise::factory()->count(3)->create() as $exercise) {
        PersonalRecord::factory()->create([
            'user_id' => $user->id,
            'exercise_id' => $exercise->id,
        ]);
    }

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.personal-records.index'))
        ->assertOk()
        ->assertJsonCount(3, 'data');
});

test('user can filter personal records by exercise', function (): void {
    $user = User::factory()->create();
    $exercise1 = Exercise::factory()->create();
    $exercise2 = Exercise::factory()->create();

    PersonalRecord::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercise1->id]);
    PersonalRecord::factory()->create(['user_id' => $user->id, 'exercise_id' => $exercise2->id]);

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.personal-records.index', ['exercise_id' => $exercise1->id]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.exercise.id', $exercise1->id);
});

test('user can create a personal record', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $user->id]);

    $data = [
        'exercise_id' => $exercise->id,
        'type' => '1RM',
        'value' => 100.5,
        'workout_id' => $workout->id,
        'achieved_at' => now()->toDateString(),
    ];

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.personal-records.store'), $data)
        ->assertCreated()
        ->assertJsonPath('data.value', '100.50')
        ->assertJsonPath('data.type', '1RM');

    assertDatabaseHas('personal_records', [
        'user_id' => $user->id,
        'exercise_id' => $exercise->id,
        'value' => 100.5,
    ]);
});

test('user can show a personal record', function (): void {
    $user = User::factory()->create();
    $pr = PersonalRecord::factory()->create(['user_id' => $user->id]);

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.personal-records.show', $pr))
        ->assertOk()
        ->assertJsonPath('data.id', $pr->id);
});

test('user can update a personal record', function (): void {
    $user = User::factory()->create();
    $pr = PersonalRecord::factory()->create(['user_id' => $user->id, 'value' => 100]);

    actingAs($user, 'sanctum')
        ->putJson(route('api.v1.personal-records.update', $pr), [
            'value' => 110.5,
        ])
        ->assertOk()
        ->assertJsonPath('data.value', '110.50');

    assertDatabaseHas('personal_records', [
        'id' => $pr->id,
        'value' => 110.5,
    ]);
});

test('user can delete a personal record', function (): void {
    $user = User::factory()->create();
    $pr = PersonalRecord::factory()->create(['user_id' => $user->id]);

    actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.personal-records.destroy', $pr))
        ->assertNoContent();

    assertDatabaseMissing('personal_records', ['id' => $pr->id]);
});

// Validation Tests

test('store requires mandatory fields', function (): void {
    $user = User::factory()->create();

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.personal-records.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['exercise_id', 'type', 'value', 'achieved_at']);
});

test('store validates exercise existence', function (): void {
    $user = User::factory()->create();

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.personal-records.store'), [
            'exercise_id' => 99999,
            'type' => '1RM',
            'value' => 100,
            'achieved_at' => now()->toDateString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['exercise_id']);
});

test('store validates numeric value', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.personal-records.store'), [
            'exercise_id' => $exercise->id,
            'type' => '1RM',
            'value' => 'not-a-number',
            'achieved_at' => now()->toDateString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['value']);
});

/**
 * Le type est un enum au niveau du modele : une valeur hors enumeration
 * passait la validation (`string|max:255`) puis cassait a la lecture du
 * record. Et rien ne bornait la valeur : 99 999 999 kg etait accepte, et
 * debloquait au passage les succes de poids.
 */
test('store refuse un type hors enumeration avec un 422', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.personal-records.store'), [
            'exercise_id' => $exercise->id,
            'type' => 'bogus',
            'value' => 100,
            'achieved_at' => now()->toDateString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type']);

    assertDatabaseMissing('personal_records', ['user_id' => $user->id]);
});

test('store borne la valeur et la valeur secondaire', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.personal-records.store'), [
            'exercise_id' => $exercise->id,
            'type' => 'max_weight',
            'value' => 99999999,
            'secondary_value' => -1,
            'achieved_at' => now()->toDateString(),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['value', 'secondary_value']);

    assertDatabaseMissing('personal_records', ['user_id' => $user->id]);
});

test('update refuse un type hors enumeration et une valeur hors borne', function (): void {
    $user = User::factory()->create();
    $pr = PersonalRecord::factory()->create(['user_id' => $user->id, 'value' => 100]);

    actingAs($user, 'sanctum')
        ->putJson(route('api.v1.personal-records.update', $pr), [
            'type' => 'bogus',
            'value' => 100001,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['type', 'value']);

    assertDatabaseHas('personal_records', ['id' => $pr->id, 'value' => 100]);
});

test('store validates date format', function (): void {
    $user = User::factory()->create();
    $exercise = Exercise::factory()->create();

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.personal-records.store'), [
            'exercise_id' => $exercise->id,
            'type' => '1RM',
            'value' => 100,
            'achieved_at' => 'not-a-date',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['achieved_at']);
});

test('update validates input types', function (): void {
    $user = User::factory()->create();
    $pr = PersonalRecord::factory()->create(['user_id' => $user->id]);

    actingAs($user, 'sanctum')
        ->putJson(route('api.v1.personal-records.update', $pr), [
            'value' => 'not-a-number',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['value']);
});

// Authorization Tests

test('guest cannot access endpoints', function (): void {
    $pr = PersonalRecord::factory()->create();

    $this->getJson(route('api.v1.personal-records.index'))->assertUnauthorized();
    $this->postJson(route('api.v1.personal-records.store'), [])->assertUnauthorized();
    $this->getJson(route('api.v1.personal-records.show', $pr))->assertUnauthorized();
    $this->putJson(route('api.v1.personal-records.update', $pr), [])->assertUnauthorized();
    $this->deleteJson(route('api.v1.personal-records.destroy', $pr))->assertUnauthorized();
});

test('user cannot view other user personal record', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $pr = PersonalRecord::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user, 'sanctum')
        ->getJson(route('api.v1.personal-records.show', $pr))
        ->assertNotFound();
});

test('user cannot update other user personal record', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $pr = PersonalRecord::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user, 'sanctum')
        ->putJson(route('api.v1.personal-records.update', $pr), [
            'value' => 200,
        ])
        ->assertNotFound();
});

test('user cannot delete other user personal record', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $pr = PersonalRecord::factory()->create(['user_id' => $otherUser->id]);

    actingAs($user, 'sanctum')
        ->deleteJson(route('api.v1.personal-records.destroy', $pr))
        ->assertNotFound();
});

test('user cannot link personal record to another users workout', function (): void {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();
    $exercise = Exercise::factory()->create();
    $workout = Workout::factory()->create(['user_id' => $otherUser->id]);

    $data = [
        'exercise_id' => $exercise->id,
        'type' => '1RM',
        'value' => 100.5,
        'workout_id' => $workout->id,
        'achieved_at' => now()->toDateString(),
    ];

    actingAs($user, 'sanctum')
        ->postJson(route('api.v1.personal-records.store'), $data)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['workout_id']);
});
