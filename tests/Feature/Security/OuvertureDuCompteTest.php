<?php

declare(strict_types=1);

use App\Actions\Auth\ConnecterLeCompte;
use App\Actions\ResolveSocialUserAction;
use App\Models\User;
use App\Notifications\LienDOuvertureDuCompte;
use App\Support\LiensDesCourriels;
use Illuminate\Auth\Events\Verified;
use Illuminate\Console\Scheduling\Event as TachePlanifiee;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Two\User as SocialiteUser;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\CeQueLeClientLit;

use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\get;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

/*
 * Un compte créé par l'inscription ne s'ouvre qu'une fois son adresse prouvée.
 *
 * L'inscription crée le compte en attente (`en_attente_depuis`) et envoie à
 * son adresse le lien qui l'ouvre. Le lien marque la session du navigateur
 * qui le suit, puis mène à la connexion : c'est la connexion, avec le bon mot
 * de passe et cette marque, qui ouvre le compte. Sans la marque, le bon mot de
 * passe ne connecte pas et renvoie le lien, avec le refus de tout autre
 * essai, comme un mauvais mot de passe ou une adresse inconnue : un refus à
 * part dirait à qui vient de s'inscrire avec ce mot de passe que l'adresse
 * était libre. La réinitialisation du mot de passe et la
 * connexion par un fournisseur qui garantit l'adresse prouvent l'adresse
 * aussi : elles ouvrent le compte, et le rendent au titulaire de l'adresse
 * quand l'inscrit était un autre. Les comptes d'avant la colonne, vérifiés ou
 * non, ne changent pas. Un compte jamais ouvert s'efface au bout de
 * `User::JOURS_AVANT_LA_PURGE` jours.
 */

/**
 * Le mot de passe des comptes en attente de ces tests.
 */
function ouvertureMotDePasse(): string
{
    return 'Un-mot-de-passe-solide-42!';
}

/**
 * Un compte que l'inscription vient de créer, en attente de son lien.
 */
function ouvertureCompteEnAttente(string $adresse = 'inscrit@example.org'): User
{
    return User::factory()->enAttente()->create([
        'email' => $adresse,
        'password' => Hash::make(ouvertureMotDePasse()),
    ]);
}

/**
 * Le lien d'ouverture que le compte a reçu, lu dans son courriel.
 */
function ouvertureLienRecu(User $compte): string
{
    $liens = [];

    Notification::assertSentTo($compte, LienDOuvertureDuCompte::class, function (LienDOuvertureDuCompte $avis) use ($compte, &$liens): bool {
        $liens[] = (string) $avis->toMail($compte)->actionUrl;

        return true;
    });

    return (string) end($liens);
}

/**
 * Une connexion comme la page la poste, avec une garde neuve, comme chaque
 * requête de production.
 *
 * @return TestResponse<Response>
 */
function ouvertureSeConnecter(string $adresse, string $motDePasse): TestResponse
{
    Auth::forgetGuards();

    /** @var TestResponse<Response> $reponse */
    $reponse = test()->from(route('login'))->post(route('login'), ['email' => $adresse, 'password' => $motDePasse]);

    return $reponse;
}

it('ouvre le compte quand le lien a été suivi, puis le bon mot de passe donné', function (): void {
    Notification::fake();
    Event::fake([Verified::class]);

    post(route('register'), [
        'name' => 'Camille Martin',
        'email' => 'inscrit@example.org',
        'password' => ouvertureMotDePasse(),
        'password_confirmation' => ouvertureMotDePasse(),
    ])->assertRedirect(route('login'));

    $compte = User::query()->where('email', 'inscrit@example.org')->sole();
    $lien = ouvertureLienRecu($compte);

    expect($lien)->toStartWith(config()->string('app.url').'/inscription/ouvrir/'.$compte->id.'/');

    get($lien)
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', __('auth.lien_d_ouverture_suivi'));

    assertGuest();
    expect($compte->refresh()->estEnAttente())->toBeTrue();

    ouvertureSeConnecter('inscrit@example.org', ouvertureMotDePasse())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($compte);
    expect($compte->refresh()->estEnAttente())->toBeFalse()
        ->and($compte->hasVerifiedEmail())->toBeTrue()
        ->and(session()->has(ConnecterLeCompte::MARQUE_DU_LIEN_SUIVI))->toBeFalse();
    Event::assertDispatched(Verified::class, fn (Verified $evenement): bool => $evenement->user instanceof User && $evenement->user->is($compte));
});

