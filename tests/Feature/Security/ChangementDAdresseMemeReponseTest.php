<?php

declare(strict_types=1);

use App\Actions\Auth\DonnerSuiteAUnChangementDAdresse;
use App\Jobs\TraiterUnChangementDAdresse;
use App\Models\User;
use App\Notifications\AdresseDuCompteChangee;
use App\Notifications\LienDeChangementDAdresse;
use App\Notifications\TentativeSurTonAdresse;
use App\Support\LiensDesCourriels;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\CeQueLeClientLit;
use Tests\Support\LienDeChangementDAdresseRecu;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/*
 * Le changement d'adresse du profil ne dit pas si la nouvelle adresse a déjà
 * un compte.
 *
 * Une adresse prise recevait l'erreur de la règle `unique` ; une adresse
 * libre remplaçait aussitôt celle du compte, et la page suivante la montrait.
 * Le profil enregistre désormais le nom, met `TraiterUnChangementDAdresse` en
 * file et répond toujours `lien-de-changement-d-adresse-envoye`, l'adresse du
 * compte inchangée. Le job envoie à la nouvelle adresse le lien qui la
 * confirme, ou prévient son titulaire ; seul ce lien, suivi depuis une session
 * du compte, change l'adresse (`ConfirmerLAdresseController`), et la vérifie.
 */

/**
 * Le compte qui change d'adresse, dont le mot de passe est `password`.
 */
function changementMemeReponseLeCompte(): User
{
    return User::factory()->create(['name' => 'Titulaire', 'email' => 'titulaire@example.org']);
}

/**
 * La demande de changement telle que la page la poste, depuis le profil, avec
 * une session et des limiteurs à neuf.
 *
 * @return TestResponse<Response>
 */
function changementMemeReponseDemander(User $compte, string $adresse): TestResponse
{
    test()->flushSession();
    Cache::flush();

    /** @var TestResponse<Response> $reponse */
    $reponse = actingAs($compte)
        ->from(route('profile.edit'))
        ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->patch(route('profile.update'), ['name' => 'Titulaire', 'email' => $adresse, 'current_password' => 'password']);

    // Les en-têtes d'Inertia ne suivent pas la requête suivante du test.
    test()->flushHeaders();

    return $reponse;
}

it('répond à l’identique, page suivante comprise, que la nouvelle adresse soit libre ou prise', function (): void {
    Notification::fake();
    $this->freezeSecond();
    $compte = changementMemeReponseLeCompte();
    User::factory()->create(['email' => 'prise@example.org']);
    User::factory()->enAttente()->create(['email' => 'en-attente@example.org']);
    User::factory()->unverified()->create(['email' => 'ancien@example.org']);

    $lire = static fn (string $adresse): array => CeQueLeClientLit::de(changementMemeReponseDemander($compte, $adresse), route('profile.edit'));

    $libre = $lire('libre@example.org');

    expect($libre['statut'])->toBe(303)
        ->and($libre['entetes']['Location'] ?? null)->toBe([route('profile.edit')])
        ->and($libre['session']['status'] ?? null)->toBe('lien-de-changement-d-adresse-envoye')
        ->and($libre['session'])->not->toHaveKey('errors')
        ->and($libre['page'])->toContain('titulaire@example.org')
        ->and($libre['page'])->not->toContain('libre@example.org')
        ->and($lire('prise@example.org'))->toBe($libre)
        ->and($lire('en-attente@example.org'))->toBe($libre)
        ->and($lire('ancien@example.org'))->toBe($libre);

    expect($compte->refresh()->email)->toBe('titulaire@example.org')
        ->and($compte->hasVerifiedEmail())->toBeTrue();
});

