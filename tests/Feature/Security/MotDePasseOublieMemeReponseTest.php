<?php

declare(strict_types=1);

use App\Actions\Auth\SendPasswordResetLinkAction;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Jobs\EnvoyerLeLienDeReinitialisation;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Support\MessageBag;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

use function Pest\Laravel\post;

/*
 * « Mot de passe oublié » ne dit pas si une adresse a un compte (#1951).
 *
 * Une adresse inconnue recevait une erreur de validation, une adresse connue un
 * message de succès ; une demande trop rapprochée, que le courtier ne refuse
 * que pour un compte existant, une troisième réponse. Et la demande d'une
 * adresse connue durait plus longtemps : le courtier y créait le jeton et
 * envoyait le courriel. La requête ne fait plus que mettre
 * `EnvoyerLeLienDeReinitialisation` en file : sa réponse, ses requêtes SQL et
 * donc son temps sont les mêmes pour toute adresse bien formée.
 *
 * La page suivante disait la même chose autrement : un jeton quelconque essayé
 * sur une adresse sans compte recevait « Nous ne trouvons aucun utilisateur
 * avec cette adresse email. », et sur un compte « Ce jeton … n'est pas
 * valide. ». Les deux reçoivent désormais le second. Le refus durait aussi plus
 * longtemps pour un compte qui venait de demander un lien : tout refus dure
 * désormais `NewPasswordController::DUREE_D_UN_REFUS_MICROSECONDES`.
 */

/**
 * L'adresse que chaque cas demande.
 */
function motDePasseOublieAdresse(): string
{
    return 'membre@example.org';
}

/**
 * Un envoi de formulaire comme la page le fait, depuis `$page`, comme s'il
 * était le premier de cet appareil : session et limiteur de débit à neuf.
 *
 * @param  array<string, string>  $donnees
 * @return TestResponse<Response>
 */
function motDePasseOublieEnvoyer(string $page, string $route, array $donnees): TestResponse
{
    test()->flushSession();
    Cache::flush();

    /** @var TestResponse<Response> $reponse */
    $reponse = test()->from($page)
        ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->post(route($route), $donnees);

    return $reponse;
}

/**
 * Une demande de lien pour cette adresse, depuis la page « Mot de passe oublié ».
 *
 * @return TestResponse<Response>
 */
function motDePasseOublieDemander(string $adresse): TestResponse
{
    return motDePasseOublieEnvoyer(route('password.request'), 'password.email', ['email' => $adresse]);
}

/**
 * Tout ce qu'un client peut lire d'une demande : statut, en-têtes, cookies,
 * corps, session, puis la page qu'il retrouve après la redirection.
 *
 * Seuls les jetons tirés au hasard à chaque requête en sont ôtés : la valeur
 * des cookies, le jeton CSRF de la session et le nonce de la CSP. L'en-tête
 * `Date` et le `Max-Age` des cookies suivent l'horloge du système, que
 * `freezeSecond()` n'arrête pas : un cookie se lit par ses attributs, dont son
 * échéance. Les erreurs de la session se lisent en tableaux : un sac d'erreurs
 * est un objet, et deux objets distincts ne sont jamais identiques.
 *
 * @param  TestResponse<Response>  $reponse
 * @return array{statut: int, entetes: array<mixed>, cookies: list<array<string, mixed>>, corps: string|false, session: array<mixed>, page: string}
 */
function motDePasseOublieCeQueLeClientLit(TestResponse $reponse, ?string $pageSuivante = null): array
{
    $enTetes = $reponse->headers->allPreserveCaseWithoutCookies();
    unset($enTetes['Date']);
    $enTetes['Content-Security-Policy'] = preg_replace("/'nonce-[^']+'/", "'nonce-…'", (string) $reponse->headers->get('Content-Security-Policy'));

    $cookies = array_values(array_map(static fn (Cookie $cookie): array => [
        'nom' => $cookie->getName(),
        'expire' => $cookie->getExpiresTime(),
        'chemin' => $cookie->getPath(),
        'domaine' => $cookie->getDomain(),
        'securise' => $cookie->isSecure(),
        'http' => $cookie->isHttpOnly(),
        'site' => $cookie->getSameSite(),
    ], $reponse->headers->getCookies()));

    $session = session()->all();
    unset($session['_token']);

    if (($session['errors'] ?? null) instanceof ViewErrorBag) {
        $session['errors'] = array_map(static fn (MessageBag $erreurs): array => $erreurs->toArray(), $session['errors']->getBags());
    }

    return [
        'statut' => $reponse->getStatusCode(),
        'entetes' => $enTetes,
        'cookies' => $cookies,
        'corps' => $reponse->getContent(),
        'session' => $session,
        'page' => json_encode(motDePasseOublieOuvrir($pageSuivante ?? route('password.request'))->inertiaPage(), JSON_THROW_ON_ERROR),
    ];
}

