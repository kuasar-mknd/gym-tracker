<?php

declare(strict_types=1);

use App\Models\ExceptionEnregistree;
use App\Models\User;
use App\Support\Courriels\CourrielleurQuiTaitLesAdresses;
use App\Support\Courriels\GestionnaireDesCourriels;
use App\Support\TexteSansAdresse;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Exceptions\Handler;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Tests\Support\TransportQuiRefuseLeDestinataire;

/*
 * Une erreur d'envoi de courriel ne cite aucune adresse (#PR).
 *
 * Le serveur de courriel nomme souvent le destinataire qu'il refuse, et
 * Symfony recopie sa réponse dans le message de son exception : celui du
 * lien « mot de passe oublié », l'adresse du compte, ou l'adresse tapée à
 * l'inscription, peut-être celle d'un tiers. Symfony Mime cite de même
 * l'adresse qu'il refuse d'écrire. Le job qui envoie est chiffré dans la
 * file, son échec ne l'est pas : le message partait dans le journal, la
 * table des exceptions du panneau, `failed_jobs` et les jobs échoués de
 * Horizon. `CourrielleurQuiTaitLesAdresses` relève l'erreur sans adresse.
 *
 * Chaque cas cherche la partie locale de l'adresse, quelle que soit sa
 * casse, dans le journal, ce que le gestionnaire d'exceptions rapporte,
 * chaque colonne de l'exception enregistrée et, par un worker, chaque
 * colonne de `failed_jobs`. Le journal de ces tests est un canal monté ici,
 * sans le masque que `config/logging.php` pose sur les canaux de
 * l'application (`LeJournalNeGardeAucuneAdresseTest`) : seule l'erreur elle-
 * même peut l'en tenir à l'écart. Le serveur cite l'adresse entière, ou sa
 * seule partie locale (`TransportQuiRefuseLeDestinataire`). La pile est
 * réglée comme dans l'image, d'après `docker/php-prod.ini`
 * (`LesErreursSqlNeRecopientPasLesValeursTest`).
 */

beforeEach(function (): void {
    $production = parse_ini_file(base_path('docker/php-prod.ini'));
    $ignorerLesArguments = is_array($production) ? ($production['zend.exception_ignore_args'] ?? null) : null;

    expect($ignorerLesArguments)->toBeString('docker/php-prod.ini ne règle pas zend.exception_ignore_args.');

    ini_set('zend.exception_ignore_args', is_string($ignorerLesArguments) ? $ignorerLesArguments : '0');
    Config::set('app.debug', false);
});

afterEach(function (): void {
    ini_restore('zend.exception_ignore_args');
});

dataset('formes de la réponse du serveur', [
    'le serveur cite l’adresse entière' => ['adresse'],
    'le serveur ne cite que sa partie locale' => ['partie-locale'],
]);

/**
 * Le courrielleur par défaut passe par un serveur qui refuse le
 * destinataire, et le cite sous la forme donnée.
 */
function courrielsSansAdresseRefuserLeDestinataire(string $forme): void
{
    Mail::extend('refus', static fn (array $configuration): TransportQuiRefuseLeDestinataire => new TransportQuiRefuseLeDestinataire(
        is_string($configuration['forme'] ?? null) ? $configuration['forme'] : 'adresse',
    ));
    Config::set('mail.mailers.refus', ['transport' => 'refus', 'forme' => $forme]);
    Config::set('mail.default', 'refus');
}

/**
 * Le journal écrit dans un fichier à part, effacé ensuite, et ce que le
 * gestionnaire d'exceptions rapporte pendant `$test`, message et pile de
 * chaque exception, en texte.
 *
 * @param  Closure(): mixed  $test
 * @return array{journal: string, rapport: string}
 */
function courrielsSansAdresseTracesDe(Closure $test): array
{
    $fichier = sys_get_temp_dir().'/courriels-sans-adresse-'.bin2hex(random_bytes(6)).'.log';
    Config::set('logging.channels.courriels_sans_adresse', ['driver' => 'single', 'path' => $fichier, 'level' => 'debug']);
    Config::set('logging.default', 'courriels_sans_adresse');

    $rapport = [];
    $gestionnaire = app(ExceptionHandler::class);
    assert($gestionnaire instanceof Handler);
    $gestionnaire->reportable(static function (Throwable $exception) use (&$rapport): void {
        $rapport[] = $exception."\n".json_encode($exception->getTrace(), JSON_PARTIAL_OUTPUT_ON_ERROR);
    });

    try {
        $test();
        $journal = is_file($fichier) ? (string) file_get_contents($fichier) : '';
    } finally {
        if (is_file($fichier)) {
            unlink($fichier);
        }
    }

    return ['journal' => $journal, 'rapport' => implode("\n", $rapport)];
}

