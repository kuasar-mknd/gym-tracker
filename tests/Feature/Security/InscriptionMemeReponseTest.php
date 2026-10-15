<?php

declare(strict_types=1);

use App\Actions\Auth\PrevenirLeTitulaireDeLAdresse;
use App\Jobs\TraiterUneInscription;
use App\Models\User;
use App\Notifications\LienDOuvertureDuCompte;
use App\Notifications\TentativeSurTonAdresse;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\CeQueLeClientLit;

use function Pest\Laravel\assertGuest;
use function Pest\Laravel\post;

/*
 * L'inscription ne dit pas si une adresse a déjà un compte.
 *
 * Une adresse prise recevait « La valeur du champ adresse e-mail est déjà
 * utilisée. » en quelques millisecondes ; une adresse libre ouvrait une
 * session et menait au tableau de bord, après le hachage du mot de passe.
 * La requête ne cherche plus le compte : elle hache le mot de passe et met
 * `TraiterUneInscription` en file, puis répond toujours par la page de
 * connexion et `auth.inscription_recue`, sans connecter personne. Le job crée
 * le compte en attente du lien envoyé à son adresse, ou prévient le titulaire
 * de l'adresse prise. La durée est l'affaire de
 * `DureeDesReponsesDAuthentificationTest`.
 */

/**
 * L'inscription telle que la page la poste, comme la première de cet
 * appareil : session à neuf, et limiteurs à neuf sauf demande contraire (le
 * limiteur de la route écrit ce qu'il reste d'essais dans les en-têtes).
 *
 * @return TestResponse<Response>
 */
function inscriptionEnvoyer(string $adresse, string $nom = 'Camille Intrus', bool $limiteursANeuf = true): TestResponse
{
    test()->flushSession();

    if ($limiteursANeuf) {
        Cache::flush();
    }

    /** @var TestResponse<Response> $reponse */
    $reponse = test()->from(route('register'))
        ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->post(route('register'), [
            'name' => $nom,
            'email' => $adresse,
            'password' => 'Un-mot-de-passe-solide-42!',
            'password_confirmation' => 'Un-mot-de-passe-solide-42!',
        ]);

    // Les en-têtes d'Inertia ne suivent pas la requête suivante du test.
    test()->flushHeaders();

    return $reponse;
}

/**
 * @return array{statut: int, entetes: array<mixed>, cookies: list<array<string, mixed>>, corps: string|false, session: array<mixed>, page: string}
 */
function inscriptionCeQueLeClientLit(string $adresse): array
{
    $lu = CeQueLeClientLit::de(inscriptionEnvoyer($adresse), route('login'));
    assertGuest();

    return $lu;
}

it('répond à l’identique pour une adresse libre, prise, prise par un compte en attente ou par un ancien compte non vérifié', function (): void {
    Notification::fake();
    $this->freezeSecond();

    User::factory()->create(['email' => 'prise@example.org']);
    User::factory()->enAttente()->create(['email' => 'en-attente@example.org']);
    User::factory()->unverified()->create(['email' => 'ancien@example.org']);

    $libre = inscriptionCeQueLeClientLit('libre@example.org');

    expect($libre['statut'])->toBe(302)
        ->and($libre['entetes']['Location'] ?? null)->toBe([route('login')])
        ->and($libre['session']['status'] ?? null)->toBe(__('auth.inscription_recue'))
        ->and($libre['session'])->not->toHaveKey('errors')
        ->and(inscriptionCeQueLeClientLit('prise@example.org'))->toBe($libre)
        ->and(inscriptionCeQueLeClientLit('en-attente@example.org'))->toBe($libre)
        ->and(inscriptionCeQueLeClientLit('ancien@example.org'))->toBe($libre);
});

/**
 * Ce que lit le client de la connexion qui suit son inscription, avec le mot
 * de passe qu'il vient d'y choisir, une fois le job passé. L'adresse tapée
 * revient dans le champ (`_old_input`) : elle se remplace par un repère.
 *
 * @return array{statut: int, entetes: array<mixed>, cookies: list<array<string, mixed>>, corps: string|false, session: array<mixed>, page: string}
 */