it('ne connecte pas un compte en attente sans le lien, refuse comme pour tout autre essai, et renvoie le lien une fois par quart d’heure', function (): void {
    Notification::fake();
    $compte = ouvertureCompteEnAttente();

    ouvertureSeConnecter('inscrit@example.org', ouvertureMotDePasse())
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    assertGuest();
    Notification::assertSentToTimes($compte, LienDOuvertureDuCompte::class, 1);

    $this->travel(ConnecterLeCompte::SECONDES_ENTRE_DEUX_LIENS - 1)->seconds();
    ouvertureSeConnecter('inscrit@example.org', ouvertureMotDePasse())->assertSessionHasErrors(['email' => __('auth.failed')]);

    Notification::assertSentToTimes($compte, LienDOuvertureDuCompte::class, 1);

    $this->travel(2)->seconds();
    ouvertureSeConnecter('inscrit@example.org', ouvertureMotDePasse())->assertSessionHasErrors(['email' => __('auth.failed')]);

    Notification::assertSentToTimes($compte, LienDOuvertureDuCompte::class, 2);
    expect($compte->refresh()->estEnAttente())->toBeTrue();
});

it('répond au bon comme au mauvais mot de passe d’un compte en attente sans le lien, comme à une adresse inconnue', function (): void {
    Notification::fake();
    $this->freezeSecond();
    $compte = ouvertureCompteEnAttente();
    User::factory()->create(['email' => 'ouvert@example.org']);

    // L'adresse tapée revient dans le champ (`_old_input`) : seule différence attendue.
    $lire = static function (string $adresse, string $motDePasse = 'pas-le-bon-mot-de-passe'): array {
        test()->flushSession();
        Cache::flush();

        $lu = CeQueLeClientLit::de(ouvertureSeConnecter($adresse, $motDePasse), route('login'));

        expect($lu['session']['_old_input'] ?? null)->toBe(['email' => $adresse]);
        $lu['session']['_old_input'] = ['email' => 'l’adresse tapée'];

        return $lu;
    };

    $inconnue = $lire('personne@example.org');

    expect($inconnue['session']['errors'] ?? null)->toBe(['default' => ['email' => [__('auth.failed')]]])
        ->and($lire('inscrit@example.org'))->toBe($inconnue)
        ->and($lire('ouvert@example.org'))->toBe($inconnue);
    Notification::assertNothingSent();

    expect($lire('inscrit@example.org', ouvertureMotDePasse()))->toBe($inconnue);
    Notification::assertSentToTimes($compte, LienDOuvertureDuCompte::class, 1);
    Notification::assertSentTimes(LienDOuvertureDuCompte::class, 1);
    assertGuest();
});

it('refuse un lien d’ouverture expiré ou altéré', function (): void {
    Notification::fake();
    $compte = ouvertureCompteEnAttente();
    $lien = LiensDesCourriels::lienDOuvertureDuCompte($compte);

    get($lien.'x')->assertForbidden();
    get(str_replace('/'.$compte->id.'/', '/'.($compte->id + 1).'/', $lien))->assertForbidden();

    $this->travel(LiensDesCourriels::minutesDeValiditeDUnLien() + 1)->minutes();
    get($lien)->assertForbidden();

    ouvertureSeConnecter('inscrit@example.org', ouvertureMotDePasse())->assertSessionHasErrors(['email' => __('auth.failed')]);
    assertGuest();
});

