<?php

declare(strict_types=1);

use App\Models\NotificationPreference;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutLine;
use App\Services\ActiveWorkoutService;
use App\Support\HeureDeLAppareil;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Inertia\Support\SessionKey;
use Inertia\Testing\AssertableInertia as Assert;
use NotificationChannels\WebPush\PushSubscription;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;

/*
 * Terminer une séance par l'API, que la file hors ligne rejoue (#2049).
 *
 * « Terminer » met la fin en file, avec l'heure du geste sur l'appareil, et
 * l'heure de l'appareil au départ (`X-Heure-De-L-Appareil`, un appareil à
 * l'heure dans ces cas : midi à Paris, 10 h en UTC) : la fin arrive des
 * heures plus tard, ou deux fois quand la réponse s'est perdue. La séance se nomme dans le corps (une séance encore en file n'a pas
 * d'identifiant à mettre dans l'adresse). Le serveur borne l'heure, garde la
 * première fin d'une séance déjà close, et autorise par `view` : la policy
 * refuse de modifier une séance close, et un rejeu recevrait 403.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-19 12:00:00');
});

/**
 * La séance en cours du compte, commencée à 11 h.
 */
function finApiSeance(User $compte): Workout
{
    return Workout::factory()->create(['user_id' => $compte->id, 'started_at' => '2026-10-19 11:00:00', 'ended_at' => null]);
}

/**
 * Termine une séance par l'API, l'heure de l'appareil au départ dans son
 * en-tête quand elle est donnée.
 *
 * @param  array<string, mixed>  $corps
 * @return TestResponse<Response>
 */
function finApi(User $compte, array $corps, ?string $heureAuDepart = null): TestResponse
{
    return actingAs($compte)->patchJson(
        route('api.v1.workouts.finish'),
        $corps,
        $heureAuDepart === null ? [] : [HeureDeLAppareil::ENTETE => $heureAuDepart],
    );
}

/**
 * L'heure d'un appareil à l'heure, au départ de la requête : midi à Paris.
 */
function finApiDepart(): string
{
    return '2026-10-19T10:00:00.000Z';
}

dataset('fins venues de l’appareil', [
    'à l’heure du geste, en UTC' => ['2026-10-19T09:30:00Z', '2026-10-19 11:30:00'],
    'après le départ de la requête' => ['2026-10-19T10:00:01Z', '2026-10-19 12:00:00'],
    'avant le début' => ['2026-10-19T08:59:59Z', '2026-10-19 11:00:00'],
]);

it('termine la séance à l’heure du geste, bornée entre le début et maintenant', function (string $fin, string $attendue): void {
    $compte = User::factory()->create();
    $seance = finApiSeance($compte);

    $reponse = finApi($compte, ['workout_id' => $seance->id, 'ended_at' => $fin], finApiDepart())->assertOk();

    expect($seance->refresh()->ended_at?->format('Y-m-d H:i:s'))->toBe($attendue)
        ->and($reponse->json('data.id'))->toBe($seance->id)
        ->and($reponse->json('data.ended_at'))->toBe($seance->ended_at?->toJSON());
})->with('fins venues de l’appareil');