function inscriptionPuisConnexion(string $adresse): array
{
    inscriptionEnvoyer($adresse)->assertRedirect(route('login'));

    test()->flushSession();
    Cache::flush();
    Auth::forgetGuards();

    /** @var TestResponse<Response> $reponse */
    $reponse = test()->from(route('login'))->post(route('login'), ['email' => $adresse, 'password' => 'Un-mot-de-passe-solide-42!']);

    $lu = CeQueLeClientLit::de($reponse, route('login'));
    assertGuest();

    expect($lu['session']['_old_input'] ?? null)->toBe(['email' => $adresse]);
    $lu['session']['_old_input'] = ['email' => 'l’adresse tapée'];

    return $lu;
}

/*
 * Inscrite libre, l'adresse a désormais un compte en attente dont le client
 * connaît le mot de passe ; prise, elle a le compte d'un autre, que ce mot de
 * passe n'ouvre pas. La connexion qui suit ne doit pas les distinguer.
 */
it('ne le dit pas non plus à la connexion qui suit, avec le mot de passe choisi à l’inscription', function (): void {
    Notification::fake();
    $this->freezeSecond();

    User::factory()->create(['email' => 'prise@example.org']);
    User::factory()->enAttente()->create(['email' => 'en-attente@example.org']);
    User::factory()->unverified()->create(['email' => 'ancien@example.org']);

    $libre = inscriptionPuisConnexion('libre@example.org');

    expect(User::query()->where('email', 'libre@example.org')->sole()->estEnAttente())->toBeTrue()
        ->and($libre['statut'])->toBe(302)
        ->and($libre['entetes']['Location'] ?? null)->toBe([route('login')])
        ->and($libre['session']['errors'] ?? null)->toBe(['default' => ['email' => [__('auth.failed')]]])
        ->and(inscriptionPuisConnexion('prise@example.org'))->toBe($libre)
        ->and(inscriptionPuisConnexion('en-attente@example.org'))->toBe($libre)
        ->and(inscriptionPuisConnexion('ancien@example.org'))->toBe($libre);
});

it('met l’inscription en file sans lire les comptes, avec l’empreinte du mot de passe et non le mot de passe', function (): void {
    Queue::fake();
    User::factory()->create(['email' => 'prise@example.org']);

    $requetes = [];
    DB::listen(static function (QueryExecuted $requete) use (&$requetes): void {
        $requetes[] = $requete->sql;
    });

    inscriptionEnvoyer('prise@example.org')->assertRedirect(route('login'));
    $requetesPrise = $requetes;
    $requetes = [];
    inscriptionEnvoyer('libre@example.org')->assertRedirect(route('login'));

    expect($requetes)->toBe($requetesPrise)
        ->and(implode("\n", $requetes))->not->toContain('users');

    Queue::assertPushedTimes(TraiterUneInscription::class, 2);
    Queue::assertPushed(TraiterUneInscription::class, static fn (TraiterUneInscription $job): bool => $job->adresse === 'libre@example.org'
        && $job->nom === 'Camille Intrus'
        && $job->langue === 'fr'
        && $job->empreinte !== 'Un-mot-de-passe-solide-42!'
        && Hash::check('Un-mot-de-passe-solide-42!', $job->empreinte));
});

it('chiffre l’inscription dans la file, et ne la met en file qu’une fois la transaction validée', function (): void {
    config(['queue.default' => 'database']);

    inscriptionEnvoyer('camille.intrus@example.org')->assertRedirect(route('login'));

    $charge = DB::table('jobs')->sole()->payload;
    assert(is_string($charge));
    expect($charge)->toContain('TraiterUneInscription')
        ->and($charge)->not->toContain('camille.intrus')
        ->and($charge)->not->toContain('Camille Intrus');

    DB::table('jobs')->delete();

    try {
        DB::transaction(static function (): void {
            TraiterUneInscription::dispatch('annulee@example.org', 'Annulée', Hash::make('x'), 'fr');

            throw new RuntimeException('Transaction annulée.');
        });
    } catch (RuntimeException) {
        // La transaction annulée ne doit rien avoir mis en file.
    }

    expect(DB::table('jobs')->count())->toBe(0);
});