it('met la demande en file sans chercher la nouvelle adresse pendant la requête', function (): void {
    Queue::fake();
    $compte = changementMemeReponseLeCompte();
    User::factory()->create(['email' => 'prise@example.org']);

    $requetes = [];
    DB::listen(static function (QueryExecuted $requete) use (&$requetes): void {
        $requetes[] = ['sql' => $requete->sql, 'valeurs' => $requete->bindings];
    });

    changementMemeReponseDemander($compte, 'prise@example.org')->assertRedirect(route('profile.edit'));
    $requetesPrise = $requetes;
    $requetes = [];
    changementMemeReponseDemander($compte, 'libre@example.org')->assertRedirect(route('profile.edit'));

    expect(array_column($requetes, 'sql'))->toBe(array_column($requetesPrise, 'sql'))
        ->and(json_encode($requetes, JSON_THROW_ON_ERROR))->not->toContain('libre')
        ->and(json_encode($requetesPrise, JSON_THROW_ON_ERROR))->not->toContain('prise');

    Queue::assertPushed(TraiterUnChangementDAdresse::class, static fn (TraiterUnChangementDAdresse $job): bool => $job->idDuCompte === $compte->id
        && $job->adresse === 'libre@example.org'
        && $job->langue === 'fr');
});

it('chiffre la demande dans la file', function (): void {
    config(['queue.default' => 'database']);
    $compte = changementMemeReponseLeCompte();

    changementMemeReponseDemander($compte, 'nouvelle.adresse@example.org')->assertRedirect(route('profile.edit'));

    $charge = DB::table('jobs')->sole()->payload;
    assert(is_string($charge));
    expect($charge)->toContain('TraiterUnChangementDAdresse')
        ->and($charge)->not->toContain('nouvelle.adresse');
});