it('n’ouvre pas un compte par le lien d’un autre, ni par une marque échue ou d’une autre adresse', function (): void {
    Notification::fake();
    $compte = ouvertureCompteEnAttente();
    $autre = ouvertureCompteEnAttente('autre@example.org');

    get(LiensDesCourriels::lienDOuvertureDuCompte($autre))->assertRedirect(route('login'));
    ouvertureSeConnecter('inscrit@example.org', ouvertureMotDePasse())->assertSessionHasErrors(['email' => __('auth.failed')]);

    // Une marque posée pour le bon compte, mais sur une autre adresse.
    session([ConnecterLeCompte::MARQUE_DU_LIEN_SUIVI => ['compte' => $compte->id, 'empreinte' => sha1('autre@example.org'), 'expire' => now()->addHour()->getTimestamp()]]);
    ouvertureSeConnecter('inscrit@example.org', ouvertureMotDePasse())->assertSessionHasErrors(['email' => __('auth.failed')]);

    // Le bon lien, puis une connexion après l'échéance de la marque.
    get(LiensDesCourriels::lienDOuvertureDuCompte($compte))->assertRedirect(route('login'));
    $this->travel(LiensDesCourriels::minutesDeValiditeDUnLien() + 1)->minutes();
    ouvertureSeConnecter('inscrit@example.org', ouvertureMotDePasse())->assertSessionHasErrors(['email' => __('auth.failed')]);

    assertGuest();
    expect($compte->refresh()->estEnAttente())->toBeTrue()
        ->and($autre->refresh()->estEnAttente())->toBeTrue();
});

/*
 * La marque vaut jusqu'à sa dernière seconde comprise, comme le lien signé
 * qui la pose.
 */
it('ouvre encore le compte à la dernière seconde de la marque du lien', function (): void {
    Notification::fake();
    $this->freezeSecond();
    $compte = ouvertureCompteEnAttente();

    get(LiensDesCourriels::lienDOuvertureDuCompte($compte))->assertRedirect(route('login'));
    $this->travel(LiensDesCourriels::minutesDeValiditeDUnLien())->minutes();

    ouvertureSeConnecter('inscrit@example.org', ouvertureMotDePasse())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($compte);
    expect($compte->refresh()->estEnAttente())->toBeFalse();
});

it('ouvre le compte en attente par une réinitialisation du mot de passe, sans le connecter', function (): void {
    Notification::fake();
    Event::fake([Verified::class]);
    $compte = ouvertureCompteEnAttente();
    $compte->forceFill(['name' => 'Nom-Choisi-Par-L-Intrus'])->save();
    $ouvert = User::factory()->create(['name' => 'Camille Martin', 'email' => 'ouvert@example.org']);

    foreach ([$compte, $ouvert] as $titulaire) {
        post(route('password.store'), [
            'token' => Password::createToken($titulaire),
            'email' => $titulaire->email,
            'password' => 'Le-mot-de-passe-du-titulaire-42!',
            'password_confirmation' => 'Le-mot-de-passe-du-titulaire-42!',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));
    }

    assertGuest();
    expect($compte->refresh()->estEnAttente())->toBeFalse()
        ->and($compte->hasVerifiedEmail())->toBeTrue()
        ->and($compte->name)->toBe('inscrit')
        ->and($ouvert->refresh()->name)->toBe('Camille Martin');
    Event::assertDispatchedTimes(Verified::class, 1);
    Event::assertDispatched(Verified::class, fn (Verified $evenement): bool => $evenement->user instanceof User && $evenement->user->is($compte));

    ouvertureSeConnecter('inscrit@example.org', ouvertureMotDePasse())->assertSessionHasErrors(['email' => __('auth.failed')]);
    ouvertureSeConnecter('inscrit@example.org', 'Le-mot-de-passe-du-titulaire-42!')->assertRedirect(route('dashboard'));
    assertAuthenticatedAs($compte);
});

/*
 * Une écriture qui change l'adresse ne prouve pas la nouvelle, même quand
 * elle pose la vérification (`SurveilleSonAdresse` la remet à null) : sans
 * quoi le compte, ni en attente ni vérifié, se connecterait comme un compte
 * d'avant la colonne.
 */