it('crée pour une adresse libre un compte en attente, qui reçoit le lien qui l’ouvre, et ne connecte personne', function (): void {
    Notification::fake();

    inscriptionEnvoyer('libre@example.org', 'Camille Martin')->assertRedirect(route('login'));

    $compte = User::query()->where('email', 'libre@example.org')->sole();

    expect($compte->name)->toBe('Camille Martin')
        ->and($compte->estEnAttente())->toBeTrue()
        ->and($compte->hasVerifiedEmail())->toBeFalse()
        ->and(Hash::check('Un-mot-de-passe-solide-42!', $compte->getAuthPassword()))->toBeTrue()
        ->and($compte->plates()->count())->toBe(7);

    Notification::assertSentToTimes($compte, LienDOuvertureDuCompte::class, 1);
    Notification::assertNotSentTo($compte, TentativeSurTonAdresse::class);
    assertGuest();
});

it('prévient le titulaire d’une adresse prise, une fois par heure au plus, sans rien recopier de la requête', function (): void {
    Notification::fake();
    $this->freezeSecond();
    $titulaire = User::factory()->create(['name' => 'Titulaire', 'email' => 'prise@example.org']);
    $empreinte = $titulaire->getAuthPassword();

    inscriptionEnvoyer('prise@example.org', 'Nom-Choisi-Par-L-Intrus', limiteursANeuf: false);
    $this->travel(59)->minutes();
    inscriptionEnvoyer('prise@example.org', limiteursANeuf: false);

    Notification::assertSentToTimes($titulaire, TentativeSurTonAdresse::class, 1);

    $this->travel(PrevenirLeTitulaireDeLAdresse::SECONDES_ENTRE_DEUX_AVIS - 59 * 60 + 1)->seconds();
    inscriptionEnvoyer('prise@example.org', limiteursANeuf: false);

    Notification::assertSentToTimes($titulaire, TentativeSurTonAdresse::class, 2);
    Notification::assertNotSentTo($titulaire, LienDOuvertureDuCompte::class);

    Notification::assertSentTo($titulaire, TentativeSurTonAdresse::class, function (TentativeSurTonAdresse $avis, array $canaux) use ($titulaire): bool {
        $courriel = $avis->toMail($titulaire);
        $rendu = (string) $courriel->render();

        return $canaux === ['mail']
            && $avis->afterCommit === true
            && $courriel->subject === 'Une inscription à '.config()->string('app.name').' a été demandée avec ton adresse'
            && str_contains($rendu, 'aucun autre n’a été créé')
            && str_contains($rendu, 'connecte-toi avec cette adresse')
            && str_contains($rendu, 'Mot de passe oublié ?')
            && str_contains($rendu, route('login'))
            && ! str_contains($rendu, 'Nom-Choisi-Par-L-Intrus')
            && ! str_contains($rendu, 'Camille Intrus')
            && ! str_contains($rendu, '127.0.0.1')
            && ! str_contains($rendu, 'Un-mot-de-passe-solide-42!');
    });

    $titulaire->refresh();
    expect(User::query()->count())->toBe(1)
        ->and($titulaire->name)->toBe('Titulaire')
        ->and($titulaire->getAuthPassword())->toBe($empreinte);
});

it('dit au titulaire d’un compte en attente comment l’ouvrir', function (): void {
    Notification::fake();
    $compte = User::factory()->enAttente()->create(['email' => 'en-attente@example.org']);

    inscriptionEnvoyer('en-attente@example.org');

    Notification::assertSentTo($compte, TentativeSurTonAdresse::class, function (TentativeSurTonAdresse $avis) use ($compte): bool {
        $rendu = (string) $avis->toMail($compte)->render();

        return str_contains($rendu, 'Ton compte n’est pas encore ouvert')
            && str_contains($rendu, 'Mot de passe oublié ?')
            && str_contains($rendu, 'au bout de '.User::JOURS_AVANT_LA_PURGE.' jours');
    });
    expect(User::query()->count())->toBe(1);
});

