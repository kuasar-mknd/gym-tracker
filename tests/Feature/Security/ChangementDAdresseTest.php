<?php

declare(strict_types=1);

use App\Actions\ResolveSocialUserAction;
use App\Models\User;
use App\Notifications\AdresseDuCompteChangee;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Tests\Support\Appareil;

/*
 * L'adresse d'un compte reçoit les liens de réinitialisation du mot de passe.
 * `PATCH /profile` la changeait sur la seule foi de la session, sans mot de
 * passe et sans prévenir personne : une session ouverte par quelqu'un d'autre
 * pouvait se donner l'adresse, puis le mot de passe, puis le compte.
 *
 * Changer l'adresse exige désormais le mot de passe actuel (le nom seul reste
 * libre), et l'ancienne adresse est prévenue de tout changement, qu'il vienne
 * du profil ou du panneau.
 */

/**
 * Un compte à l'adresse vérifiée, dont le mot de passe est `password`.
 */
function changementDAdresseLeCompte(): User
{
    return User::factory()->create(['name' => 'Titulaire', 'email' => 'titulaire@example.org']);
}

/**
 * Un nouveau mot de passe recevable, et sa confirmation.
 *
 * @return array{password: string, password_confirmation: string}
 */
function changementDAdresseNouveauMotDePasse(): array
{
    return ['password' => 'Nouveau-mot-de-passe-2026!', 'password_confirmation' => 'Nouveau-mot-de-passe-2026!'];
}

/**
 * Affirme que rien n'a changé : même adresse, toujours vérifiée, et aucun
 * courriel parti.
 */
function changementDAdresseRienNAChange(User $compte, string $adresse): void
{
    $compte->refresh();

    expect($compte->email)->toBe($adresse)
        ->and($compte->email_verified_at)->not->toBeNull();

    Notification::assertNothingSent();
}

it('refuse un changement d’adresse sans le mot de passe actuel', function (): void {
    Notification::fake();
    $compte = changementDAdresseLeCompte();
    $this->actingAs($compte);

    $this->from('/profile/edit')
        ->patch('/profile', ['name' => 'Titulaire', 'email' => 'autre@example.org'])
        ->assertRedirect('/profile/edit')
        ->assertSessionHasErrors(['current_password' => 'Ton mot de passe actuel est demandé pour changer d’adresse.']);

    changementDAdresseRienNAChange($compte, 'titulaire@example.org');
});

it('ne laisse pas une seconde session du compte s’approprier l’adresse, ni la réinitialisation qui la suivait', function (): void {
    Notification::fake();
    $compte = changementDAdresseLeCompte();
    $identifiants = ['email' => 'titulaire@example.org', 'password' => 'password', 'remember' => '1'];
    $titulaire = new Appareil();
    $titulaire->envoyer('POST', '/login', $identifiants)->assertRedirect(route('dashboard'));
    $autreSession = new Appareil();
    $autreSession->envoyer('POST', '/login', $identifiants)->assertRedirect(route('dashboard'));

    $autreSession->envoyer('PATCH', '/profile', ['name' => 'Titulaire', 'email' => 'autre@example.org'], ['Referer' => url('/profile/edit')])
        ->assertRedirect(url('/profile/edit'))
        ->assertSessionHasErrors('current_password');

    changementDAdresseRienNAChange($compte, 'titulaire@example.org');

    new Appareil()->envoyer('POST', '/forgot-password', ['email' => 'autre@example.org'])->assertSessionHasErrors('email');
    new Appareil()->envoyer('POST', '/forgot-password', ['email' => 'titulaire@example.org'])->assertSessionHasNoErrors();

    Notification::assertSentTo($compte, ResetPassword::class);
    $titulaire->envoyer('GET', '/profile/edit')->assertOk();
});

it('refuse un mauvais mot de passe, et compte l’essai', function (): void {
    Notification::fake();
    $compte = changementDAdresseLeCompte();
    $this->actingAs($compte);

    $this->from('/profile/edit')
        ->patch('/profile', ['name' => 'Titulaire', 'email' => 'autre@example.org', 'current_password' => 'pas-le-bon'])
        ->assertSessionHasErrors(['current_password' => 'Ce mot de passe ne correspond pas à ton compte.']);

    changementDAdresseRienNAChange($compte, 'titulaire@example.org');
    expect(RateLimiter::attempts('update-password-'.$compte->id))->toBe(1);
});

