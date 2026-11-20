<?php

declare(strict_types=1);

use App\Models\BodyMeasurement;
use App\Models\BodyPartMeasurement;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

test('un invité est renvoyé à la connexion', function (): void {
    get(route('corps.index'))->assertRedirect(route('login'));
});

test('un compte sans mesure voit une page vide, sans erreur', function (): void {
    actingAs(User::factory()->create())
        ->get(route('corps.index'))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page): Assert => $page
                ->component('Corps/Index')
                ->where('latestWeight', null)
                ->where('weightChange', 0)
                ->where('bodyFat', null)
                ->has('latestMeasurements', 0)
                ->loadDeferredProps(
                    fn (Assert $page): Assert => $page
                        ->has('bodyStats.weightHistory', 0)
                        ->has('bodyStats.bodyFatHistory', 0)
                )
        );
});

test('la page réunit le poids, la masse grasse et les mensurations du compte', function (): void {
    $user = User::factory()->create();
    BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 80.4, 'body_fat' => 18.5, 'measured_at' => now()->subDay()]);
    BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 81.0, 'body_fat' => 19.0, 'measured_at' => now()->subDays(8)]);
    BodyPartMeasurement::factory()->create(['user_id' => $user->id, 'part' => 'Waist']);

    actingAs($user)
        ->get(route('corps.index'))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page): Assert => $page
                ->component('Corps/Index')
                ->where('latestWeight', '80.40')
                ->where('weightChange', -0.6)
                ->where('bodyFat', '18.50')
                ->has('latestMeasurements', 1)
                ->loadDeferredProps(
                    fn (Assert $page): Assert => $page
                        ->has('bodyStats.weightHistory', 2)
                        ->has('bodyStats.bodyFatHistory', 2)
                )
        );
});

test('la page ne montre rien du compte d’un autre', function (): void {
    $autre = User::factory()->create();
    BodyMeasurement::factory()->create(['user_id' => $autre->id, 'weight' => 99.9, 'measured_at' => now()->subDay()]);
    BodyPartMeasurement::factory()->create(['user_id' => $autre->id]);

    actingAs(User::factory()->create())
        ->get(route('corps.index'))
        ->assertOk()
        ->assertInertia(
            fn (Assert $page): Assert => $page
                ->where('latestWeight', null)
                ->has('latestMeasurements', 0)
                ->loadDeferredProps(fn (Assert $page): Assert => $page->has('bodyStats.weightHistory', 0)->has('bodyStats.bodyFatHistory', 0))
        );
});

test('l’ancienne adresse des statistiques ne rend plus aucun chiffre de poids', function (): void {
    $user = User::factory()->create();
    BodyMeasurement::factory()->create(['user_id' => $user->id, 'weight' => 80.4, 'body_fat' => 18.5, 'measured_at' => now()->subDay()]);

    $reponse = actingAs($user)->get(route('stats.index'))->assertOk();

    $contenu = (string) $reponse->getContent();

    expect($contenu)->not->toContain('80.40');
    expect($contenu)->not->toContain('latestWeight');
});
