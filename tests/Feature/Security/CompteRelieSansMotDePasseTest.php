<?php

declare(strict_types=1);

use App\Actions\ResolveSocialUserAction;
use App\Models\User;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
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
 * se déconnecter, puis « Mot de passe oublié ? ». Ou, quand aucun lien ne lui
 * parviendrait, parce que la messagerie ne sait pas écrire son adresse ou que
 * la demande du lien la refuse, le renvoi à un administrateur
 * (`AdresseQueLaMessagerieSaitEcrireTest`). Un compte sans
 * fournisseur garde ses refus tels quels. Le message ne s'adresse qu'à la
 * session du compte, à qui la page du profil nomme déjà son fournisseur et
 * dit si la messagerie écrit son adresse.
 */

/**
 * Le chemin que donne un refus à un compte relié à Google à qui le lien
 * « mot de passe oublié » parvient.
 */
const COMPTE_RELIE_CHEMIN_PAR_LE_LIEN = 'Ton compte est relié à Google. Si tu n’as jamais choisi de mot de passe, choisis-en un d’abord : déconnecte-toi, puis « Mot de passe oublié ? » sur la page de connexion. Le lien part à ton adresse actuelle.';

/**
 * Le renvoi à un administrateur, pour un compte relié à Google à qui aucun
 * lien ne parviendrait.
 */
const COMPTE_RELIE_CHEMIN_PAR_UN_ADMINISTRATEUR = 'Ton compte est relié à Google. Si tu n’as jamais choisi de mot de passe, aucun lien ne peut t’en donner un : nous n’en envoyons pas à ton adresse actuelle. Demande à un administrateur de l’application de la changer.';

/**
 * Le refus du limiteur (`auth.throttle`) sous une horloge arrêtée, une minute
 * avant le prochain essai. Il tutoie, comme le chemin qui le suit pour un
 * compte relié : la phrase vouvoyait, et le refus changeait de registre en
 * son milieu (#2119).
 */
const COMPTE_RELIE_REFUS_DU_LIMITEUR = 'Trop d’essais. Réessaie dans 60 secondes.';

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
 * Un compte relié à Google dont l'adresse, antérieure à #2075, porte un tiret
 * bas dans le domaine : la messagerie l'écrit, mais la demande du lien
 * « mot de passe oublié » la refuse (règle `email`), et aucun lien ne part.
 */
function compteRelieALAdresseQueLaDemandeDuLienRefuse(): User
{
    $compte = User::factory()->create(['email' => 'camille@exa_mple.org']);
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
    assert($compte instanceof User);
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
    'messagerie qui écrit l’adresse' => [compteRelieOuvertParGoogle(...), COMPTE_RELIE_CHEMIN_PAR_LE_LIEN],
    'messagerie qui ne l’écrit pas' => [compteRelieALAdresseQueLaMessagerieNEcritPas(...), COMPTE_RELIE_CHEMIN_PAR_UN_ADMINISTRATEUR],
    'demande du lien qui refuse l’adresse' => [compteRelieALAdresseQueLaDemandeDuLienRefuse(...), COMPTE_RELIE_CHEMIN_PAR_UN_ADMINISTRATEUR],
]);