it('change l’adresse avec le bon mot de passe et prévient l’ancienne', function (): void {
    Notification::fake();
    $compte = changementDAdresseLeCompte();
    $this->actingAs($compte);
    RateLimiter::hit('update-password-'.$compte->id);

    $this->from('/profile/edit')
        ->patch('/profile', ['name' => 'Titulaire', 'email' => 'nouvelle@example.org', 'current_password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile/edit');

    $compte->refresh();

    expect($compte->email)->toBe('nouvelle@example.org')
        ->and($compte->email_verified_at)->toBeNull()
        ->and(RateLimiter::attempts('update-password-'.$compte->id))->toBe(0);

    Notification::assertSentOnDemand(
        AdresseDuCompteChangee::class,
        fn (AdresseDuCompteChangee $avis, array $canaux, AnonymousNotifiable $destinataire): bool => $destinataire->routes === ['mail' => 'titulaire@example.org']
            && $canaux === ['mail']
            && $avis->nouvelleAdresse === 'nouvelle@example.org',
    );
    Notification::assertSentOnDemandTimes(AdresseDuCompteChangee::class, 1);
    Notification::assertNotSentTo($compte, AdresseDuCompteChangee::class);
});

it('laisse le nom changer sans mot de passe, mais pas l’adresse dans la même requête', function (): void {
    Notification::fake();
    $compte = changementDAdresseLeCompte();
    $this->actingAs($compte);

    // Un mot de passe faux joint à un changement du seul nom est écarté, pas vérifié.
    $this->patch('/profile', ['name' => 'Nouveau Nom', 'email' => 'titulaire@example.org', 'current_password' => 'pas-le-bon'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile/edit');

    expect($compte->refresh()->name)->toBe('Nouveau Nom')
        ->and(RateLimiter::attempts('update-password-'.$compte->id))->toBe(0);

    $this->patch('/profile', ['name' => 'Autre Nom', 'email' => 'autre@example.org'])
        ->assertSessionHasErrors('current_password');

    expect($compte->refresh()->name)->toBe('Nouveau Nom');
    changementDAdresseRienNAChange($compte, 'titulaire@example.org');
});

/**
 * Un compte ouvert par GitHub, qui rend l'adresse principale avec sa casse.
 * Le compte ne connaît pas son mot de passe, tiré au hasard à l'inscription.
 */
function changementDAdresseLeCompteGitHub(string $adresse): User
{
    $utilisateurGitHub = new SocialiteUser()->map([
        'id' => 4242,
        'nickname' => 'titulaire',
        'name' => 'Titulaire',
        'email' => $adresse,
        'avatar' => null,
    ]);

    return app(ResolveSocialUserAction::class)->execute('github', $utilisateurGitHub)->refresh();
}

/**
 * La règle des minuscules ne vise que l'adresse qui change. Une adresse
 * enregistrée avec des majuscules, telle que GitHub la rend à l'inscription ou
 * que le panneau la pose, bloquait sinon le seul nom : la ramener en
 * minuscules, c'est la changer, et le mot de passe est alors exigé d'un compte
 * qui peut n'en avoir jamais choisi.
 */
it('laisse changer le seul nom d’un compte dont l’adresse porte des majuscules', function (): void {
    Notification::fake();
    $compte = changementDAdresseLeCompteGitHub('Titulaire@Example.org');
    $verifieeLe = $compte->email_verified_at;
    $this->actingAs($compte);

    $this->from('/profile/edit')
        ->patch('/profile', ['name' => 'Nouveau Nom', 'email' => 'Titulaire@Example.org'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile/edit');

    expect($compte->refresh()->name)->toBe('Nouveau Nom')
        ->and($compte->email_verified_at)->toEqual($verifieeLe)
        ->and(RateLimiter::attempts('update-password-'.$compte->id))->toBe(0);
    changementDAdresseRienNAChange($compte, 'Titulaire@Example.org');
});

it('tient le compteur d’essais : plein, il bloque l’adresse mais pas le nom, et le nom seul ne le vide pas', function (): void {
    Notification::fake();
    $compte = changementDAdresseLeCompte();
    $this->actingAs($compte);
    $mauvais = ['name' => 'Titulaire', 'email' => 'autre@example.org', 'current_password' => 'pas-le-bon'];

    foreach (range(1, 4) as $_essai) {
        $this->patch('/profile', $mauvais)->assertSessionHasErrors('current_password');
    }

    // Un enregistrement du seul nom glissé entre deux essais ne remet rien à zéro.
    $this->patch('/profile', ['name' => 'Titulaire', 'email' => 'titulaire@example.org'])->assertSessionHasNoErrors();
    expect(RateLimiter::attempts('update-password-'.$compte->id))->toBe(4);

    $this->patch('/profile', $mauvais)->assertSessionHasErrors('current_password');

    $this->patch('/profile', ['name' => 'Titulaire', 'email' => 'autre@example.org', 'current_password' => 'password'])
        ->assertInvalid(['current_password' => 'essayer de nouveau dans']);

    $this->patch('/profile', ['name' => 'Encore Libre', 'email' => 'titulaire@example.org'])->assertSessionHasNoErrors();
    expect($compte->refresh()->name)->toBe('Encore Libre');

    changementDAdresseRienNAChange($compte, 'titulaire@example.org');
});

/**
 * Le changement d'adresse et le changement de mot de passe évaluent le même
 * mot de passe. Avec deux compteurs, une session ouverte par quelqu'un d'autre
 * qui a épuisé ses essais sur l'un en retrouvait cinq sur l'autre, chaque
 * minute : ils partagent donc le même, dans les deux sens.
 *
 * Deux cas plutôt qu'un : la limite de route de `PUT /password` (six requêtes
 * par minute, sous une clé par compte qu'elle partage avec les autres limites
 * de route) couperait la seconde moitié d'un cas unique en 429, avant le
 * compteur d'essais.
 */
it('bloque le changement d’adresse quand les essais du mot de passe sont épuisés', function (): void {
    Notification::fake();
    $compte = changementDAdresseLeCompte();
    $this->actingAs($compte);

    foreach (range(1, 5) as $essai) {
        $this->from('/profile/edit')
            ->put('/password', ['current_password' => "devine-{$essai}", ...changementDAdresseNouveauMotDePasse()])
            ->assertSessionHasErrors('current_password');
    }

    // Même avec le bon mot de passe, l'adresse attend que le compteur se vide.
    $this->from('/profile/edit')
        ->patch('/profile', ['name' => 'Titulaire', 'email' => 'autre@example.org', 'current_password' => 'password'])
        ->assertInvalid(['current_password' => 'essayer de nouveau dans']);

    changementDAdresseRienNAChange($compte, 'titulaire@example.org');
});

it('bloque le changement de mot de passe quand les essais de l’adresse sont épuisés', function (): void {
    Notification::fake();
    $compte = changementDAdresseLeCompte();
    $empreinteDuMotDePasse = $compte->password;
    $this->actingAs($compte);

    foreach (range(1, 5) as $essai) {
        $this->from('/profile/edit')
            ->patch('/profile', ['name' => 'Titulaire', 'email' => 'autre@example.org', 'current_password' => "devine-{$essai}"])
            ->assertSessionHasErrors('current_password');
    }

    $this->from('/profile/edit')
        ->put('/password', ['current_password' => 'password', ...changementDAdresseNouveauMotDePasse()])
        ->assertInvalid(['current_password' => 'essayer de nouveau dans']);

    expect($compte->refresh()->password)->toBe($empreinteDuMotDePasse);
    changementDAdresseRienNAChange($compte, 'titulaire@example.org');
});

it('dit à un compte relié à un fournisseur comment obtenir un mot de passe', function (): void {
    Notification::fake();
    $compte = User::factory()->create([
        'email' => 'sociale@example.org',
        'provider' => 'google',
        'provider_id' => 'identifiant-google',
    ]);
    $this->actingAs($compte);

    $chemin = 'Ton compte est relié à Google. Si tu n’as jamais choisi de mot de passe, choisis-en un d’abord : déconnecte-toi, puis « Mot de passe oublié ? » sur la page de connexion. Le lien part à ton adresse actuelle.';

    $this->patch('/profile', ['name' => $compte->name, 'email' => 'autre@example.org'])
        ->assertSessionHasErrors(['current_password' => $chemin]);

    $this->patch('/profile', ['name' => $compte->name, 'email' => 'autre@example.org', 'current_password' => 'devine'])
        ->assertSessionHasErrors(['current_password' => 'Ce mot de passe ne correspond pas à ton compte. '.$chemin]);

    changementDAdresseRienNAChange($compte, 'sociale@example.org');

    $this->get('/profile/edit')->assertInertia(
        fn (Inertia\Testing\AssertableInertia $page): Inertia\Testing\AssertableInertia => $page->where('fournisseurDeConnexion', 'Google'),
    );
});

/**
 * `auth.user` ne dit pas si l'adresse est vérifiée, et le formulaire le lisait
 * là : aucun compte ne voyait l'annonce de l'avis à l'adresse actuelle, ni le
 * bandeau qui propose de renvoyer le lien de vérification. Ce bandeau compte
 * après un changement : tant que la nouvelle adresse n'est pas vérifiée, un
 * changement suivant ne prévient que la dernière adresse vérifiée.
 */
it('dit au formulaire si l’adresse est vérifiée, et qu’elle ne l’est plus après un changement', function (): void {
    Notification::fake();
    $compte = changementDAdresseLeCompte();
    $this->actingAs($compte);

    $this->get('/profile/edit')->assertInertia(
        fn (Inertia\Testing\AssertableInertia $page): Inertia\Testing\AssertableInertia => $page->where('adresseVerifiee', true),
    );

    $this->from('/profile/edit')
        ->patch('/profile', ['name' => 'Titulaire', 'email' => 'nouvelle@example.org', 'current_password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile/edit');

    $this->get('/profile/edit')->assertInertia(
        fn (Inertia\Testing\AssertableInertia $page): Inertia\Testing\AssertableInertia => $page
            ->where('auth.user.email', 'nouvelle@example.org')
            ->where('adresseVerifiee', false),
    );

    $this->actingAs(User::factory()->unverified()->create())->get('/profile/edit')->assertInertia(
        fn (Inertia\Testing\AssertableInertia $page): Inertia\Testing\AssertableInertia => $page->where('adresseVerifiee', false),
    );
});

it('ne nomme aucun fournisseur à un compte à mot de passe seul', function (): void {
    $this->actingAs(changementDAdresseLeCompte());

    $this->get('/profile/edit')->assertInertia(
        fn (Inertia\Testing\AssertableInertia $page): Inertia\Testing\AssertableInertia => $page->where('fournisseurDeConnexion', null),
    );
});

it('prévient l’ancienne adresse et retire la vérification quel que soit le chemin', function (): void {
    Notification::fake();
    $compte = User::factory()->create(['email' => 'avant@example.org']);

    $compte->update(['email' => 'apres@example.org']);

    expect($compte->refresh()->email_verified_at)->toBeNull();
    Notification::assertSentOnDemand(
        AdresseDuCompteChangee::class,
        fn (AdresseDuCompteChangee $avis, array $canaux, AnonymousNotifiable $destinataire): bool => $destinataire->routes === ['mail' => 'avant@example.org'],
    );

    // Une écriture qui ne touche pas l'adresse ne prévient personne et garde la vérification.
    $verifie = User::factory()->create();
    $verifie->update(['name' => 'Autre']);

    expect($verifie->refresh()->email_verified_at)->not->toBeNull();
    Notification::assertSentOnDemandTimes(AdresseDuCompteChangee::class, 1);
});

/**
 * Une écriture qui change l'adresse et pose `email_verified_at` du même coup
 * ne garde pas la nouvelle adresse pour vérifiée : rien ne l'a prouvée. Aucun
 * chemin de l'application ne le fait aujourd'hui ; une exception laissée aux
 * appelants aurait été une porte que rien ne gardait.
 */
it('retire la vérification même quand l’écriture qui change l’adresse la pose', function (): void {
    Notification::fake();
    $compte = changementDAdresseLeCompte();

    $compte->forceFill(['email' => 'pretendue@example.org', 'email_verified_at' => now()->addMinute()])->save();
    $compte->refresh();

    expect($compte->email)->toBe('pretendue@example.org')
        ->and($compte->email_verified_at)->toBeNull()
        ->and($compte->ancienne_adresse_verifiee)->toBe('titulaire@example.org');
    Notification::assertSentOnDemand(
        AdresseDuCompteChangee::class,
        fn (AdresseDuCompteChangee $avis, array $canaux, AnonymousNotifiable $destinataire): bool => $destinataire->routes === ['mail' => 'titulaire@example.org'],
    );
});

/**
 * Les écouteurs tournent à chaque enregistrement d'un compte, y compris d'une
 * instance chargée par une sélection partielle, comme celles dont
 * `app:verify-data-coherence --repair` recale les séries. Hors production, le
 * mode strict refuse la lecture d'un attribut non chargé : les écouteurs ne
 * doivent lire ainsi que ce que l'écriture pose.
 */
it('laisse enregistrer un compte chargé sans ses colonnes d’adresse', function (): void {
    Notification::fake();
    $compte = changementDAdresseLeCompte();

    expect(Model::preventsAccessingMissingAttributes())->toBeTrue();

    $partiel = User::query()->select(['id', 'current_streak'])->findOrFail($compte->id);
    $partiel->current_streak = 7;
    $partiel->save();

    $partiel = User::query()->select(['id'])->findOrFail($compte->id);
    $partiel->markEmailAsVerified();

    $compte->refresh();

    expect($compte->current_streak)->toBe(7)
        ->and($compte->email)->toBe('titulaire@example.org')
        ->and($compte->email_verified_at)->not->toBeNull()
        ->and($compte->ancienne_adresse_verifiee)->toBeNull();
    Notification::assertNothingSent();
});

/**
 * L'inscription ne prouve pas l'adresse, et un changement remet la nouvelle en
 * non vérifiée : un compte peut donc porter l'adresse d'un tiers, et faire des
 * allers-retours d'adresse avec son propre mot de passe. Prévenir à chaque
 * fois l'ancienne adresse enverrait au tiers, depuis l'expéditeur de
 * l'application, autant d'avis de sécurité que le compte le voudrait.
 */
it('ne prévient pas une ancienne adresse que personne n’a vérifiée', function (): void {
    Notification::fake();
    $compte = User::factory()->unverified()->create(['email' => 'jamais-verifiee@example.org']);
    $this->actingAs($compte);

    foreach (range(1, 3) as $tour) {
        $this->patch('/profile', ['name' => 'X', 'email' => "ailleurs{$tour}@example.org", 'current_password' => 'password'])
            ->assertSessionHasNoErrors();
        $this->patch('/profile', ['name' => 'X', 'email' => 'jamais-verifiee@example.org', 'current_password' => 'password'])
            ->assertSessionHasNoErrors();
    }

    expect($compte->refresh()->email)->toBe('jamais-verifiee@example.org')
        ->and($compte->email_verified_at)->toBeNull();
    Notification::assertNothingSent();
});

/**
 * Les avis partis, dans l'ordre d'envoi : le destinataire, la nouvelle adresse
 * annoncée et le texte rendu.
 *
 * @return list<array{destinataire: array<mixed>, nouvelle: string, rendu: string}>
 */
function changementDAdresseAvisEnvoyes(): array
{
    $avis = [];

    Notification::assertSentOnDemand(
        AdresseDuCompteChangee::class,
        function (AdresseDuCompteChangee $unAvis, array $_canaux, AnonymousNotifiable $destinataire) use (&$avis): bool {
            $avis[] = [
                'destinataire' => $destinataire->routes,
                'nouvelle' => $unAvis->nouvelleAdresse,
                'rendu' => (string) $unAvis->toMail($destinataire)->render(),
            ];

            return true;
        },
    );

    return $avis;
}

/**
 * Un changement d'adresse laisse le compte non vérifié, et seule une adresse
 * vérifiée est prévenue. Sans mémoire de la dernière adresse vérifiée, un
 * premier changement vers une adresse dont la forme masquée est celle du
 * titulaire lui envoyait un seul avis, qui semblait nommer sa propre adresse ;
 * le changement suivant, depuis cette adresse non vérifiée, ne prévenait
 * personne, et le titulaire n'apprenait jamais où son compte était parti.
 */
it('prévient la dernière adresse vérifiée de chaque changement tant que le compte n’est pas revérifié', function (): void {
    Notification::fake();
    $compte = changementDAdresseLeCompte();
    $this->actingAs($compte);

    $this->patch('/profile', ['name' => 'X', 'email' => 't@e.org', 'current_password' => 'password'])->assertSessionHasNoErrors();
    $this->patch('/profile', ['name' => 'X', 'email' => 'tiers@autre.example.org', 'current_password' => 'password'])->assertSessionHasNoErrors();

    $compte->refresh();

    expect($compte->email)->toBe('tiers@autre.example.org')
        ->and($compte->email_verified_at)->toBeNull()
        ->and($compte->ancienne_adresse_verifiee)->toBe('titulaire@example.org')
        ->and($compte->toArray())->not->toHaveKey('ancienne_adresse_verifiee');

    $avis = changementDAdresseAvisEnvoyes();

    expect(array_column($avis, 'destinataire'))->toBe([['mail' => 'titulaire@example.org'], ['mail' => 'titulaire@example.org']])
        ->and(array_column($avis, 'nouvelle'))->toBe(['t@e.org', 'tiers@autre.example.org']);

    // Le premier avis nomme une adresse qui, masquée, se confond avec celle du
    // titulaire : il le dit. Le second nomme l'adresse où le compte est parti.
    expect($avis[0]['rendu'])->toContain('remplacée par t•••@e•••.org.')
        ->and($avis[0]['rendu'])->toContain('c’en est pourtant une autre')
        ->and($avis[1]['rendu'])->toContain('remplacée par t•••@a•••.org.')
        ->and($avis[1]['rendu'])->not->toContain('c’en est pourtant une autre');
});

it('ne prévient pas l’adresse que le compte reprend, mais la prévient du changement suivant', function (): void {
    Notification::fake();
    $compte = changementDAdresseLeCompte();
    $this->actingAs($compte);

    foreach (['ailleurs@example.org', 'titulaire@example.org', 'encore-ailleurs@example.org'] as $adresse) {
        $this->patch('/profile', ['name' => 'X', 'email' => $adresse, 'current_password' => 'password'])->assertSessionHasNoErrors();
    }

    $avis = changementDAdresseAvisEnvoyes();

    // Le retour à l'adresse du titulaire ne lui annonce pas sa propre adresse ;
    // le compte, toujours non vérifié, la garde en mémoire pour la suite.
    expect(array_column($avis, 'destinataire'))->toBe([['mail' => 'titulaire@example.org'], ['mail' => 'titulaire@example.org']])
        ->and(array_column($avis, 'nouvelle'))->toBe(['ailleurs@example.org', 'encore-ailleurs@example.org']);
});

it('ne prévient pas l’adresse rendue au compte avec d’autres majuscules', function (): void {
    Notification::fake();
    $compte = changementDAdresseLeCompte();

    // Le panneau n'impose pas les minuscules, contrairement au profil.
    $compte->update(['email' => 'ailleurs@example.org']);
    $compte->update(['email' => 'Titulaire@Example.org']);

    expect(array_column(changementDAdresseAvisEnvoyes(), 'nouvelle'))->toBe(['ailleurs@example.org']);
});

it('oublie l’ancienne adresse quand le compte est vérifié de nouveau', function (): void {
    Notification::fake();
    $compte = changementDAdresseLeCompte();
    $this->actingAs($compte);

    $this->patch('/profile', ['name' => 'X', 'email' => 'nouvelle@example.org', 'current_password' => 'password'])->assertSessionHasNoErrors();

    expect($compte->refresh()->ancienne_adresse_verifiee)->toBe('titulaire@example.org');

    $this->get(URL::temporarySignedRoute('verification.verify', now()->addHour(), [
        'id' => $compte->id,
        'hash' => sha1('nouvelle@example.org'),
    ]))->assertRedirect();

    $compte->refresh();

    expect($compte->email_verified_at)->not->toBeNull()
        ->and($compte->ancienne_adresse_verifiee)->toBeNull();

    // Le changement suivant prévient l'adresse vérifiée qu'il quitte, et elle seule.
    $this->patch('/profile', ['name' => 'X', 'email' => 'encore@example.org', 'current_password' => 'password'])->assertSessionHasNoErrors();

    $avis = changementDAdresseAvisEnvoyes();

    expect(array_column($avis, 'destinataire'))->toBe([['mail' => 'titulaire@example.org'], ['mail' => 'nouvelle@example.org']])
        ->and(array_column($avis, 'nouvelle'))->toBe(['nouvelle@example.org', 'encore@example.org'])
        ->and($compte->refresh()->ancienne_adresse_verifiee)->toBe('nouvelle@example.org');
});

it('envoie le courriel pour de bon, et seulement une fois la transaction validée', function (): void {
    $mailer = Mail::mailer();
    assert($mailer instanceof Illuminate\Mail\Mailer);
    $transport = $mailer->getSymfonyTransport();
    assert($transport instanceof ArrayTransport);
    $annule = User::factory()->create(['email' => 'annule@example.org']);
    $valide = User::factory()->create(['email' => 'valide@example.org']);

    try {
        DB::transaction(function () use ($annule): void {
            $annule->update(['email' => 'annule-apres@example.org']);

            throw new RuntimeException('annulé');
        });
    } catch (RuntimeException) {
        // Le changement annulé ne doit rien annoncer.
    }

    DB::transaction(fn (): bool => $valide->update(['email' => 'valide-apres@example.org']));

    $envois = $transport->messages()->map(
        fn (mixed $envoi): array => $envoi instanceof SentMessage ? array_map(
            fn (Address $adresse): string => $adresse->getAddress(),
            $envoi->getEnvelope()->getRecipients(),
        ) : [],
    )->all();

    expect($envois)->toBe([['valide@example.org']])
        ->and(User::query()->where('email', 'annule@example.org')->exists())->toBeTrue();
});

it('écrit le courriel en français, avec la nouvelle adresse masquée et la marche à suivre', function (): void {
    $avis = new AdresseDuCompteChangee('nouvelle.adresse@exemple-tres-long.org');
    $courriel = $avis->toMail(new AnonymousNotifiable());
    $rendu = (string) $courriel->render();

    expect($avis)->toBeInstanceOf(ShouldQueue::class)
        ->and($avis->afterCommit)->toBeTrue()
        ->and($courriel->subject)->toBe('L’adresse de ton compte '.config()->string('app.name').' a changé')
        ->and($rendu)->toContain('n•••@e•••.org')
        ->and($rendu)->not->toContain('nouvelle.adresse')
        ->and($rendu)->not->toContain('exemple-tres-long')
        ->and($rendu)->toContain('Si ce n’est pas toi')
        ->and($rendu)->toContain('change ton mot de passe')
        ->and($rendu)->toContain('réponds à ce message');
});

it('masque une adresse sans laisser passer de mise en forme', function (string $adresse, string $masquee): void {
    expect(AdresseDuCompteChangee::masquer($adresse))->toBe($masquee);
})->with([
    'adresse ordinaire' => ['voleur@example.org', 'v•••@e•••.org'],
    'sous-domaines' => ['a@mail.example.co.uk', 'a•••@m•••.uk'],
    'premier caractère de mise en forme' => ['*gras*@[lien](x).org', '•••@•••.org'],
    'extension qui n’en est pas une' => ['x@[192.0.2.1]', 'x•••@•••'],
    'sans arobase' => ['pas-une-adresse', '•••'],
    'extension d’une seule lettre' => ['x@y.z', 'x•••@y•••'],
    'extension de sept lettres' => ['a@b.website', 'a•••@b•••'],
    'extension qui porte une phrase' => ['x@y.migration-automatique-rien-a-faire', 'x•••@y•••'],
    'extension qui porte une phrase sans tirets' => ['x@y.migrationautomatique', 'x•••@y•••'],
    'extension qui porte un numéro' => ['x@y.0612345678', 'x•••@y•••'],
]);

/**
 * La règle `email` laisse passer un dernier label de soixante-trois lettres,
 * chiffres et tirets. Recopié dans l'avis, il y mettrait une phrase de
 * l'auteur du changement, dans le courriel même qui doit alerter le titulaire.
 */
it('n’écrit dans l’avis aucune phrase venue de la nouvelle adresse', function (): void {
    Notification::fake();
    $compte = changementDAdresseLeCompte();

    $this->actingAs($compte)
        ->patch('/profile', ['name' => 'Titulaire', 'email' => 'x@y.migration-automatique-rien-a-faire', 'current_password' => 'password'])
        ->assertSessionHasNoErrors();

    Notification::assertSentOnDemand(
        AdresseDuCompteChangee::class,
        function (AdresseDuCompteChangee $avis, array $canaux, AnonymousNotifiable $destinataire): bool {
            $rendu = (string) $avis->toMail($destinataire)->render();

            return $destinataire->routes === ['mail' => 'titulaire@example.org']
                && str_contains($rendu, 'remplacée par x•••@y•••.')
                && ! str_contains($rendu, 'migration');
        },
    );
});