/**
 * Chaque ligne d'une table, toutes colonnes, en un seul texte ; `query`
 * mise à part. Laravel ne la remplit qu'en mode debug, celui de la CI : elle
 * garde alors chaque requête SQL réussie et ses valeurs, dont l'adresse du
 * compte que le test crée puis que le courtier cherche, à dessein
 * (`.ai/rules/tests.md`). Le Markdown, qui les recopie, est lu.
 */
function courrielsSansAdresseLignesDe(string $table): string
{
    return (string) json_encode(
        DB::table($table)->get()->map(static fn (object $ligne): array => Arr::except((array) $ligne, ['query'])),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
    );
}

/**
 * Les traces, parmi celles données, qui laissent lire la partie locale de
 * l'adresse, quelle que soit sa casse.
 *
 * @param  array<string, string>  $traces
 * @return list<string>
 */
function courrielsSansAdresseTracesQuiCitent(string $partieLocale, array $traces): array
{
    return array_keys(array_filter(
        $traces,
        static fn (string $texte): bool => str_contains(strtolower($texte), strtolower($partieLocale)),
    ));
}

/**
 * S'inscrit avec l'adresse donnée ; l'envoi du lien qui ouvre le compte
 * échoue pendant la requête, la file des tests étant synchrone.
 */
function courrielsSansAdresseSInscrire(string $adresse): void
{
    test()->from(route('register'))->post(route('register'), [
        'name' => 'Saisie',
        'email' => $adresse,
        'password' => 'Un-Mot-De-Passe-Solide-2026!',
        'password_confirmation' => 'Un-Mot-De-Passe-Solide-2026!',
    ])->assertInternalServerError();
}

it('crée chaque courrielleur sans adresse dans ses erreurs, comme Laravel le créerait', function (string $nom): void {
    $notre = app('mail.manager')->mailer($nom);
    $celuiDeLaravel = new MailManager(app())->mailer($nom);
    $proprietes = [];

    foreach (new ReflectionClass(Mailer::class)->getProperties() as $propriete) {
        $lire = static fn (object $courrielleur): mixed => is_object($valeur = $propriete->getValue($courrielleur)) ? $valeur::class : $valeur;
        $proprietes[$propriete->getName()] = [$lire($notre), $lire($celuiDeLaravel)];
    }

    expect(app('mail.manager'))->toBeInstanceOf(GestionnaireDesCourriels::class)
        ->and($notre)->toBeInstanceOf(CourrielleurQuiTaitLesAdresses::class)
        ->and(Mail::mailer())->toBeInstanceOf(CourrielleurQuiTaitLesAdresses::class)
        ->and(array_filter($proprietes, static fn (array $paire): bool => $paire[0] !== $paire[1]))->toBe([])
        ->and($proprietes)->toHaveKeys(['name', 'views', 'transport', 'events', 'queue']);
})->with(['array', 'smtp', 'log']);

it('relève l’erreur du transport sans adresse, avec sa classe, son code et la réponse du serveur', function (string $forme): void {
    courrielsSansAdresseRefuserLeDestinataire($forme);

    try {
        Mail::raw('Bonjour', static function (Illuminate\Mail\Message $message): void {
            $message->to('Titulaire.Direct@Example.org')->cc('copie.directe@example.org')->subject('Essai');
        });
        $erreur = null;
    } catch (UnexpectedResponseException $exception) {
        $erreur = $exception;
    }

    expect($erreur)->toBeInstanceOf(UnexpectedResponseException::class)
        ->and($erreur?->getCode())->toBe(550)
        ->and($erreur?->getMessage())->toStartWith('Expected response code "250/251/252" but got code "550", with message "550 5.1.1 '.($forme === 'adresse' ? '<'.TexteSansAdresse::MASQUE.'>: Recipient address rejected' : TexteSansAdresse::MASQUE.'... User unknown'))
        ->and($erreur?->getPrevious())->toBeNull()
        ->and($erreur?->getDebug())->toBe('')
        ->and(courrielsSansAdresseTracesQuiCitent('titulaire.direct', ['erreur' => (string) $erreur]))->toBe([]);
})->with('formes de la réponse du serveur');