it('termine la séance maintenant sans heure de l’appareil', function (): void {
    $compte = User::factory()->create();
    $seance = finApiSeance($compte);

    finApi($compte, ['workout_id' => $seance->id])->assertOk();

    expect($seance->refresh()->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-19 12:00:00');
});

/*
 * L'instant du geste sans l'heure de l'appareil au départ ne se compare à
 * rien : maintenant (relu le 2026-10-21 : le serveur prenait l'instant).
 */
it('termine la séance maintenant quand l’heure du geste vient sans l’heure de l’appareil au départ', function (): void {
    $compte = User::factory()->create();
    $seance = finApiSeance($compte);

    finApi($compte, ['workout_id' => $seance->id, 'ended_at' => '2026-10-19T09:30:00Z'])->assertOk();

    expect($seance->refresh()->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-19 12:00:00');
});

/*
 * La fin rejouée sur une séance déjà close : la même réponse, et la première
 * fin reste, même quand le rejeu porte une autre heure.
 */
it('garde la première fin d’une séance déjà close au rejeu', function (): void {
    $compte = User::factory()->create();
    $seance = finApiSeance($compte);
    finApi($compte, ['workout_id' => $seance->id, 'ended_at' => '2026-10-19T09:30:00Z'], finApiDepart())->assertOk();

    finApi($compte, ['workout_id' => $seance->id, 'ended_at' => '2026-10-19T09:45:00Z'], finApiDepart())
        ->assertOk()
        ->assertJsonPath('data.id', $seance->id);

    expect($seance->refresh()->ended_at?->format('Y-m-d H:i:s'))->toBe('2026-10-19 11:30:00');
});

/*
 * La séance d'un autre compte et une séance qui n'existe pas reçoivent la
 * même réponse, entière : rien ne dit laquelle existe.
 */
it('refuse pareil la séance d’un autre compte et une séance absente', function (): void {
    $compte = User::factory()->create();
    $deLAutre = finApiSeance(User::factory()->create());

    $dUnAutre = finApi($compte, ['workout_id' => $deLAutre->id])->assertUnprocessable();
    $absente = finApi($compte, ['workout_id' => $deLAutre->id + 1000])->assertUnprocessable();

    expect($dUnAutre->json())->toBe($absente->json())
        ->and($dUnAutre->json('errors.workout_id.0'))->toBe('Le champ séance sélectionné est invalide.')
        ->and($deLAutre->refresh()->ended_at)->toBeNull();
});

/*
 * Une fin dont la séance attend encore sa création dans la file part avec
 * `{ enAttenteDe }` à la place de l'identifiant tant que la file ne l'a pas
 * remplacé : un refus sur le champ, jamais une erreur 500.
 */
it('refuse sur le champ une séance qui n’est pas un identifiant', function (mixed $reference): void {
    Exceptions::fake();
    $compte = User::factory()->create();

    finApi($compte, ['workout_id' => $reference])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('workout_id');

    Exceptions::assertNothingReported();
})->with([
    'référence en attente' => [['enAttenteDe' => 'q-12']],
    'liste imbriquée' => [[[1]]],
    'texte' => ['douze'],
]);

/*
 * L'invitation aux notifications part dans `meta`, comme la fin par la page
 * la pose en donnée flash, et seulement pour le compte qui n'a aucun
 * abonnement et n'a pas coupé les records. Rien en session : un rejeu de la
 * file la ferait surgir sur une page au hasard.
 */
it('dit dans meta s’il faut proposer les notifications, sans rien poser en session', function (): void {
    $compte = User::factory()->create();

    finApi($compte, ['workout_id' => finApiSeance($compte)->id])
        ->assertOk()
        ->assertJsonPath('meta.proposer_les_notifications', true)
        ->assertInertiaFlashMissing('proposerLesNotifications')
        ->assertSessionMissing(SessionKey::FLASH_DATA);
});

it('ne propose pas les notifications au compte abonné ni à celui qui a coupé les records', function (Closure $preparer): void {
    $compte = User::factory()->create();
    $preparer($compte);

    finApi($compte, ['workout_id' => finApiSeance($compte)->id])
        ->assertOk()
        ->assertJsonPath('meta.proposer_les_notifications', false);
})->with([
    'abonné' => [fn (User $compte): PushSubscription => $compte->updatePushSubscription('https://push.example.com/telephone', 'cle', 'jeton')],
    'records coupés' => [fn (User $compte) => NotificationPreference::factory()->create([
        'user_id' => $compte->id,
        'type' => 'personal_record',
        'is_enabled' => false,
        'is_push_enabled' => false,
    ])],
]);

it('vide les statistiques des séances à la fin', function (): void {
    $compte = User::factory()->create();
    $seance = finApiSeance($compte);
    \Illuminate\Support\Facades\Cache::put(\App\Services\Stats\ClesDeStats::seances($compte, 'duration_history.20'), ['périmé'], 600);

    finApi($compte, ['workout_id' => $seance->id])->assertOk();

    expect(\Illuminate\Support\Facades\Cache::has(\App\Services\Stats\ClesDeStats::seances($compte, 'duration_history.20')))->toBeFalse();
});

/*
 * La séance close n'est plus la séance en cours : le bandeau de la page
 * suivante ne la montre plus, le cache de deux heures oublié.
 */
it('retire la séance close de la séance en cours de la page suivante', function (): void {
    $compte = User::factory()->create();
    $seance = finApiSeance($compte);
    expect(app(ActiveWorkoutService::class)->for($compte)?->id)->toBe($seance->id);

    finApi($compte, ['workout_id' => $seance->id])->assertOk();

    actingAs($compte)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page): Assert => $page->where('auth.user.active_workout', null));
});

/*
 * Une série qui arrive après la fin reçoit le refus d'une séance close, comme
 * avant : la fin en file passe après les écritures de la séance.
 */
it('refuse ensuite une série sur la séance close, comme avant', function (): void {
    $compte = User::factory()->create();
    $seance = finApiSeance($compte);
    $ligne = WorkoutLine::factory()->create(['workout_id' => $seance->id]);
    finApi($compte, ['workout_id' => $seance->id])->assertOk();

    actingAs($compte)
        ->postJson(route('api.v1.sets.store'), ['workout_line_id' => $ligne->id, 'reps' => 5, 'weight' => 60])
        ->assertForbidden();

    expect($ligne->sets()->count())->toBe(0);
});