/**
 * La page où mène la redirection, chargée en entier.
 *
 * @return TestResponse<Response>
 */
function motDePasseOublieOuvrir(string $page): TestResponse
{
    /** @var TestResponse<Response> $reponse */
    $reponse = test()->flushHeaders()->get($page);

    return $reponse;
}

it('répond à l’identique pour une adresse inconnue, connue, puis limitée par le courtier', function (): void {
    Notification::fake();
    $this->freezeSecond();

    $inconnue = motDePasseOublieCeQueLeClientLit(motDePasseOublieDemander(motDePasseOublieAdresse()));

    $compte = User::factory()->create(['email' => motDePasseOublieAdresse()]);
    $connue = motDePasseOublieCeQueLeClientLit(motDePasseOublieDemander(motDePasseOublieAdresse()));

    // Le lien vient de partir : le courtier refuse la demande suivante (RESET_THROTTLED).
    $courtier = Password::broker();
    assert($courtier instanceof PasswordBroker);
    expect($courtier->getRepository()->recentlyCreatedToken($compte))->toBeTrue();
    $limitee = motDePasseOublieCeQueLeClientLit(motDePasseOublieDemander(motDePasseOublieAdresse()));

    expect($inconnue['statut'])->toBe(302)
        ->and($inconnue['session']['status'] ?? null)->toBe('Si un compte existe pour cette adresse, un lien de réinitialisation du mot de passe vient d\'y être envoyé.')
        ->and($inconnue['session'])->not->toHaveKey('errors')
        ->and($connue)->toBe($inconnue)
        ->and($limitee)->toBe($inconnue);

    Notification::assertSentToTimes($compte, ResetPassword::class, 1);
});

it('met la demande en file sans lire le compte ni ses jetons pendant la requête', function (): void {
    Queue::fake();
    Notification::fake();
    User::factory()->create(['email' => 'connue@example.org']);

    $requetes = [];
    DB::listen(static function (QueryExecuted $requete) use (&$requetes): void {
        $requetes[] = $requete->sql;
    });

    motDePasseOublieDemander('connue@example.org')->assertRedirect(route('password.request'));
    $requetesConnue = $requetes;
    $requetes = [];
    motDePasseOublieDemander('inconnue@example.org')->assertRedirect(route('password.request'));

    expect($requetes)->toBe($requetesConnue);
    expect(implode("\n", $requetes))->not->toContain('users');
    expect(implode("\n", $requetes))->not->toContain('password_reset_tokens');

    Notification::assertNothingSent();
    Queue::assertPushedTimes(EnvoyerLeLienDeReinitialisation::class, 2);
    Queue::assertPushed(EnvoyerLeLienDeReinitialisation::class, static fn (EnvoyerLeLienDeReinitialisation $job): bool => $job->adresse === 'connue@example.org' && $job->langue === 'fr');
    Queue::assertPushed(EnvoyerLeLienDeReinitialisation::class, static fn (EnvoyerLeLienDeReinitialisation $job): bool => $job->adresse === 'inconnue@example.org' && $job->langue === 'fr');
});