it('ne cite pas l’adresse du compte quand le serveur refuse le lien « mot de passe oublié »', function (string $forme): void {
    courrielsSansAdresseRefuserLeDestinataire($forme);
    User::factory()->create(['email' => 'titulaire.smtp@example.org']);

    $traces = courrielsSansAdresseTracesDe(static function (): void {
        test()->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'Titulaire.SMTP@example.org'])
            ->assertInternalServerError();
    });

    $exception = ExceptionEnregistree::query()->sole();

    expect($exception->type)->toBe(UnexpectedResponseException::class)
        ->and($exception->message)->toContain('but got code "550", with message "550 5.1.1 ')
        ->and($traces['journal'])->toContain('but got code "550"')
        ->and(courrielsSansAdresseTracesQuiCitent('titulaire.smtp', [
            'le journal' => $traces['journal'],
            'le rapport' => $traces['rapport'],
            'la table des exceptions' => courrielsSansAdresseLignesDe('filament_exceptions_table'),
        ]))->toBe([]);
})->with('formes de la réponse du serveur');

it('ne cite pas l’adresse du compte quand le lien « mot de passe oublié » échoue dans un worker', function (string $forme): void {
    courrielsSansAdresseRefuserLeDestinataire($forme);
    Config::set('queue.default', 'database');
    User::factory()->create(['email' => 'titulaire.file@example.org']);

    $traces = courrielsSansAdresseTracesDe(static function (): void {
        test()->from(route('password.request'))->post(route('password.email'), ['email' => 'titulaire.file@example.org'])->assertRedirect();

        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);
    });

    expect(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->value('exception'))->toBeString()->toStartWith(UnexpectedResponseException::class.': Expected response code')
        ->and(ExceptionEnregistree::query()->where('type', UnexpectedResponseException::class)->count())->toBe(1)
        ->and(courrielsSansAdresseTracesQuiCitent('titulaire.file', [
            'failed_jobs' => courrielsSansAdresseLignesDe('failed_jobs'),
            'le journal' => $traces['journal'],
            'le rapport' => $traces['rapport'],
            'la table des exceptions' => courrielsSansAdresseLignesDe('filament_exceptions_table'),
        ]))->toBe([]);
})->with('formes de la réponse du serveur');

it('ne cite pas l’adresse tapée à l’inscription quand le serveur la refuse', function (string $forme): void {
    courrielsSansAdresseRefuserLeDestinataire($forme);

    $traces = courrielsSansAdresseTracesDe(static function (): void {
        courrielsSansAdresseSInscrire('saisie.inscription@example.org');
    });

    expect(ExceptionEnregistree::query()->sole()->type)->toBe(UnexpectedResponseException::class)
        ->and(courrielsSansAdresseTracesQuiCitent('saisie.inscription', [
            'le journal' => $traces['journal'],
            'le rapport' => $traces['rapport'],
            'la table des exceptions' => courrielsSansAdresseLignesDe('filament_exceptions_table'),
        ]))->toBe([]);
})->with('formes de la réponse du serveur');

/*
 * La validation de l'inscription admet un commentaire dans l'adresse
 * (« ada(x)@… », conforme à la RFC 5322), que Symfony Mime refuse d'écrire :
 * son erreur citait l'adresse tapée.
 */
it('ne cite pas l’adresse tapée à l’inscription que Symfony Mime refuse d’écrire', function (): void {
    $traces = courrielsSansAdresseTracesDe(static function (): void {
        courrielsSansAdresseSInscrire('saisie.commentee(x)@example.org');
    });

    $exception = ExceptionEnregistree::query()->sole();

    expect($exception->type)->toBe(RfcComplianceException::class)
        ->and($exception->message)->toBe('Email "'.TexteSansAdresse::MASQUE.'" does not comply with addr-spec of RFC 2822.')
        ->and(courrielsSansAdresseTracesQuiCitent('saisie.commentee', [
            'le journal' => $traces['journal'],
            'le rapport' => $traces['rapport'],
            'la table des exceptions' => courrielsSansAdresseLignesDe('filament_exceptions_table'),
        ]))->toBe([]);
});
