<?php

declare(strict_types=1);

use App\Http\Middleware\VerifieLeCompteDeLEcriture;
use App\Models\Set;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

/*
 * La file hors ligne envoie avec chaque écriture rejouée le compte qui l'a
 * faite (#1964). L'onglet qui vide la file ne sait pas toujours quelle session
 * l'accompagne : un autre onglet, ou la PWA, qui partagent les cookies, ont pu
 * ouvrir celle d'un autre compte. Le serveur refuse donc, sans rien exécuter,
 * une écriture dont le compte n'est pas celui de la session.
 */

/** Une ligne de séance en cours appartenant à ce compte, où il peut écrire des séries. */
function ligneEnCoursDuCompte(User $compte): WorkoutLine
{
    $seance = Workout::factory()->create(['user_id' => $compte->id, 'ended_at' => null]);

    return WorkoutLine::factory()->create(['workout_id' => $seance->id]);
}

/**
 * L'en-tête que le vidage de la file pose sur une écriture rejouée.
 *
 * @return array<string, string>
 */
function enteteDuCompteDeLEcriture(User $compte): array
{
    return [VerifieLeCompteDeLEcriture::ENTETE => (string) $compte->id];
}

/**
 * Le refus d'une écriture faite pour un autre compte, tel que la file le reconnaît.
 *
 * @param  TestResponse<Response>  $reponse
 */
function assertRefusPourUnAutreCompte(TestResponse $reponse): void
{
    $reponse->assertStatus(409)->assertJson(['raison' => VerifieLeCompteDeLEcriture::RAISON]);
}

it('refuse sans l’appliquer la modification de préférences d’un autre compte rejouée sous une session', function (): void {
    $compteA = User::factory()->create();
    $compteB = User::factory()->create();

    actingAs($compteB);

    assertRefusPourUnAutreCompte(patchJson(
        route('profile.preferences.update'),
        ['preferences' => ['personal_record' => false, 'training_reminder' => false], 'push_preferences' => ['personal_record' => false]],
        enteteDuCompteDeLEcriture($compteA),
    ));

    assertDatabaseMissing('notification_preferences', ['user_id' => $compteB->id]);
    assertDatabaseMissing('notification_preferences', ['user_id' => $compteA->id]);
});

it('laisse passer l’écriture rejouée pour le compte de la session, et celle qui ne vient pas de la file', function (): void {
    $compte = User::factory()->create();

    actingAs($compte);

    patchJson(
        route('profile.preferences.update'),
        ['preferences' => ['personal_record' => false], 'push_preferences' => ['personal_record' => false]],
        enteteDuCompteDeLEcriture($compte),
    )->assertNoContent();

    assertDatabaseHas('notification_preferences', [
        'user_id' => $compte->id,
        'type' => 'personal_record',
        'is_enabled' => false,
    ]);

    patchJson(route('profile.preferences.update'), [
        'preferences' => ['training_reminder' => false],
        'push_preferences' => ['training_reminder' => false],
    ])->assertNoContent();

    assertDatabaseHas('notification_preferences', [
        'user_id' => $compte->id,
        'type' => 'training_reminder',
        'is_enabled' => false,
    ]);
});

it('refuse la création, la modification et la suppression d’une série rejouées pour un autre compte, même sur les séries de la session', function (): void {
    $compteA = User::factory()->create();
    $compteB = User::factory()->create();
    $ligneDeB = ligneEnCoursDuCompte($compteB);
    $serieDeB = Set::factory()->create(['workout_line_id' => $ligneDeB->id, 'reps' => 5, 'is_completed' => false]);

    actingAs($compteB);

    assertRefusPourUnAutreCompte(postJson(
        route('api.v1.sets.store'),
        ['workout_line_id' => $ligneDeB->id, 'weight' => 80, 'reps' => 3, 'is_completed' => true],
        enteteDuCompteDeLEcriture($compteA),
    ));

    assertRefusPourUnAutreCompte(patchJson(
        route('api.v1.sets.update', ['set' => $serieDeB->id]),
        ['reps' => 3, 'is_completed' => true],
        enteteDuCompteDeLEcriture($compteA),
    ));

    assertRefusPourUnAutreCompte(deleteJson(
        route('api.v1.sets.destroy', ['set' => $serieDeB->id]),
        [],
        enteteDuCompteDeLEcriture($compteA),
    ));

    expect(Set::query()->where('workout_line_id', $ligneDeB->id)->get(['id', 'reps', 'is_completed'])->toArray())
        ->toBe([['id' => $serieDeB->id, 'reps' => 5, 'is_completed' => false]]);
});

it('répond pareil pour la série d’un autre compte et pour une série qui n’existe pas', function (): void {
    $compteA = User::factory()->create();
    $compteB = User::factory()->create();
    $serieDeA = Set::factory()->create(['workout_line_id' => ligneEnCoursDuCompte($compteA)->id]);

    actingAs($compteB);

    $existante = patchJson(
        route('api.v1.sets.update', ['set' => $serieDeA->id]),
        ['reps' => 1],
        enteteDuCompteDeLEcriture($compteA),
    );
    $absente = patchJson(
        route('api.v1.sets.update', ['set' => $serieDeA->id + 1000]),
        ['reps' => 1],
        enteteDuCompteDeLEcriture($compteA),
    );

    assertRefusPourUnAutreCompte($existante);
    expect($absente->getStatusCode())->toBe($existante->getStatusCode())
        ->and($absente->getContent())->toBe($existante->getContent());
});

it('laisse une session expirée répondre 401, pour que la page invite à se reconnecter', function (): void {
    $compte = User::factory()->create();
    $ligne = ligneEnCoursDuCompte($compte);

    postJson(
        route('api.v1.sets.store'),
        ['workout_line_id' => $ligne->id, 'weight' => 80, 'reps' => 3],
        enteteDuCompteDeLEcriture($compte),
    )->assertUnauthorized();

    patchJson(
        route('profile.preferences.update'),
        ['preferences' => ['personal_record' => false], 'push_preferences' => ['personal_record' => false]],
        enteteDuCompteDeLEcriture($compte),
    )->assertUnauthorized();

    assertDatabaseMissing('sets', ['workout_line_id' => $ligne->id]);
    assertDatabaseMissing('notification_preferences', ['user_id' => $compte->id]);
});