it('envoie depuis la file un courriel chiffré en attente, dans la langue de la demande, dont le lien réinitialise le mot de passe', function (): void {
    config(['queue.default' => 'database', 'app.url' => 'https://gym.example.org']);
    $compte = User::factory()->create(['email' => motDePasseOublieAdresse()]);

    motDePasseOublieDemander(motDePasseOublieAdresse())->assertRedirect(route('password.request'));

    $charge = DB::table('jobs')->sole()->payload;
    assert(is_string($charge));
    expect($charge)->toContain('EnvoyerLeLienDeReinitialisation');
    expect($charge)->not->toContain(Str::before(motDePasseOublieAdresse(), '@'));

    // Le worker tourne dans une autre langue que la requête qui a demandé le lien.
    app()->setLocale('en');
    expect(Artisan::call('queue:work', ['connection' => 'database', '--once' => true]))->toBe(0);

    expect(app()->getLocale())->toBe('en')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    $transport = Mail::mailer()->getSymfonyTransport();
    assert($transport instanceof ArrayTransport);
    expect($transport->messages())->toHaveCount(1);
    $envoi = $transport->messages()->sole();
    assert($envoi instanceof SentMessage);
    $message = $envoi->getOriginalMessage();
    assert($message instanceof Email);

    expect($message->getSubject())->toBe('Réinitialisez votre mot de passe')
        ->and($message->getTo()[0]->getAddress())->toBe(motDePasseOublieAdresse());

    $corps = html_entity_decode((string) $message->getHtmlBody(), ENT_QUOTES | ENT_HTML5);
    expect(preg_match('#https://gym\.example\.org/reset-password/([A-Za-z0-9]+)\?email=membre%40example\.org#', $corps, $lien))->toBe(1);

    post('/reset-password', [
        'token' => $lien[1] ?? '',
        'email' => motDePasseOublieAdresse(),
        'password' => 'Un-nouveau-mot-de-passe-2026!',
        'password_confirmation' => 'Un-nouveau-mot-de-passe-2026!',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    expect(Hash::check('Un-nouveau-mot-de-passe-2026!', (string) $compte->fresh()?->getAuthPassword()))->toBeTrue();
});

it('n’envoie rien pour une adresse sans compte, et ne crée aucun jeton', function (): void {
    Notification::fake();
    User::factory()->create(['email' => motDePasseOublieAdresse()]);

    motDePasseOublieDemander('personne@example.org')->assertSessionHasNoErrors();
    new EnvoyerLeLienDeReinitialisation('personne@example.org', 'fr')->handle();

    Notification::assertNothingSent();
    expect(DB::table('password_reset_tokens')->count())->toBe(0);
});

it('n’envoie pas de deuxième lien pendant la fenêtre du courtier, et en envoie un après', function (): void {
    Notification::fake();
    // La base garde la seconde du jeton : l'horloge part d'une seconde ronde.
    $this->freezeSecond();
    $compte = User::factory()->create(['email' => motDePasseOublieAdresse()]);

    motDePasseOublieDemander(motDePasseOublieAdresse())->assertSessionHasNoErrors();
    $this->travel(59)->seconds();
    motDePasseOublieDemander(motDePasseOublieAdresse())->assertSessionHasNoErrors();

    Notification::assertSentToTimes($compte, ResetPassword::class, 1);

    $this->travel(2)->seconds();
    motDePasseOublieDemander(motDePasseOublieAdresse())->assertSessionHasNoErrors();

    Notification::assertSentToTimes($compte, ResetPassword::class, 2);
});

it('n’envoie le lien qu’après la validation de la transaction qui le demande', function (): void {
    Notification::fake();
    $compte = User::factory()->create(['email' => motDePasseOublieAdresse()]);

    try {
        DB::transaction(static function () use ($compte): void {
            app(SendPasswordResetLinkAction::class)->execute($compte->email);

            Notification::assertNothingSent();

            throw new RuntimeException('Transaction annulée.');
        });
    } catch (RuntimeException) {
        // La transaction annulée ne doit rien avoir envoyé.
    }

    Notification::assertNothingSent();

    DB::transaction(static function () use ($compte): void {
        app(SendPasswordResetLinkAction::class)->execute($compte->email);
    });

    Notification::assertSentToTimes($compte, ResetPassword::class, 1);
});

it('garde le limiteur de débit de la route, le même pour toute adresse', function (): void {
    Queue::fake();
    User::factory()->create(['email' => 'connue@example.org']);

    foreach (range(1, 6) as $essai) {
        post(route('password.email'), ['email' => $essai % 2 === 0 ? 'connue@example.org' : 'inconnue@example.org'])
            ->assertRedirect();
    }

    post(route('password.email'), ['email' => 'connue@example.org'])->assertTooManyRequests();
    post(route('password.email'), ['email' => 'inconnue@example.org'])->assertTooManyRequests();

    Queue::assertPushedTimes(EnvoyerLeLienDeReinitialisation::class, 6);
});

it('ne laisse demander un lien au courtier que par le job', function (): void {
    $appels = [];

    foreach (['app', 'routes', 'bootstrap'] as $dossier) {
        foreach (File::allFiles(base_path($dossier)) as $fichier) {
            if ($fichier->getExtension() !== 'php') {
                continue;
            }

            if (preg_match('/->sendResetLink\(|::sendResetLink\(|sendPasswordResetNotification\(|->passwordReset\(/', $fichier->getContents()) === 1) {
                $appels[] = Str::after($fichier->getPathname(), base_path().'/');
            }
        }
    }

    expect($appels)->toBe(['app/Jobs/EnvoyerLeLienDeReinitialisation.php']);
});

/**
 * La page qu'ouvre un lien de réinitialisation, avec un jeton quelconque.
 */
function motDePasseOubliePageDuLien(): string
{
    return route('password.reset', ['token' => 'un-jeton-quelconque', 'email' => motDePasseOublieAdresse()]);
}

/**
 * Un essai de réinitialisation avec ce jeton quelconque, depuis sa page.
 *
 * @return TestResponse<Response>
 */
function motDePasseOublieReinitialiser(): TestResponse
{
    return motDePasseOublieEnvoyer(motDePasseOubliePageDuLien(), 'password.store', [
        'token' => 'un-jeton-quelconque',
        'email' => motDePasseOublieAdresse(),
        'password' => 'Un-nouveau-mot-de-passe-2026!',
        'password_confirmation' => 'Un-nouveau-mot-de-passe-2026!',
    ]);
}

it('refuse à l’identique, à la réinitialisation, une adresse sans compte et le jeton faux d’un compte', function (): void {
    $this->freezeSecond();

    $sansCompte = motDePasseOublieCeQueLeClientLit(motDePasseOublieReinitialiser(), motDePasseOubliePageDuLien());

    $compte = User::factory()->create(['email' => motDePasseOublieAdresse()]);
    Password::createToken($compte);
    $jetonFaux = motDePasseOublieCeQueLeClientLit(motDePasseOublieReinitialiser(), motDePasseOubliePageDuLien());

    expect($sansCompte['statut'])->toBe(302)
        ->and($sansCompte['session']['errors'] ?? null)->toBe(['default' => ['email' => ['Ce jeton de réinitialisation du mot de passe n\'est pas valide.']]])
        ->and($jetonFaux)->toBe($sansCompte)
        ->and(Hash::check('password', (string) $compte->fresh()?->getAuthPassword()))->toBeTrue();
});

/**
 * Un essai de réinitialisation avec le jeton quelconque, et sa durée en
 * microsecondes.
 *
 * @return array{0: float, 1: TestResponse<Response>}
 */
function motDePasseOublieChronometrerLeRefus(): array
{
    $debut = microtime(true);
    $reponse = motDePasseOublieReinitialiser();

    return [(microtime(true) - $debut) * 1_000_000, $reponse];
}

/**
 * Le coût bcrypt que `.env.example` donne à la production, et que phpunit.xml
 * baisse à 4.
 */
function motDePasseOublieCoutDeProduction(): int
{
    expect(preg_match('/^BCRYPT_ROUNDS=(\d+)$/m', File::get(base_path('.env.example')), $cout))->toBe(1);

    return (int) $cout[1];
}

/*
 * Le courtier ne vérifie l'empreinte du jeton essayé (bcrypt) que pour un
 * compte qui a une demande récente, et au coût de production cette
 * vérification dépasse sa propre Timebox : le refus durait alors plus
 * longtemps que pour une adresse sans compte. Ici, la demande récente est celle
 * que « Mot de passe oublié » vient de faire pour le compte.
 */
it('refuse dans le même temps, à la réinitialisation, une adresse sans compte et le jeton faux d’un compte qui vient de demander un lien', function (): void {
    $plancher = NewPasswordController::DUREE_D_UN_REFUS_MICROSECONDES;

    [$dureeSansCompte, $sansCompte] = motDePasseOublieChronometrerLeRefus();
    $sansCompte->assertSessionHasErrors(['email' => 'Ce jeton de réinitialisation du mot de passe n\'est pas valide.']);

    $compte = User::factory()->create(['email' => motDePasseOublieAdresse()]);
    // Le jeton s'enregistre au coût de production, que sa vérification paiera.
    config(['hashing.bcrypt.rounds' => motDePasseOublieCoutDeProduction()]);
    Hash::forgetDrivers();
    Password::createToken($compte);

    [$dureeCompte, $jetonFaux] = motDePasseOublieChronometrerLeRefus();
    $jetonFaux->assertSessionHasErrors(['email' => 'Ce jeton de réinitialisation du mot de passe n\'est pas valide.']);

    // Le travail du refus le plus long, hors du plancher : la Timebox du courtier, puis la vérification de l'empreinte.
    $debut = microtime(true);
    expect(Password::reset([
        'token' => 'un-jeton-quelconque',
        'email' => motDePasseOublieAdresse(),
        'password' => 'Un-nouveau-mot-de-passe-2026!',
        'password_confirmation' => 'Un-nouveau-mot-de-passe-2026!',
    ], static function (): void {
    }))->toBe(Password::INVALID_TOKEN);
    $travail = (microtime(true) - $debut) * 1_000_000;

    expect($travail)->toBeLessThan($plancher)
        ->and($dureeSansCompte)->toBeGreaterThanOrEqual($plancher)
        ->and($dureeCompte)->toBeGreaterThanOrEqual($plancher);
});

it('répond à une réinitialisation réussie sans attendre le plancher des refus', function (): void {
    $compte = User::factory()->create(['email' => motDePasseOublieAdresse()]);
    $jeton = Password::createToken($compte);

    $debut = microtime(true);
    post(route('password.store'), [
        'token' => $jeton,
        'email' => motDePasseOublieAdresse(),
        'password' => 'Un-nouveau-mot-de-passe-2026!',
        'password_confirmation' => 'Un-nouveau-mot-de-passe-2026!',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    expect((microtime(true) - $debut) * 1_000_000)->toBeLessThan(NewPasswordController::DUREE_D_UN_REFUS_MICROSECONDES);
});