it('donne le chemin vers un mot de passe à un compte relié qui veut changer son mot de passe', function (Closure $creer, string $chemin): void {
    $compte = $creer();
    assert($compte instanceof User);
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
    'messagerie qui écrit l’adresse' => [compteRelieOuvertParGoogle(...), COMPTE_RELIE_CHEMIN_PAR_LE_LIEN],
    'messagerie qui ne l’écrit pas' => [compteRelieALAdresseQueLaMessagerieNEcritPas(...), COMPTE_RELIE_CHEMIN_PAR_UN_ADMINISTRATEUR],
    'demande du lien qui refuse l’adresse' => [compteRelieALAdresseQueLaDemandeDuLienRefuse(...), COMPTE_RELIE_CHEMIN_PAR_UN_ADMINISTRATEUR],
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
 * du limiteur, même avec le bon mot de passe. Ce refus donne encore le
 * chemin : le formulaire cache son aide devant tout refus du champ, et la
 * personne bloquée ne le lirait plus nulle part. Deux cas plutôt qu'un : la
 * limite de route de `PUT /password` (six requêtes par minute) couperait la
 * seconde moitié d'un cas unique en 429.
 */
it('bloque la suppression d’un compte relié après cinq essais, et le refus du limiteur donne encore le chemin', function (): void {
    $this->freezeSecond();
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
        ->assertSessionHasErrors(['password' => COMPTE_RELIE_REFUS_DU_LIMITEUR.' '.COMPTE_RELIE_CHEMIN_PAR_LE_LIEN]);

    expect(User::query()->whereKey($compte->id)->exists())->toBeTrue();
});

it('bloque le changement du mot de passe d’un compte relié après cinq essais, et le refus du limiteur donne encore le chemin', function (): void {
    $this->freezeSecond();
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
        ->assertSessionHasErrors(['current_password' => COMPTE_RELIE_REFUS_DU_LIMITEUR.' '.COMPTE_RELIE_CHEMIN_PAR_LE_LIEN]);

    expect($compte->refresh()->password)->toBe($empreinte);
});

/*
 * Le changement d'adresse compte ses essais sous la clé du mot de passe : son
 * refus du limiteur donne le même chemin, et le formulaire de l'adresse cache
 * lui aussi son aide devant le refus de son champ.
 */
it('donne aussi le chemin au refus du limiteur du changement d’adresse d’un compte relié', function (): void {
    $this->freezeSecond();
    $compte = compteRelieOuvertParGoogle();
    foreach (range(1, 5) as $_essai) {
        RateLimiter::hit('update-password-'.$compte->id);
    }
    $this->actingAs($compte);

    $this->from('/profile/edit')
        ->patch('/profile', ['name' => 'Camille', 'email' => 'autre@example.org', 'current_password' => 'devine'])
        ->assertRedirect('/profile/edit');

    expect(session('errors')->get('current_password'))->toBe([COMPTE_RELIE_REFUS_DU_LIMITEUR.' '.COMPTE_RELIE_CHEMIN_PAR_LE_LIEN])
        ->and($compte->refresh()->email)->toBe('camille@example.org');
});

it('garde le refus du limiteur tel quel à un compte sans fournisseur', function (): void {
    $this->freezeSecond();
    $compte = User::factory()->create();
    foreach (range(1, 5) as $_essai) {
        RateLimiter::hit('delete-account-'.$compte->id);
        RateLimiter::hit('update-password-'.$compte->id);
    }
    $this->actingAs($compte);

    $this->from('/profile/edit')->delete('/profile', ['password' => 'password']);
    expect(session('errors')->get('password'))->toBe([COMPTE_RELIE_REFUS_DU_LIMITEUR]);

    $this->from('/profile/edit')->put('/password', ['current_password' => 'password', ...compteRelieNouveauMotDePasse()]);
    expect(session('errors')->get('current_password'))->toBe([COMPTE_RELIE_REFUS_DU_LIMITEUR]);

    $this->from('/profile/edit')->patch('/profile', ['name' => $compte->name, 'email' => 'autre@example.org', 'current_password' => 'password']);
    expect(session('errors')->get('current_password'))->toBe([COMPTE_RELIE_REFUS_DU_LIMITEUR]);
});

/*
 * Une quatrième requête évalue le mot de passe actuel du compte connecté : la
 * confirmation du mot de passe (`ConfirmPasswordRequest`, page
 * Auth/ConfirmPassword), que le middleware `password.confirm` exige d'une
 * route. Elle ne donne pas le chemin : son refus reste celui de `auth.password`
 * et du limiteur. Aucune route ne l'exige, et personne n'y est envoyé. Le jour
 * où une route l'exigera, un compte relié s'y bloquera comme avant #2104 : ce
 * cas échoue alors, pour que la requête et la page donnent d'abord le chemin.
 */
it('n’envoie personne à la confirmation du mot de passe, qui ne donne pas le chemin à un compte relié', function (): void {
    $routeur = app(Router::class);

    $routesQuiLExigent = collect($routeur->getRoutes()->getRoutes())
        ->filter(static fn (Route $route): bool => collect($routeur->gatherRouteMiddleware($route))
            ->contains(static fn (mixed $maillon): bool => is_string($maillon) && Str::before($maillon, ':') === RequirePassword::class))
        ->map(static fn (Route $route): string => $route->uri())
        ->values()
        ->all();

    expect($routesQuiLExigent)->toBe([], 'Ces routes exigent la confirmation du mot de passe : ConfirmPasswordRequest et la page Auth/ConfirmPassword doivent d’abord donner le chemin à un compte relié (DonneLeCheminVersUnMotDePasse, CheminVersUnMotDePasse).');
});
