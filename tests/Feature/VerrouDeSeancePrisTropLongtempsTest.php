<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

function unCompteDontLeVerrouDesSeancesEstPris(): User
{
    $user = User::factory()->create();
    Cache::lock("seances-du-compte:{$user->id}", 60, 'un-autre-processus')->get();
    Sleep::fake(syncWithCarbon: true);

    return $user;
}

it('répond 503 rejouable, avec Retry-After, quand le verrou reste pris (API)', function (): void {
    Sanctum::actingAs(unCompteDontLeVerrouDesSeancesEstPris());

    postJson(route('api.v1.workouts.store'))
        ->assertStatus(503)
        ->assertHeader('Retry-After', '2')
        ->assertJsonPath('message', 'Une autre requête met à jour tes séances. Réessaie dans un instant.');

    $this->assertDatabaseCount('workouts', 0);
});

it('garde la page et flashe le message quand le verrou reste pris (web Inertia)', function (): void {
    actingAs(unCompteDontLeVerrouDesSeancesEstPris())
        ->from(route('dashboard'))
        ->post(route('workouts.store'), [], ['X-Inertia' => 'true'])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('error', 'Une autre requête met à jour tes séances. Réessaie dans un instant.');

    $this->assertDatabaseCount('workouts', 0);
});
