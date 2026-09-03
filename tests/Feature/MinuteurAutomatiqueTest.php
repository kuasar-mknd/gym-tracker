<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

/*
 * Valider une serie ouvrait toujours le minuteur. `auto_rest_timer` permet de
 * s'en passer, et ne vaut que s'il ATTEINT le client : c'est exactement ainsi
 * que `default_rest_time` a ete perdu — colonne lue nulle part, repli applique
 * en silence, reglage sans effet.
 */
/** @return array<string, mixed> */
function partagesDuMinuteur(User $utilisateur): array
{
    $requete = Request::create('/dashboard');
    $requete->setUserResolver(fn (): User => $utilisateur);

    return new HandleInertiaRequests()->share($requete);
}

it('demarre automatiquement par defaut', function (): void {
    // Depuis la BASE : Eloquent ne relit pas les valeurs par defaut apres
    // l insertion, donc l instance en memoire ne prouverait rien.
    expect(User::factory()->create()->refresh()->auto_rest_timer)->toBeTrue();
});

it('envoie le reglage au client', function (): void {
    $utilisateur = User::factory()->create(['auto_rest_timer' => false]);

    // La CLE autant que la valeur : `data_get` rend `null` pour un champ absent
    // comme pour un champ faux, et `false` n'est pas distinguable de rien.
    expect(data_get(partagesDuMinuteur($utilisateur), 'auth.user'))->toHaveKey('auto_rest_timer')
        ->and(data_get(partagesDuMinuteur($utilisateur), 'auth.user.auto_rest_timer'))->toBeFalse();
});

it('expose les deux reglages du minuteur au client mobile', function (): void {
    $utilisateur = User::factory()->create(['auto_rest_timer' => false, 'default_rest_time' => 150]);

    $rendu = new UserResource($utilisateur)->toArray(Request::create('/'));

    // Les deux ensemble : un client qui recoit la duree sans le declencheur, ou
    // l'inverse, ne peut pas rendre l'ecran.
    expect($rendu)->toHaveKey('auto_rest_timer')
        ->and($rendu['auto_rest_timer'])->toBeFalse()
        ->and($rendu)->toHaveKey('default_rest_time')
        ->and($rendu['default_rest_time'])->toBe(150);
});

it('bascule le reglage sans quitter la page', function (): void {
    $utilisateur = User::factory()->create(['auto_rest_timer' => true]);

    $reponse = $this->actingAs($utilisateur)
        ->from('/workouts/1')
        ->patch(route('profile.rest-timer.update'), ['auto_rest_timer' => false]);

    // En arriere, et non vers le profil : l'interrupteur vit dans le panneau du
    // minuteur, donc pendant une seance.
    $reponse->assertRedirect('/workouts/1');

    expect($utilisateur->refresh()->auto_rest_timer)->toBeFalse();
});

it('refuse une valeur qui n est pas un booleen', function (): void {
    $this->actingAs(User::factory()->create())
        ->patchJson(route('profile.rest-timer.update'), ['auto_rest_timer' => 'peut-etre'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('auto_rest_timer');
});

it('refuse un visiteur', function (): void {
    $this->patch(route('profile.rest-timer.update'), ['auto_rest_timer' => false])
        ->assertRedirect(route('login'));
});