it('envoie le lien à une adresse libre, et ne change l’adresse qu’une fois ce lien suivi', function (): void {
    Notification::fake();
    Event::fake([Verified::class]);
    $compte = changementMemeReponseLeCompte();

    changementMemeReponseDemander($compte, 'libre@example.org')->assertSessionHasNoErrors();

    expect($compte->refresh()->email)->toBe('titulaire@example.org');
    Notification::assertSentOnDemandTimes(LienDeChangementDAdresse::class, 1);
    Notification::assertSentOnDemand(LienDeChangementDAdresse::class, static function (LienDeChangementDAdresse $avis, array $canaux, AnonymousNotifiable $destinataire): bool {
        $rendu = (string) $avis->toMail($destinataire)->render();

        return $destinataire->routes === ['mail' => 'libre@example.org']
            && $canaux === ['mail']
            && $avis->afterCommit === true
            && in_array(Illuminate\Contracts\Queue\ShouldBeEncrypted::class, class_implements($avis), true)
            && str_starts_with($avis->lien, config()->string('app.url').'/adresse/confirmer?')
            && str_contains($rendu, 'ne le deviendra qu’une fois ce lien suivi')
            && ! str_contains($rendu, 'titulaire@example.org')
            && ! str_contains($rendu, 'Titulaire');
    });
    Notification::assertNotSentTo($compte, TentativeSurTonAdresse::class);

    get(LienDeChangementDAdresseRecu::pour('libre@example.org'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', 'adresse-confirmee');

    $compte->refresh();
    expect($compte->email)->toBe('libre@example.org')
        ->and($compte->hasVerifiedEmail())->toBeTrue()
        ->and($compte->ancienne_adresse_verifiee)->toBeNull();
    Event::assertDispatched(Verified::class);
    Notification::assertSentOnDemand(
        AdresseDuCompteChangee::class,
        static fn (AdresseDuCompteChangee $avis, array $_canaux, AnonymousNotifiable $destinataire): bool => $destinataire->routes === ['mail' => 'titulaire@example.org']
            && $avis->nouvelleAdresse === 'libre@example.org',
    );
});

it('prévient le titulaire d’une adresse prise, au plus une fois par heure, sans lien ni changement', function (): void {
    Notification::fake();
    $compte = changementMemeReponseLeCompte();
    $titulaire = User::factory()->create(['name' => 'Autre Titulaire', 'email' => 'prise@example.org']);

    foreach (range(1, 3) as $_essai) {
        actingAs($compte)->patch(route('profile.update'), ['name' => 'Titulaire', 'email' => 'prise@example.org', 'current_password' => 'password'])
            ->assertSessionHasNoErrors();
    }

    Notification::assertSentToTimes($titulaire, TentativeSurTonAdresse::class, 1);
    Notification::assertSentTo($titulaire, TentativeSurTonAdresse::class, static function (TentativeSurTonAdresse $avis) use ($titulaire): bool {
        $courriel = $avis->toMail($titulaire);
        $rendu = (string) $courriel->render();

        return $courriel->subject === 'Un compte '.config()->string('app.name').' a demandé à prendre ton adresse'
            && str_contains($rendu, 'rien n’a changé')
            && ! str_contains($rendu, 'titulaire@example.org')
            && ! str_contains($rendu, 'Titulaire')
            && ! str_contains($rendu, '127.0.0.1');
    });
    Notification::assertSentOnDemandTimes(LienDeChangementDAdresse::class, 0);
    expect($compte->refresh()->email)->toBe('titulaire@example.org')
        ->and($titulaire->refresh()->email)->toBe('prise@example.org');
});

it('n’envoie pas plus d’un lien par quart d’heure à la même adresse', function (): void {
    Notification::fake();
    $compte = changementMemeReponseLeCompte();

    foreach (range(1, 3) as $_essai) {
        actingAs($compte)->patch(route('profile.update'), ['name' => 'Titulaire', 'email' => 'libre@example.org', 'current_password' => 'password'])
            ->assertSessionHasNoErrors();
    }

    Notification::assertSentOnDemandTimes(LienDeChangementDAdresse::class, 1);
    expect(DonnerSuiteAUnChangementDAdresse::SECONDES_ENTRE_DEUX_LIENS)->toBe(900);

    $this->travel(DonnerSuiteAUnChangementDAdresse::SECONDES_ENTRE_DEUX_LIENS + 1)->seconds();
    actingAs($compte)->patch(route('profile.update'), ['name' => 'Titulaire', 'email' => 'libre@example.org', 'current_password' => 'password']);

    Notification::assertSentOnDemandTimes(LienDeChangementDAdresse::class, 2);
});

it('ne fait partir que trois courriels par heure depuis un même compte, avis et liens confondus, sans que la réponse change', function (): void {
    Notification::fake();
    $compte = changementMemeReponseLeCompte();
    $premier = User::factory()->create(['email' => 'prise1@example.org']);
    $second = User::factory()->create(['email' => 'prise2@example.org']);
    $troisieme = User::factory()->create(['email' => 'prise3@example.org']);

    $demander = static function (string $adresse) use ($compte): void {
        actingAs($compte)
            ->patch(route('profile.update'), ['name' => 'Titulaire', 'email' => $adresse, 'current_password' => 'password'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'lien-de-changement-d-adresse-envoye');
    };

    $demander('prise1@example.org');
    $demander('prise2@example.org');

    foreach (range(1, 10) as $numero) {
        $demander("destinataire{$numero}@example.org");
    }

    $demander('prise3@example.org');

    Notification::assertSentToTimes($premier, TentativeSurTonAdresse::class, 1);
    Notification::assertSentToTimes($second, TentativeSurTonAdresse::class, 1);
    Notification::assertNotSentTo($troisieme, TentativeSurTonAdresse::class);
    Notification::assertSentOnDemandTimes(LienDeChangementDAdresse::class, 1);
    expect(TraiterUnChangementDAdresse::COURRIELS_PAR_HEURE_ET_PAR_COMPTE)->toBe(3);

    $this->travel(3601)->seconds();
    $demander('destinataire11@example.org');

    Notification::assertSentOnDemandTimes(LienDeChangementDAdresse::class, 2);
});

/**
 * Le lien de confirmation, où la valeur du paramètre `adresse` est remplacée.
 */
function changementMemeReponseAutreAdresseDansLeLien(string $lien, string $adresse): string
{
    return (string) preg_replace('/([?&]adresse=)[^&]+/', '${1}'.rawurlencode($adresse), $lien, 1);
}

/**
 * La valeur, décodée, d'un paramètre de la chaîne de requête du lien.
 */
function changementMemeReponseParametreDuLien(string $lien, string $nom): mixed
{
    parse_str((string) parse_url($lien, PHP_URL_QUERY), $parametres);

    return $parametres[$nom] ?? null;
}

it('refuse un lien d’un autre compte, altéré, ou périmé par un changement d’adresse', function (): void {
    Notification::fake();
    $compte = changementMemeReponseLeCompte();
    $autre = User::factory()->create(['email' => 'autre@example.org']);
    $lienDeLAutre = LiensDesCourriels::lienDeConfirmationDAdresse($autre, 'cible@example.org');
    $lien = LiensDesCourriels::lienDeConfirmationDAdresse($compte, 'libre@example.org');

    actingAs($compte)->get($lienDeLAutre)->assertNotFound();
    actingAs($compte)->get(changementMemeReponseAutreAdresseDansLeLien($lien, Crypt::encryptString('pirate@example.org')))->assertForbidden();
    expect($autre->refresh()->email)->toBe('autre@example.org');

    // Un autre lien a changé l'adresse entre-temps : le premier ne vaut plus.
    actingAs($compte)->get(LiensDesCourriels::lienDeConfirmationDAdresse($compte, 'entre-temps@example.org'))->assertSessionHas('status', 'adresse-confirmee');
    actingAs($compte)->get($lien)
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', 'lien-de-changement-d-adresse-perime');

    expect($compte->refresh()->email)->toBe('entre-temps@example.org');

    // Un lien expiré, et un invité qui suit le lien : la connexion d'abord.
    $tardif = LiensDesCourriels::lienDeConfirmationDAdresse($compte, 'tardive@example.org');
    $this->travel(LiensDesCourriels::minutesDeValiditeDUnLien() + 1)->minutes();
    actingAs($compte)->get($tardif)->assertForbidden();
    expect($compte->refresh()->email)->toBe('entre-temps@example.org');
});

/*
 * Un lien suivi en GET finit dans les journaux d'accès, l'historique du
 * navigateur, l'adresse que la connexion garde pour après et, si la requête
 * échoue, la trace de l'exception : la nouvelle adresse n'y paraît que
 * chiffrée, sous la signature.
 */
it('ne met la nouvelle adresse dans le lien que chiffrée, jusque dans la session de l’invité qui le suit', function (): void {
    Notification::fake();
    $compte = changementMemeReponseLeCompte();

    changementMemeReponseDemander($compte, 'nouvelle.adresse@example.org')->assertSessionHasNoErrors();
    $lien = LienDeChangementDAdresseRecu::pour('nouvelle.adresse@example.org');
    $chiffree = changementMemeReponseParametreDuLien($lien, 'adresse');

    expect($lien)->not->toContain('nouvelle')
        ->and(rawurldecode($lien))->not->toContain('nouvelle')
        ->and($chiffree)->toBeString();
    assert(is_string($chiffree));
    expect(Crypt::decryptString($chiffree))->toBe('nouvelle.adresse@example.org');

    Auth::logout();
    test()->flushSession();
    get($lien)->assertRedirect(route('login'));

    expect(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain('nouvelle');

    test()->post(route('login'), ['email' => 'titulaire@example.org', 'password' => 'password'])->assertRedirect($lien);
    get($lien)->assertSessionHas('status', 'adresse-confirmee');

    expect($compte->refresh()->email)->toBe('nouvelle.adresse@example.org');
});

it('refuse un lien signé dont l’adresse ne se déchiffre pas', function (): void {
    $compte = changementMemeReponseLeCompte();
    $lien = URL::temporarySignedRoute('adresse.confirmer', now()->addHour(), [
        'compte' => $compte->id,
        'adresse' => 'libre@example.org',
        'actuelle' => sha1($compte->email),
    ]);

    actingAs($compte)->get($lien)->assertForbidden();

    expect($compte->refresh()->email)->toBe('titulaire@example.org');
});

it('envoie un invité qui suit le lien vers la connexion', function (): void {
    $compte = changementMemeReponseLeCompte();

    get(LiensDesCourriels::lienDeConfirmationDAdresse($compte, 'libre@example.org'))->assertRedirect(route('login'));

    expect($compte->refresh()->email)->toBe('titulaire@example.org');
});

it('laisse le compte tel quel quand l’adresse est devenue celle d’un autre avant le lien', function (): void {
    Notification::fake();
    $compte = changementMemeReponseLeCompte();
    $lien = LiensDesCourriels::lienDeConfirmationDAdresse($compte, 'disputee@example.org');
    User::factory()->create(['email' => 'disputee@example.org']);

    actingAs($compte)->get($lien)
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', 'adresse-deja-prise');

    $compte->refresh();
    expect($compte->email)->toBe('titulaire@example.org')
        ->and($compte->hasVerifiedEmail())->toBeTrue();
    Notification::assertSentOnDemandTimes(AdresseDuCompteChangee::class, 0);
});

/*
 * Un tiers a inscrit l'adresse du membre, sans pouvoir ouvrir le compte. Le
 * membre, qui détient la boîte, doit pouvoir la prendre quand même.
 */
it('envoie le lien quand seule une inscription jamais ouverte tient l’adresse, et l’efface quand le lien est suivi', function (): void {
    Notification::fake();
    $compte = changementMemeReponseLeCompte();
    $inscription = User::factory()->enAttente()->create(['name' => 'Nom-Choisi-Par-L-Intrus', 'email' => 'boite-du-membre@example.org']);

    changementMemeReponseDemander($compte, 'boite-du-membre@example.org')->assertSessionHasNoErrors();

    Notification::assertSentOnDemandTimes(LienDeChangementDAdresse::class, 1);
    Notification::assertNotSentTo($inscription, TentativeSurTonAdresse::class);
    expect($compte->refresh()->email)->toBe('titulaire@example.org');

    actingAs($compte)->get(LienDeChangementDAdresseRecu::pour('boite-du-membre@example.org'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', 'adresse-confirmee');

    $compte->refresh();
    expect($compte->email)->toBe('boite-du-membre@example.org')
        ->and($compte->hasVerifiedEmail())->toBeTrue()
        ->and(User::query()->whereKey($inscription->id)->exists())->toBeFalse()
        ->and(User::query()->count())->toBe(1);
    Notification::assertSentOnDemand(
        AdresseDuCompteChangee::class,
        static fn (AdresseDuCompteChangee $_avis, array $_canaux, AnonymousNotifiable $destinataire): bool => $destinataire->routes === ['mail' => 'titulaire@example.org'],
    );
});

it('prévient, sans lien, le titulaire d’une inscription jamais ouverte sur une adresse qui n’est pas exactement celle demandée', function (): void {
    Notification::fake();
    $compte = changementMemeReponseLeCompte();
    $inscription = User::factory()->enAttente()->create(['email' => 'Boite-Du-Membre@example.org']);

    changementMemeReponseDemander($compte, 'boite-du-membre@example.org')->assertSessionHasNoErrors();

    Notification::assertSentOnDemandTimes(LienDeChangementDAdresse::class, 0);
    Notification::assertSentToTimes($inscription, TentativeSurTonAdresse::class, 1);
});

it('n’efface pas au lien suivi un compte ouvert entre-temps sur l’adresse', function (): void {
    Notification::fake();
    $compte = changementMemeReponseLeCompte();
    $inscription = User::factory()->enAttente()->create(['email' => 'disputee@example.org']);
    $lien = LiensDesCourriels::lienDeConfirmationDAdresse($compte, 'disputee@example.org');
    $inscription->markEmailAsVerified();

    actingAs($compte)->get($lien)->assertSessionHas('status', 'adresse-deja-prise');

    expect($compte->refresh()->email)->toBe('titulaire@example.org')
        ->and($inscription->refresh()->email)->toBe('disputee@example.org')
        ->and($inscription->estEnAttente())->toBeFalse();
});

it('répond « confirmée » au même lien suivi deux fois, sans rien réécrire', function (): void {
    Notification::fake();
    $compte = changementMemeReponseLeCompte();
    $lien = LiensDesCourriels::lienDeConfirmationDAdresse($compte, 'libre@example.org');

    actingAs($compte)->get($lien)->assertSessionHas('status', 'adresse-confirmee');
    actingAs($compte)->get($lien)->assertSessionHas('status', 'adresse-confirmee');

    expect($compte->refresh()->email)->toBe('libre@example.org');
    Notification::assertSentOnDemandTimes(AdresseDuCompteChangee::class, 1);
});
