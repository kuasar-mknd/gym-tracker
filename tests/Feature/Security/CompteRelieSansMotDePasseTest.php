<?php

declare(strict_types=1);

use App\Actions\ResolveSocialUserAction;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Socialite\Two\User as SocialiteUser;

/*
 * La connexion sociale crée le compte avec un mot de passe tiré au hasard,
 * que personne ne connaît (`ResolveSocialUserAction`). Supprimer le compte et
 * changer le mot de passe exigent pourtant le mot de passe actuel, et leurs
 * refus ne disaient que « Le mot de passe est incorrect. » : la personne
 * inscrite par Google, GitHub ou Apple ne trouvait pas la voie, et le
 * limiteur la bloquait après cinq essais (#2104).
 *
 * Comme le changement d'adresse (`ChangementDAdresseTest`), chaque refus du
 * mot de passe actuel donne à un tel compte le chemin vers un mot de passe :
 * se déconnecter, puis « Mot de passe oublié ? ». Ou, quand la messagerie ne
 * sait pas écrire son adresse et qu'aucun lien ne lui parviendrait, le renvoi
 * à un administrateur (`AdresseQueLaMessagerieSaitEcrireTest`). Un compte sans
 * fournisseur garde ses refus tels quels. Le message ne s'adresse qu'à la
 * session du compte, à qui la page du profil nomme déjà son fournisseur et
 * dit si la messagerie écrit son adresse.
 */

/**
 * Le chemin que donne un refus à un compte relié à Google dont la messagerie
 * écrit l'adresse.
 */
const COMPTE_RELIE_CHEMIN_PAR_LE_LIEN = 'Ton compte est relié à Google. Si tu n’as jamais choisi de mot de passe, choisis-en un d’abord : déconnecte-toi, puis « Mot de passe oublié ? » sur la page de connexion. Le lien part à ton adresse actuelle.';

/**
 * Le renvoi à un administrateur, pour un compte relié à Google dont la
 * messagerie n'écrit pas l'adresse : aucun lien ne lui parviendrait.
 */
const COMPTE_RELIE_CHEMIN_PAR_UN_ADMINISTRATEUR = 'Ton compte est relié à Google. Si tu n’as jamais choisi de mot de passe, aucun lien ne peut t’en donner un : notre messagerie ne sait pas écrire à ton adresse actuelle. Demande à un administrateur de l’application de la changer.';

/**
 * Un compte ouvert par Google, comme la connexion sociale le crée : son mot de
 * passe, tiré au hasard, n'est connu de personne.
 */
function compteRelieOuvertParGoogle(): User
{
    $utilisateurGoogle = new SocialiteUser()->map([
        'id' => 'identifiant-google',
        'name' => 'Camille',
        'email' => 'camille@example.org',
        'avatar' => null,
    ]);

    return app(ResolveSocialUserAction::class)->execute('google', $utilisateurGoogle, adresseVerifiee: true)->refresh();
}

/**
 * Un compte relié à Google dont l'adresse, antérieure à #2075, n'est pas une
 * adresse que la messagerie écrit telle quelle : aucun lien ne lui part.
 */
function compteRelieALAdresseQueLaMessagerieNEcritPas(): User
{
    $compte = User::factory()->create(['email' => 'camille(salle)@example.org']);
    $compte->forceFill(['provider' => 'google', 'provider_id' => 'identifiant-google', 'liaison_prouvee_le' => now()])->save();

    return $compte;
}

/**
 * Un nouveau mot de passe recevable, et sa confirmation.
 *
 * @return array{password: string, password_confirmation: string}
 */
function compteRelieNouveauMotDePasse(): array
{
    return ['password' => 'Nouveau-mot-de-passe-2026!', 'password_confirmation' => 'Nouveau-mot-de-passe-2026!'];
}

it('donne le chemin vers un mot de passe à un compte relié qui veut supprimer son compte', function (Closure $creer, string $chemin): void {
    $compte = $creer();
    $this->actingAs($compte);

    $this->from('/profile/edit')
        ->delete('/profile', ['password' => 'devine'])
        ->assertRedirect('/profile/edit')
        ->assertSessionHasErrors(['password' => 'Le mot de passe est incorrect. '.$chemin]);

    $this->from('/profile/edit')
        ->delete('/profile', ['password' => ''])
        ->assertSessionHasErrors(['password' => 'Le champ mot de passe est obligatoire. '.$chemin]);

    $this->assertAuthenticatedAs($compte);
    expect(User::query()->whereKey($compte->id)->exists())->toBeTrue()
        ->and(RateLimiter::attempts('delete-account-'.$compte->id))->toBe(2);
})->with([
    'messagerie qui écrit l’adresse' => [fn (): User => compteRelieOuvertParGoogle(), COMPTE_RELIE_CHEMIN_PAR_LE_LIEN],
    'messagerie qui ne l’écrit pas' => [fn (): User => compteRelieALAdresseQueLaMessagerieNEcritPas(), COMPTE_RELIE_CHEMIN_PAR_UN_ADMINISTRATEUR],
]);