it('garde en attente un compte dont une même écriture change l’adresse et pose la vérification', function (): void {
    Notification::fake();
    $compte = ouvertureCompteEnAttente();

    $compte->forceFill(['email' => 'autre@example.org', 'email_verified_at' => now()])->save();

    $compte->refresh();
    expect($compte->email)->toBe('autre@example.org')
        ->and($compte->estEnAttente())->toBeTrue()
        ->and($compte->hasVerifiedEmail())->toBeFalse();

    ouvertureSeConnecter('autre@example.org', ouvertureMotDePasse())->assertSessionHasErrors(['email' => __('auth.failed')]);
    assertGuest();
});

it('rend au titulaire revenu par un fournisseur le compte en attente ouvert sur son adresse', function (): void {
    Notification::fake();
    Event::fake([Verified::class]);
    $compte = ouvertureCompteEnAttente('camille.martin@example.org');
    $compte->forceFill(['name' => 'Nom-Choisi-Par-L-Intrus'])->save();
    $jeton = (string) $compte->getRememberToken();

    $retour = new SocialiteUser()->map([
        'id' => '109876543210987654321',
        'nickname' => null,
        'name' => 'Camille Martin',
        'email' => 'camille.martin@example.org',
        'avatar' => 'https://example.org/avatar.jpg',
    ]);

    $repris = app(ResolveSocialUserAction::class)->execute('google', $retour, adresseVerifiee: true);

    $compte->refresh();
    expect($repris->is($compte))->toBeTrue()
        ->and($compte->estEnAttente())->toBeFalse()
        ->and($compte->hasVerifiedEmail())->toBeTrue()
        ->and($compte->name)->toBe('Camille Martin')
        ->and($compte->provider)->toBe('google')
        ->and($compte->provider_id)->toBe('109876543210987654321')
        ->and($compte->liaison_prouvee_le)->not->toBeNull()
        ->and($compte->getRememberToken())->not->toBe($jeton)
        ->and(strlen((string) $compte->getRememberToken()))->toBe(60)
        ->and($compte->avatar)->toBe('https://example.org/avatar.jpg')
        ->and(Hash::check(ouvertureMotDePasse(), $compte->getAuthPassword()))->toBeFalse()
        ->and(User::query()->count())->toBe(1);
    Event::assertDispatchedTimes(Verified::class, 1);
    Event::assertDispatched(Verified::class, fn (Verified $evenement): bool => $evenement->user instanceof User && $evenement->user->is($compte));

    // Le mot de passe choisi par l'inscrit ne vaut plus rien.
    ouvertureSeConnecter('camille.martin@example.org', ouvertureMotDePasse())->assertSessionHasErrors(['email' => __('auth.failed')]);
    assertGuest();
});

it('donne au compte repris le pseudonyme du fournisseur quand celui-ci ne rend pas de nom', function (): void {
    Notification::fake();
    $compte = ouvertureCompteEnAttente('camille.martin@example.org');
    $retour = new SocialiteUser()->map(['id' => '42', 'nickname' => 'camille', 'name' => null, 'email' => 'camille.martin@example.org', 'avatar' => null]);

    app(ResolveSocialUserAction::class)->execute('github', $retour, adresseVerifiee: true);

    expect($compte->refresh()->name)->toBe('camille')
        ->and($compte->estEnAttente())->toBeFalse();
});

it('ne rend pas le compte en attente à un fournisseur qui ne garantit pas l’adresse', function (): void {
    $compte = ouvertureCompteEnAttente('camille.martin@example.org');
    $retour = new SocialiteUser()->map(['id' => '42', 'nickname' => null, 'name' => 'X', 'email' => 'camille.martin@example.org', 'avatar' => null]);

    expect(fn (): User => app(ResolveSocialUserAction::class)->execute('google', $retour, adresseVerifiee: false))
        ->toThrow(App\Exceptions\SocialAuthException::class);
    expect($compte->refresh()->estEnAttente())->toBeTrue()
        ->and($compte->provider)->toBeNull();
});

