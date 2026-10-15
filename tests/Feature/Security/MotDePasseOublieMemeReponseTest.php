<?php

declare(strict_types=1);

use App\Actions\Auth\SendPasswordResetLinkAction;
use App\Jobs\EnvoyerLeLienDeReinitialisation;
use App\Models\User;
use App\Support\DureeDesReponsesDAuthentification;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Passwords\PasswordBroker;
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
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\CeQueLeClientLit;
use Tests\Support\DureeSimulee;

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
 * désormais au moins le plancher commun des réponses d'authentification
 * (`DureeDesReponsesDAuthentification`), mesuré ici sans dormir pour de vrai
 * (`DureeSimulee`).
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
 * Tout ce qu'un client peut lire d'une demande, puis de la page qu'il retrouve
 * après la redirection (`CeQueLeClientLit`).
 *
 * @param  TestResponse<Response>  $reponse
 * @return array{statut: int, entetes: array<mixed>, cookies: list<array<string, mixed>>, corps: string|false, session: array<mixed>, page: string}
 */
function motDePasseOublieCeQueLeClientLit(TestResponse $reponse, ?string $pageSuivante = null): array
{
    return CeQueLeClientLit::de($reponse, $pageSuivante ?? route('password.request'));
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

/**
 * Ce code demande-t-il ou envoie-t-il un lien de réinitialisation : demande
 * de lien (`sendResetLink`), création d'un jeton par la façade, par un
 * courtier nommé ou par le dépôt de la façade, avis `ResetPassword` construit
 * à la main, ou page « Mot de passe oublié » du panneau ? Un courtier pris
 * autrement (conteneur, injection) est l'affaire de
 * `motDePasseOublieAtteintLeCourtier()`. La garde lit le texte : une écriture
 * qu'elle ne connaît pas lui échappe, et ne vaut pas permission.
 */
function motDePasseOublieEnvoieUnLien(string $code): bool
{
    return preg_match(
        '/->sendResetLink\(|::sendResetLink\(|sendPasswordResetNotification\(|->passwordReset\('
        .'|Password::(?:createToken|getRepository)\(|broker\([^;]*?\)->(?:createToken|getRepository)\('
        .'|\bnew\s+[\w\\\\]*ResetPassword\b|ResetPassword::class|\bextends\s+[\w\\\\]*ResetPassword\b/',
        $code,
    ) === 1;
}

it('reconnaît chaque façon connue de demander ou d’envoyer un lien', function (string $code, bool $envoie): void {
    expect(motDePasseOublieEnvoieUnLien($code))->toBe($envoie);
})->with([
    'le courtier par la façade' => ['Password::sendResetLink([\'email\' => $adresse]);', true],
    'un courtier nommé' => ['Password::broker(\'users\')->sendResetLink($donnees);', true],
    'l’avis du compte' => ['$user->sendPasswordResetNotification($jeton);', true],
    'la page du panneau' => ['->passwordReset()', true],
    'un jeton par la façade' => ['$user->notify(new ResetPassword(Password::createToken($user)));', true],
    'un jeton par un courtier' => ['$jeton = app(\'auth.password\')->broker(config(\'auth.defaults.passwords\'))->createToken($user);', true],
    'un jeton par le dépôt' => ['Password::getRepository()->create($user);', true],
    'l’avis nommé en entier' => ['$user->notify(new \\Illuminate\\Auth\\Notifications\\ResetPassword($jeton));', true],
    'l’avis par sa classe' => ['Notification::send($user, app(ResetPassword::class, [\'token\' => $jeton]));', true],
    'un avis dérivé' => ['final class Lien extends ResetPassword {}', true],
    'un avis maison' => ['$user->notify(new LienDeReinitialisationResetPassword($jeton));', true],
    'le jeton d’API de Sanctum' => ['$user->createToken(\'api\')->plainTextToken;', false],
    'le lien des courriels' => ['ResetPassword::createUrlUsing(LiensDesCourriels::lienDeReinitialisation(...));', false],
    'les migrations jouées' => ['$migrator->getRepository()->getRan();', false],
    'l’import de l’avis' => ['use Illuminate\\Auth\\Notifications\\ResetPassword;', false],
    'un contrôleur au nom voisin' => ['Route::post(\'x\', [ResetPasswordController::class, \'store\']);', false],
]);

/**
 * Le code PHP de app, bootstrap et routes, par chemin depuis la racine du
 * dépôt. bootstrap/cache, que le framework écrit, n'en est pas : il nomme les
 * clefs du conteneur que chaque fournisseur déclare.
 *
 * @return array<string, string>
 */
function motDePasseOublieCodeDeLApplication(): array
{
    $codes = [];

    foreach (['app', 'bootstrap', 'routes'] as $dossier) {
        foreach (File::allFiles(base_path($dossier)) as $fichier) {
            $chemin = Str::after($fichier->getPathname(), base_path().'/');

            if ($fichier->getExtension() !== 'php' || str_starts_with($chemin, 'bootstrap/cache/')) {
                continue;
            }

            $codes[$chemin] = $fichier->getContents();
        }
    }

    ksort($codes);

    return $codes;
}

it('ne laisse demander ou envoyer un lien que par le job', function (): void {
    $appels = array_keys(array_filter(motDePasseOublieCodeDeLApplication(), motDePasseOublieEnvoieUnLien(...)));

    expect($appels)->toBe(['app/Jobs/EnvoyerLeLienDeReinitialisation.php']);
});

/**
 * Ce code atteint-il le courtier des mots de passe ou ses jetons ? Par la
 * façade (importée, même en groupe ou sous un autre nom, nommée en entier,
 * ou par l'alias global `\Password`), par un appel propre au courtier sur un
 * `Password::` dont l'import manque (routes), par une clef du conteneur
 * (`auth.password`, `auth.password.broker`), par le contrat ou la classe du
 * courtier, de sa fabrique ou de son dépôt, ou par la table des jetons. La
 * règle de validation `Password` et la clef de traduction homonyme
 * (`__('auth.password')`) n'en sont pas. La garde lit le texte : une écriture
 * qu'elle ne connaît pas lui échappe, et ne vaut pas permission.
 */
function motDePasseOublieAtteintLeCourtier(string $code): bool
{
    return preg_match(
        '/Facades\\\\(?:\{[^}]*\b)?Password\b|(?<![\w\\\\])\\\\Password::|\buse\s+\\\\?Password\b'
        .'|\bPassword::(?:sendResetLink|createToken|deleteToken|tokenExists|reset|getUser|getRepository|broker)\('
        .'|(?<!__\(|trans\()[\'"]auth\.password(?:\.broker)?[\'"]'
        .'|\bPasswordBroker(?:Factory|Manager)?\b'
        .'|\b(?:TokenRepositoryInterface|DatabaseTokenRepository|CacheTokenRepository)\b'
        .'|[\'"]password_reset_tokens[\'"]/',
        $code,
    ) === 1;
}

/**
 * Les méthodes du courtier ou de son dépôt que ce code appelle, quel que soit
 * l'objet qui les reçoit, triées et sans doublon.
 *
 * @return list<string>
 */
function motDePasseOublieUsagesDuCourtier(string $code): array
{
    preg_match_all(
        '/(?:::|->)(sendResetLink|createToken|deleteToken|deleteExpired|tokenExists|recentlyCreatedToken|reset|getUser|getRepository|broker)\(/',
        $code,
        $appels,
    );

    $usages = array_values(array_unique($appels[1]));
    sort($usages);

    return $usages;
}

it('reconnaît chaque façon connue d’atteindre le courtier', function (string $code, bool $atteint): void {
    expect(motDePasseOublieAtteintLeCourtier($code))->toBe($atteint);
})->with([
    'la façade importée' => ['use Illuminate\\Support\\Facades\\Password;', true],
    'la façade sous un autre nom' => ['use Illuminate\\Support\\Facades\\Password as Courtier;', true],
    'la façade dans un import groupé' => ['use Illuminate\\Support\\Facades\\{Hash, Password};', true],
    'la façade nommée en entier' => ['\\Illuminate\\Support\\Facades\\Password::createToken($user);', true],
    'l’alias global' => ['\\Password::sendResetLink($donnees);', true],
    'l’alias global importé' => ['use Password;', true],
    'la façade sans import, dans un fichier de routes' => ['return Password::reset($donnees, static function (): void {});', true],
    'le courtier du conteneur' => ['return app(\'auth.password.broker\')->createToken($user);', true],
    'la fabrique du conteneur' => ['$this->app[\'auth.password\']->broker()->reset($donnees, $rappel);', true],
    'le contrat du courtier' => ['app(PasswordBroker::class)->createToken($user);', true],
    'la fabrique injectée' => ['public function __construct(private PasswordBrokerFactory $courtiers) {}', true],
    'le dépôt des jetons' => ['app(TokenRepositoryInterface::class)->create($user);', true],
    'la table des jetons' => ['DB::table(\'password_reset_tokens\')->insert($ligne);', true],
    'la règle de validation' => ['use Illuminate\\Validation\\Rules\\Password;', false],
    'ses règles par défaut' => ['Password::defaults(static fn (): Password => Password::min(8)->mixedCase());', false],
    'la règle nommée en entier' => ['\\Illuminate\\Validation\\Rules\\Password::min(8);', false],
    'la traduction homonyme' => ['throw ValidationException::withMessages([\'password\' => __(\'auth.password\')]);', false],
    'la confirmation du mot de passe' => ['$request->session()->put(\'auth.password_confirmed_at\', time());', false],
]);

it('lit les méthodes du courtier qu’un code appelle, quel que soit leur receveur', function (string $code, array $usages): void {
    expect(motDePasseOublieUsagesDuCourtier($code))->toBe($usages);
})->with([
    'par la façade' => ['Password::sendResetLink([\'email\' => $adresse]);', ['sendResetLink']],
    'par un courtier injecté' => ['return $this->courtier->createToken($user);', ['createToken']],
    'par un courtier nommé' => ['Password::broker(\'users\')->reset($donnees, $rappel); Password::reset($autres, $rappel);', ['broker', 'reset']],
    'aucune' => ['$this->form->fill(); $jeton?->delete();', []],
]);

/*
 * Trois fichiers seulement atteignent le courtier, chacun pour un seul usage :
 * le job envoie le lien, par un courtier neuf qu'il crée sans plancher
 * (`broker()`), `NewPasswordController` réinitialise sous le plancher des
 * refus et ne rend jamais `passwords.user`, et l'effacement d'un compte
 * retire son jeton. Une autre entrée qui réinitialiserait (API, panneau)
 * rendrait `passwords.user` pour une adresse sans compte, et sans le
 * plancher : elle passe par `NewPasswordController::store()`, ou par une
 * classe extraite de lui qui garde ce plancher et ce message, et qui prend sa
 * place ici.
 */
it('ne laisse atteindre le courtier qu’à trois fichiers, chacun pour son seul usage', function (): void {
    $usages = array_map(
        motDePasseOublieUsagesDuCourtier(...),
        array_filter(motDePasseOublieCodeDeLApplication(), motDePasseOublieAtteintLeCourtier(...)),
    );

    expect($usages)->toBe([
        'app/Http/Controllers/Auth/NewPasswordController.php' => ['reset'],
        'app/Jobs/EnvoyerLeLienDeReinitialisation.php' => ['broker', 'sendResetLink'],
        'app/Models/Traits/EffaceSesTracesPolymorphes.php' => ['deleteToken'],
    ]);
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
 * Le coût bcrypt que `.env.example` donne à la production, et que phpunit.xml
 * baisse à 4.
 */
function motDePasseOublieCoutDeProduction(): int
{
    if (preg_match('/^BCRYPT_ROUNDS=(\d+)$/m', File::get(base_path('.env.example')), $cout) !== 1) {
        throw new RuntimeException('.env.example ne donne plus BCRYPT_ROUNDS.');
    }

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
    $duree = DureeSimulee::demarrer();
    $plancher = $duree->plancher;

    $sansCompte = $duree->mesurer(motDePasseOublieReinitialiser(...));
    $sansCompte['reponse']->assertSessionHasErrors(['email' => 'Ce jeton de réinitialisation du mot de passe n\'est pas valide.']);

    $compte = User::factory()->create(['email' => motDePasseOublieAdresse()]);
    // Le jeton s'enregistre au coût de production, que sa vérification paiera.
    config(['hashing.bcrypt.rounds' => motDePasseOublieCoutDeProduction()]);
    Hash::forgetDrivers();
    Password::createToken($compte);

    $jetonFaux = $duree->mesurer(motDePasseOublieReinitialiser(...));
    $jetonFaux['reponse']->assertSessionHasErrors(['email' => 'Ce jeton de réinitialisation du mot de passe n\'est pas valide.']);

    // Le travail du refus le plus long, hors des planchers (sommeil simulé) : la vérification de l'empreinte.
    $debut = microtime(true);
    expect(Password::reset([
        'token' => 'un-jeton-quelconque',
        'email' => motDePasseOublieAdresse(),
        'password' => 'Un-nouveau-mot-de-passe-2026!',
        'password_confirmation' => 'Un-nouveau-mot-de-passe-2026!',
    ], static function (): void {
    }))->toBe(Password::INVALID_TOKEN);
    $travail = (microtime(true) - $debut) * 1_000_000;

    expect($plancher)->toBe(DureeDesReponsesDAuthentification::PLANCHER_PAR_DEFAUT_MICROSECONDES)
        ->and($travail)->toBeLessThan($plancher)
        ->and($sansCompte['total'])->toBeGreaterThanOrEqual($plancher)
        ->and($jetonFaux['total'])->toBeGreaterThanOrEqual($plancher)
        ->and($sansCompte['dormi'])->toBeGreaterThan(0.0)
        ->and($jetonFaux['dormi'])->toBeGreaterThan(0.0);
});

it('répond à une réinitialisation réussie sans attendre le plancher des refus', function (): void {
    $duree = DureeSimulee::demarrer();
    $compte = User::factory()->create(['email' => motDePasseOublieAdresse()]);
    $jeton = Password::createToken($compte);

    $reussite = $duree->mesurer(static fn (): TestResponse => post(route('password.store'), [
        'token' => $jeton,
        'email' => motDePasseOublieAdresse(),
        'password' => 'Un-nouveau-mot-de-passe-2026!',
        'password_confirmation' => 'Un-nouveau-mot-de-passe-2026!',
    ]));

    $reussite['reponse']->assertSessionHasNoErrors()->assertRedirect(route('login'));

    expect($reussite['dormi'])->toBe(0.0)
        ->and($reussite['total'])->toBeLessThan($duree->plancher);
});