it('donne le chemin vers un mot de passe à un compte relié qui veut changer son mot de passe', function (Closure $creer, string $chemin): void {
    $compte = $creer();
    $empreinte = $compte->password;
    $this->actingAs($compte);

    $this->from('/profile/edit')
        ->put('/password', ['current_password' => 'devine', ...compteRelieNouveauMotDePasse()])
        ->assertRedirect('/profile/edit')
        ->assertSessionHasErrors(['current_password' => 'Le mot de passe est incorrect. '.$chemin]);

    $this->from('/profile/edit')
        ->put('/password', ['current_password' => '', ...compteRelieNouveauMotDePasse()])
        ->assertSessionHasErrors(['current_password' => 'Le champ mot de passe actuel est obligatoire. '.$chemin]);

    expect($compte->refresh()->password)->toBe($empreinte)
        ->and(RateLimiter::attempts('update-password-'.$compte->id))->toBe(2);
})->with([
    'messagerie qui écrit l’adresse' => [fn (): User => compteRelieOuvertParGoogle(), COMPTE_RELIE_CHEMIN_PAR_LE_LIEN],
    'messagerie qui ne l’écrit pas' => [fn (): User => compteRelieALAdresseQueLaMessagerieNEcritPas(), COMPTE_RELIE_CHEMIN_PAR_UN_ADMINISTRATEUR],
]);

it('garde ses refus tels quels à un compte sans fournisseur', function (): void {
    $compte = User::factory()->create();
    $this->actingAs($compte);

    $this->from('/profile/edit')
        ->delete('/profile', ['password' => 'devine'])
        ->assertSessionHasErrors(['password' => 'Le mot de passe est incorrect.']);
    expect(session('errors')->get('password'))->toBe(['Le mot de passe est incorrect.']);

    $this->from('/profile/edit')
        ->delete('/profile', ['password' => ''])
        ->assertSessionHasErrors(['password' => 'Le champ mot de passe est obligatoire.']);
    expect(session('errors')->get('password'))->toBe(['Le champ mot de passe est obligatoire.']);

    $this->from('/profile/edit')
        ->put('/password', ['current_password' => 'devine', ...compteRelieNouveauMotDePasse()])
        ->assertSessionHasErrors(['current_password' => 'Le mot de passe est incorrect.']);
    expect(session('errors')->get('current_password'))->toBe(['Le mot de passe est incorrect.']);

    $this->from('/profile/edit')
        ->put('/password', ['current_password' => '', ...compteRelieNouveauMotDePasse()])
        ->assertSessionHasErrors(['current_password' => 'Le champ mot de passe actuel est obligatoire.']);
    expect(session('errors')->get('current_password'))->toBe(['Le champ mot de passe actuel est obligatoire.']);
});

/*
 * Le chemin ne change rien au limiteur : cinq essais manqués, puis le refus
 * du limiteur, même avec le bon mot de passe. Deux cas plutôt qu'un : la
 * limite de route de `PUT /password` (six requêtes par minute) couperait la
 * seconde moitié d'un cas unique en 429.
 */
it('bloque la suppression d’un compte relié après cinq essais, chemin ou non', function (): void {
    $compte = compteRelieOuvertParGoogle();
    $compte->forceFill(['password' => Hash::make('connu-desormais')])->save();
    $this->actingAs($compte);

    foreach (range(1, 5) as $essai) {
        $this->from('/profile/edit')
            ->delete('/profile', ['password' => "devine-{$essai}"])
            ->assertSessionHasErrors(['password' => 'Le mot de passe est incorrect. '.COMPTE_RELIE_CHEMIN_PAR_LE_LIEN]);
    }

    $this->from('/profile/edit')
        ->delete('/profile', ['password' => 'connu-desormais'])
        ->assertInvalid(['password' => 'essayer de nouveau dans']);

    expect(User::query()->whereKey($compte->id)->exists())->toBeTrue();
});

it('bloque le changement du mot de passe d’un compte relié après cinq essais, chemin ou non', function (): void {
    $compte = compteRelieOuvertParGoogle();
    $compte->forceFill(['password' => Hash::make('connu-desormais')])->save();
    $empreinte = $compte->password;
    $this->actingAs($compte);

    foreach (range(1, 5) as $essai) {
        $this->from('/profile/edit')
            ->put('/password', ['current_password' => "devine-{$essai}", ...compteRelieNouveauMotDePasse()])
            ->assertSessionHasErrors(['current_password' => 'Le mot de passe est incorrect. '.COMPTE_RELIE_CHEMIN_PAR_LE_LIEN]);
    }

    $this->from('/profile/edit')
        ->put('/password', ['current_password' => 'connu-desormais', ...compteRelieNouveauMotDePasse()])
        ->assertInvalid(['current_password' => 'essayer de nouveau dans']);

    expect($compte->refresh()->password)->toBe($empreinte);
});