it('ne prévient personne quand le job est rejoué après avoir créé le compte', function (): void {
    Notification::fake();
    $job = new TraiterUneInscription('libre@example.org', 'Camille Martin', Hash::make('Un-mot-de-passe-solide-42!'), 'fr');

    app()->call($job->handle(...));
    app()->call($job->handle(...));

    $compte = User::query()->where('email', 'libre@example.org')->sole();

    Notification::assertSentToTimes($compte, LienDOuvertureDuCompte::class, 1);
    Notification::assertNotSentTo($compte, TentativeSurTonAdresse::class);

    // Une autre inscription de la même adresse, elle, prévient le compte en attente.
    app()->call(new TraiterUneInscription('libre@example.org', 'Autre', Hash::make('Un-autre-mot-de-passe-42!'), 'fr')->handle(...));

    Notification::assertSentToTimes($compte, TentativeSurTonAdresse::class, 1);
});

it('prévient le titulaire quand une inscription concurrente prend l’adresse juste avant', function (): void {
    Notification::fake();
    $concurrente = null;

    User::creating(static function (User $compte) use (&$concurrente): void {
        if ($concurrente !== null || $compte->email !== 'disputee@example.org') {
            return;
        }

        $concurrente = User::factory()->enAttente()->createQuietly(['email' => 'disputee@example.org']);
    });

    app()->call(new TraiterUneInscription('disputee@example.org', 'Second', Hash::make('Un-mot-de-passe-solide-42!'), 'fr')->handle(...));

    expect($concurrente)->toBeInstanceOf(User::class)
        ->and(User::query()->where('email', 'disputee@example.org')->count())->toBe(1);
    assert($concurrente instanceof User);
    Notification::assertSentToTimes($concurrente, TentativeSurTonAdresse::class, 1);
});

it('cherche le compte comme l’index unique le juge, à la casse près', function (): void {
    Notification::fake();
    $titulaire = User::factory()->create(['email' => 'Camille.Martin@Example.org']);

    inscriptionEnvoyer('camille.martin@example.org')->assertRedirect(route('login'));

    expect(User::query()->count())->toBe(1);
    Notification::assertSentToTimes($titulaire, TentativeSurTonAdresse::class, 1);
});

it('garde le limiteur de la route, le même pour toute adresse', function (): void {
    Queue::fake();
    User::factory()->create(['email' => 'prise@example.org']);
    $donnees = static fn (string $adresse): array => [
        'name' => 'Camille Intrus',
        'email' => $adresse,
        'password' => 'Un-mot-de-passe-solide-42!',
        'password_confirmation' => 'Un-mot-de-passe-solide-42!',
    ];

    foreach (range(1, 6) as $essai) {
        post(route('register'), $donnees($essai % 2 === 0 ? 'prise@example.org' : 'libre'.$essai.'@example.org'))
            ->assertRedirect(route('login'));
    }

    post(route('register'), $donnees('prise@example.org'))->assertTooManyRequests();
    post(route('register'), $donnees('libre@example.org'))->assertTooManyRequests();

    Queue::assertPushedTimes(TraiterUneInscription::class, 6);
});

it('borne les avis sous une clef qui nomme le compte, jamais l’adresse', function (): void {
    Notification::fake();
    $titulaire = User::factory()->create(['email' => 'prise@example.org']);

    inscriptionEnvoyer('prise@example.org');

    expect(RateLimiter::attempts('avis-sur-l-adresse:'.$titulaire->id))->toBe(1)
        ->and(RateLimiter::attempts('avis-sur-l-adresse:prise@example.org'))->toBe(0);
});
