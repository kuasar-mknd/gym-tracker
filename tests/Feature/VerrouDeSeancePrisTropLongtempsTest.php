<?php

declare(strict_types=1);

use App\Actions\Workouts\DemarrerUneSeanceAction;
use App\Exceptions\LeVerrouDesSeancesEstPris;
use App\Models\User;
use App\Models\Workout;
use App\Models\WorkoutTemplate;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

function messageDuVerrouDesSeances(): string
{
    return 'Une autre requête met à jour tes séances. Réessaie dans un instant.';
}

function unCompteDontLeVerrouDesSeancesEstPris(): User
{
    $user = User::factory()->create();
    Cache::lock("seances-du-compte:{$user->id}", 60, 'un-autre-processus')->get();
    Sleep::fake(syncWithCarbon: true);

    return $user;
}

it('répond 503 rejouable, avec Retry-After, au démarrage par l\'API', function (): void {
    Sanctum::actingAs(unCompteDontLeVerrouDesSeancesEstPris());

    postJson(route('api.v1.workouts.store'))
        ->assertStatus(503)
        ->assertHeader('Retry-After', '2')
        ->assertJsonPath('message', messageDuVerrouDesSeances());

    $this->assertDatabaseCount('workouts', 0);
});

it('répond 503 rejouable à la fin par l\'API', function (): void {
    $user = unCompteDontLeVerrouDesSeancesEstPris();
    $seance = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);

    Sanctum::actingAs($user);

    patchJson(route('api.v1.workouts.finish'), ['workout_id' => $seance->id])
        ->assertStatus(503)
        ->assertHeader('Retry-After', '2');

    expect($seance->refresh()->ended_at)->toBeNull();
});

it('garde la page et dit l\'échec au démarrage web Inertia', function (): void {
    actingAs(unCompteDontLeVerrouDesSeancesEstPris())
        ->from(route('dashboard'))
        ->post(route('workouts.store'), [], ['X-Inertia' => 'true'])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors(['base' => messageDuVerrouDesSeances()])
        ->assertSessionHas('error', messageDuVerrouDesSeances());

    $this->assertDatabaseCount('workouts', 0);
});

it('garde la page et dit l\'échec à la fin web (PATCH workouts.update)', function (): void {
    $user = unCompteDontLeVerrouDesSeancesEstPris();
    $seance = Workout::factory()->create(['user_id' => $user->id, 'ended_at' => null]);

    actingAs($user)
        ->from(route('dashboard'))
        ->patch(route('workouts.update', $seance), ['is_finished' => true], ['X-Inertia' => 'true'])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors(['base' => messageDuVerrouDesSeances()]);

    expect($seance->refresh()->ended_at)->toBeNull();
});

it('garde la page et dit l\'échec au lancement d\'un modèle (templates.execute)', function (): void {
    $user = unCompteDontLeVerrouDesSeancesEstPris();
    $modele = WorkoutTemplate::factory()->create(['user_id' => $user->id]);

    actingAs($user)
        ->from(route('dashboard'))
        ->post(route('templates.execute', $modele), [], ['X-Inertia' => 'true'])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors(['base' => messageDuVerrouDesSeances()]);

    $this->assertDatabaseCount('workouts', 0);
});

it('répond le 503 JSON, pas une redirection, à un appel JSON sur une route web', function (): void {
    $user = unCompteDontLeVerrouDesSeancesEstPris();

    actingAs($user)
        ->post(route('workouts.store'), [], ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'])
        ->assertStatus(503)
        ->assertHeader('Retry-After', '2')
        ->assertJsonPath('message', messageDuVerrouDesSeances());
});

it('ne rend en 503 que le verrou des séances, pas un autre verrou', function (): void {
    Route::middleware('web')->get('/_test/verrou-des-records', function (): never {
        throw new LockTimeoutException();
    });
    Route::middleware('web')->get('/_test/verrou-des-seances', function (): never {
        throw new LeVerrouDesSeancesEstPris();
    });

    $this->get('/_test/verrou-des-records', ['Accept' => 'application/json'])->assertStatus(500);
    $this->get('/_test/verrou-des-seances', ['Accept' => 'application/json'])->assertStatus(503);
});

it('rend le verrou quand la section lève, pour la requête suivante', function (): void {
    $user = User::factory()->create();

    expect(fn (): mixed => DemarrerUneSeanceAction::sousLeVerrouDuCompte($user->id, function (): never {
        throw new RuntimeException('la section échoue');
    }))->toThrow(RuntimeException::class, 'la section échoue');

    Sleep::fake(syncWithCarbon: true);

    expect(DemarrerUneSeanceAction::sousLeVerrouDuCompte($user->id, fn (): string => 'reprise'))->toBe('reprise');
});

it('tient le verrou pendant la section et le rend quand elle réussit', function (): void {
    $user = User::factory()->create();
    Sleep::fake(syncWithCarbon: true);

    $pris = DemarrerUneSeanceAction::sousLeVerrouDuCompte(
        $user->id,
        fn (): bool => (bool) Cache::lock("seances-du-compte:{$user->id}", 5)->get(),
    );

    expect($pris)->toBeFalse();
    expect(Cache::lock("seances-du-compte:{$user->id}", 5)->get())->toBeTrue();
});

it('rapporte la prise manquée du verrou en rendant le 503', function (): void {
    Exceptions::fake();

    Sanctum::actingAs(unCompteDontLeVerrouDesSeancesEstPris());

    postJson(route('api.v1.workouts.store'))->assertStatus(503);

    Exceptions::assertReported(LeVerrouDesSeancesEstPris::class);
});