it('ne donne à un compte en attente ni session ni accès à l’API', function (): void {
    Notification::fake();
    ouvertureCompteEnAttente();

    ouvertureSeConnecter('inscrit@example.org', ouvertureMotDePasse())->assertSessionHasErrors('email');

    assertGuest();
    postJson(route('api.v1.sets.store'), [])->assertUnauthorized();
    get(route('dashboard'))->assertRedirect(route('login'));
});

it('laisse se connecter comme avant un compte d’avant la colonne, vérifié ou non', function (): void {
    $ancien = User::factory()->unverified()->create(['email' => 'ancien@example.org', 'password' => Hash::make(ouvertureMotDePasse())]);
    $verifie = User::factory()->create(['email' => 'verifie@example.org', 'password' => Hash::make(ouvertureMotDePasse())]);

    ouvertureSeConnecter('ancien@example.org', ouvertureMotDePasse())->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
    assertAuthenticatedAs($ancien);
    expect($ancien->refresh()->hasVerifiedEmail())->toBeFalse();

    Auth::guard('web')->logout();

    ouvertureSeConnecter('verifie@example.org', ouvertureMotDePasse())->assertSessionHasNoErrors()->assertRedirect(route('dashboard'));
    assertAuthenticatedAs($verifie);
});

it('efface chaque nuit les comptes jamais ouverts au-delà du délai, et eux seuls', function (): void {
    $this->freezeSecond();
    $echu = ouvertureCompteEnAttente('echu@example.org');
    $echu->forceFill(['en_attente_depuis' => now()->subDays(User::JOURS_AVANT_LA_PURGE)->subMinute()])->save();
    $recent = ouvertureCompteEnAttente('recent@example.org');
    $recent->forceFill(['en_attente_depuis' => now()->subDays(User::JOURS_AVANT_LA_PURGE)->addMinute()])->save();
    $ancienNonVerifie = User::factory()->unverified()->create(['created_at' => now()->subYear()]);
    $ancienVerifie = User::factory()->create(['created_at' => now()->subYear()]);

    expect(Artisan::call('model:prune', ['--model' => [User::class]]))->toBe(0);

    expect(User::query()->whereKey($echu->id)->exists())->toBeFalse()
        ->and(User::query()->whereKey($recent->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($ancienNonVerifie->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($ancienVerifie->id)->exists())->toBeTrue();

    $purges = collect(app(Schedule::class)->events())
        ->filter(static fn (TachePlanifiee $tache): bool => str_contains((string) $tache->command, 'model:prune') && str_contains((string) $tache->command, User::class));

    expect($purges)->toHaveCount(1)
        ->and($purges->sole()->expression)->toBe('50 3 * * *');
});

it('écrit le lien d’ouverture en français, sur APP_URL, sans rien de l’inscription', function (): void {
    config(['app.url' => 'https://gym.example.org']);
    $compte = ouvertureCompteEnAttente();
    $compte->forceFill(['name' => 'Nom-Choisi-A-L-Inscription'])->save();

    $avis = new LienDOuvertureDuCompte();
    $courriel = $avis->toMail($compte);
    $rendu = (string) $courriel->render();

    expect($avis->afterCommit)->toBeTrue()
        ->and($avis->via($compte))->toBe(['mail'])
        ->and($courriel->subject)->toBe('Ouvre ton compte '.config()->string('app.name'))
        ->and((string) $courriel->actionUrl)->toStartWith('https://gym.example.org/inscription/ouvrir/'.$compte->id.'/'.sha1('inscrit@example.org').'?expires=')
        ->and($rendu)->toContain('au bout de '.User::JOURS_AVANT_LA_PURGE.' jours')
        ->and($rendu)->not->toContain('Nom-Choisi-A-L-Inscription');

    // Un compte qui n'est pas en attente reçoit toujours le lien de vérification de Laravel.
    Notification::fake();
    $ancien = User::factory()->unverified()->create();
    $ancien->sendEmailVerificationNotification();

    Notification::assertSentTo($ancien, Illuminate\Auth\Notifications\VerifyEmail::class);
    Notification::assertNotSentTo($ancien, LienDOuvertureDuCompte::class);
});
