<?php

declare(strict_types=1);

use App\Models\ExceptionEnregistree;
use App\Models\User;
use App\Support\LiensDesCourriels;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Encryption\Encrypter;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\Support\CeQueLeClientLit;
use Tests\Support\FormulaireDeReinitialisation;

use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
 * Le lien du courriel « mot de passe oublié » portait l'adresse du compte en
 * clair (`/reset-password/{jeton}?email=…`), et la page la montrait dans un
 * champ modifiable. Un lien suivi en GET finit dans les journaux d'accès,
 * l'historique du navigateur et la trace d'une exception. Il ne la porte
 * plus que chiffrée (`adresse`, `Crypt::encryptString()`), comme le lien de
 * confirmation d'une nouvelle adresse ; la page la renvoie telle quelle sans
 * l'afficher, et seule la réinitialisation la déchiffre.
 *
 * Une valeur qui ne se déchiffre pas, comme un lien émis avant ce changement,
 * est refusée comme un jeton faux : même réponse, entière, et même plancher
 * de durée (`DureeDesReponsesDAuthentificationTest` mesure ces cas).
 */

/**
 * L'adresse du compte de chaque cas. Sa partie locale ne se trouve nulle part
 * ailleurs : la lire dans un texte, c'est y lire l'adresse, en clair ou
 * encodée dans une URL.
 */
function lienChiffreAdresse(): string
{
    return 'titulaire.du-lien@example.org';
}

/**
 * Ce texte laisse-t-il lire l'adresse du compte ?
 */
function lienChiffreLaisseLireLAdresse(string $texte): bool
{
    return str_contains($texte, 'titulaire.du-lien');
}

function lienChiffreMotDePasse(): string
{
    return 'Un-nouveau-mot-de-passe-2026!';
}

/**
 * Le dernier courriel envoyé, tel que l'utilisateur le reçoit.
 */
function lienChiffreDernierCourriel(): Email
{
    $transport = Mail::mailer()->getSymfonyTransport();
    assert($transport instanceof ArrayTransport);
    $envoi = $transport->messages()->last();
    assert($envoi instanceof SentMessage);
    $message = $envoi->getOriginalMessage();
    assert($message instanceof Email);

    return $message;
}

/**
 * Le lien de réinitialisation du dernier courriel envoyé.
 */
function lienChiffreLienDuCourriel(): string
{
    $corps = html_entity_decode((string) lienChiffreDernierCourriel()->getHtmlBody(), ENT_QUOTES | ENT_HTML5);

    expect(preg_match('#https?://[^\s"<>]+/reset-password/[^\s"<>]+#', $corps, $lien))->toBe(1);

    return $lien[0] ?? '';
}

/**
 * Un envoi du formulaire comme la page le fait, depuis `$page`, comme s'il
 * était le premier de cet appareil, puis tout ce que le client en lit
 * (`CeQueLeClientLit`).
 *
 * La saisie que la session garde pour la page suivante (`_old_input`) en est
 * ôtée : elle recopie ce que le client vient d'envoyer, qui diffère d'un cas
 * à l'autre par construction, et rien d'autre.
 *
 * @param  array<string, mixed>  $donnees
 * @return array{statut: int, entetes: array<mixed>, cookies: list<array<string, mixed>>, corps: string|false, session: array<mixed>, page: string}
 */
function lienChiffreCeQueLeClientLit(string $page, array $donnees): array
{
    test()->flushSession();
    Cache::flush();

    /** @var TestResponse<Response> $reponse */
    $reponse = test()->from($page)
        ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->post(route('password.store'), $donnees);

    $lu = CeQueLeClientLit::de($reponse, $page);
    unset($lu['session']['_old_input']);

    return $lu;
}

/**
 * La valeur chiffrée, un caractère changé en son milieu.
 */
function lienChiffreAlterer(string $valeur): string
{
    $milieu = intdiv(strlen($valeur), 2);

    return substr_replace($valeur, $valeur[$milieu] === 'A' ? 'B' : 'A', $milieu, 1);
}

it('n’écrit l’adresse du compte que chiffrée dans le lien du courriel', function (): void {
    config(['app.url' => 'https://gym.example.org']);
    User::factory()->create(['email' => lienChiffreAdresse()]);

    post(route('password.email'), ['email' => lienChiffreAdresse()])->assertSessionHasNoErrors();

    $message = lienChiffreDernierCourriel();
    $lien = lienChiffreLienDuCourriel();
    $formulaire = FormulaireDeReinitialisation::depuisLeLien($lien, lienChiffreMotDePasse());

    expect($message->getTo()[0]->getAddress())->toBe(lienChiffreAdresse())
        ->and($lien)->toStartWith('https://gym.example.org/reset-password/')
        ->and($lien)->not->toContain('email=')
        ->and(lienChiffreLaisseLireLAdresse(html_entity_decode((string) $message->getHtmlBody(), ENT_QUOTES | ENT_HTML5)))->toBeFalse()
        ->and(lienChiffreLaisseLireLAdresse((string) $message->getTextBody()))->toBeFalse()
        ->and(Crypt::decryptString($formulaire['adresse']))->toBe(lienChiffreAdresse());
});

it('ouvre une page qui ne montre l’adresse nulle part et lui rend la valeur chiffrée telle que le lien la porte', function (): void {
    $compte = User::factory()->create(['email' => lienChiffreAdresse()]);
    $lien = LiensDesCourriels::lienDeReinitialisation($compte, Password::createToken($compte));
    $formulaire = FormulaireDeReinitialisation::depuisLeLien($lien, lienChiffreMotDePasse());

    $page = get($lien)->assertOk();
    $proprietes = $page->inertiaProps();

    expect($page->inertiaPage()['component'] ?? null)->toBe('Auth/ResetPassword')
        ->and(lienChiffreLaisseLireLAdresse((string) $page->getContent()))->toBeFalse()
        ->and($proprietes['token'] ?? null)->toBe($formulaire['token'])
        ->and($proprietes['adresse'] ?? null)->toBe($formulaire['adresse'])
        ->and($proprietes)->not->toHaveKey('email');
});

it('réinitialise le mot de passe avec ce que la page renvoie, puis refuse le même lien comme un jeton faux', function (): void {
    $compte = User::factory()->create(['email' => lienChiffreAdresse()]);
    post(route('password.email'), ['email' => lienChiffreAdresse()])->assertSessionHasNoErrors();
    $lien = lienChiffreLienDuCourriel();

    $proprietes = get($lien)->assertOk()->inertiaProps();
    $envoi = [
        'token' => $proprietes['token'] ?? null,
        'adresse' => $proprietes['adresse'] ?? null,
        'password' => lienChiffreMotDePasse(),
        'password_confirmation' => lienChiffreMotDePasse(),
    ];

    test()->from($lien)->post(route('password.store'), $envoi)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', __('passwords.reset'));

    expect(Hash::check(lienChiffreMotDePasse(), (string) $compte->fresh()?->getAuthPassword()))->toBeTrue();

    test()->from($lien)->post(route('password.store'), [...$envoi, 'password' => 'Encore-un-autre-2026!', 'password_confirmation' => 'Encore-un-autre-2026!'])
        ->assertRedirect($lien)
        ->assertSessionHasErrors(['token' => __('passwords.token')]);

    expect(Hash::check(lienChiffreMotDePasse(), (string) $compte->fresh()?->getAuthPassword()))->toBeTrue();
});

/*
 * Chaque cas part d'un vrai lien, au jeton valide : seule l'adresse chiffrée
 * change. La référence est le jeton faux du même compte.
 */
dataset('liens dont l’adresse ne se déchiffre pas', [
    'une valeur altérée' => [static fn (array $formulaire): array => [...$formulaire, 'adresse' => lienChiffreAlterer($formulaire['adresse'])]],
    'une valeur chiffrée sous une autre clef' => [static fn (array $formulaire): array => [
        ...$formulaire,
        'adresse' => new Encrypter(Encrypter::generateKey('aes-256-cbc'), 'aes-256-cbc')->encryptString(lienChiffreAdresse()),
    ]],
    'l’adresse en clair à la place de la valeur chiffrée' => [static fn (array $formulaire): array => [...$formulaire, 'adresse' => lienChiffreAdresse()]],
    'un lien d’avant le chiffrement : l’adresse en clair, sans valeur chiffrée' => [static fn (array $formulaire): array => [
        ...Arr::except($formulaire, 'adresse'),
        'email' => lienChiffreAdresse(),
    ]],
    'aucune valeur' => [static fn (array $formulaire): array => Arr::except($formulaire, 'adresse')],
    'une valeur vide' => [static fn (array $formulaire): array => [...$formulaire, 'adresse' => '']],
    'une valeur qui n’est pas une chaîne' => [static fn (array $formulaire): array => [...$formulaire, 'adresse' => [$formulaire['adresse']]]],
]);

it('refuse comme un jeton faux, réponse entière comprise, un lien dont l’adresse ne se déchiffre pas', function (Closure $alterer): void {
    $this->freezeSecond();
    $compte = User::factory()->create(['email' => lienChiffreAdresse()]);
    $jeton = Password::createToken($compte);
    $lien = LiensDesCourriels::lienDeReinitialisation($compte, $jeton);
    $formulaire = FormulaireDeReinitialisation::depuisLeLien($lien, lienChiffreMotDePasse());

    $jetonFaux = lienChiffreCeQueLeClientLit($lien, [...$formulaire, 'token' => 'un-jeton-quelconque']);
    /** @var array<string, mixed> $donnees */
    $donnees = $alterer($formulaire);
    $refus = lienChiffreCeQueLeClientLit($lien, $donnees);

    expect($jetonFaux['statut'])->toBe(302)
        ->and($jetonFaux['session']['errors'] ?? null)->toBe(['default' => ['token' => ['Ce jeton de réinitialisation du mot de passe n\'est pas valide.']]])
        ->and($refus)->toBe($jetonFaux)
        ->and(Hash::check('password', (string) $compte->fresh()?->getAuthPassword()))->toBeTrue()
        ->and(Password::tokenExists($compte, $jeton))->toBeTrue();
})->with('liens dont l’adresse ne se déchiffre pas');

/*
 * Un jeton en tableau faisait lever la vérification de son empreinte : une
 * erreur 500 pour un compte qui venait de demander un lien, une redirection
 * pour toute autre adresse.
 */
it('refuse de la même façon un jeton qui n’est pas une chaîne, que l’adresse ait une demande en cours ou non', function (): void {
    $this->freezeSecond();
    $adresse = Crypt::encryptString(lienChiffreAdresse());
    $page = route('password.reset', ['token' => 'un-jeton-quelconque', 'adresse' => $adresse]);
    $donnees = [
        'token' => ['un-jeton-quelconque'],
        'adresse' => $adresse,
        'password' => lienChiffreMotDePasse(),
        'password_confirmation' => lienChiffreMotDePasse(),
    ];

    $sansCompte = lienChiffreCeQueLeClientLit($page, $donnees);
    Password::createToken(User::factory()->create(['email' => lienChiffreAdresse()]));
    $demandeEnCours = lienChiffreCeQueLeClientLit($page, $donnees);

    expect($sansCompte['statut'])->toBe(302)
        ->and($sansCompte['session']['errors'] ?? null)->toBe(['default' => ['token' => ['Le champ jeton doit être une chaîne de caractères.']]])
        ->and($demandeEnCours)->toBe($sansCompte);
});

it('ne recopie l’adresse déchiffrée ni dans la session, ni dans une réponse, ni dans le journal', function (): void {
    $fichier = sys_get_temp_dir().'/lien-chiffre-'.bin2hex(random_bytes(6)).'.log';
    Config::set('logging.channels.lien_chiffre', ['driver' => 'single', 'path' => $fichier, 'level' => 'debug']);
    Config::set('logging.default', 'lien_chiffre');

    try {
        $compte = User::factory()->create(['email' => lienChiffreAdresse()]);
        $lien = LiensDesCourriels::lienDeReinitialisation($compte, Password::createToken($compte));
        $formulaire = FormulaireDeReinitialisation::depuisLeLien($lien, lienChiffreMotDePasse());

        $traces = ['page du lien' => (string) get($lien)->assertOk()->getContent()];

        $refus = test()->from($lien)->post(route('password.store'), [...$formulaire, 'token' => 'un-jeton-quelconque'])
            ->assertSessionHasErrors('token');
        $traces['redirection du refus'] = (string) $refus->headers->get('Location');
        $traces['session après le refus'] = serialize(session()->all());
        $traces['page après le refus'] = (string) get($traces['redirection du refus'])->getContent();

        $reussite = test()->from($lien)->post(route('password.store'), $formulaire)->assertRedirect(route('login'));
        $traces['session après la réussite'] = serialize(session()->all());
        $traces['page de connexion'] = (string) get((string) $reussite->headers->get('Location'))->getContent();
        $traces['journal'] = is_file($fichier) ? (string) file_get_contents($fichier) : '';

        expect(array_keys(array_filter($traces, lienChiffreLaisseLireLAdresse(...))))->toBe([])
            ->and(session('url.intended'))->toBeNull()
            ->and(Hash::check(lienChiffreMotDePasse(), (string) $compte->fresh()?->getAuthPassword()))->toBeTrue();
    } finally {
        if (is_file($fichier)) {
            unlink($fichier);
        }
    }
});

/*
 * Une panne pendant la réinitialisation, l'adresse déjà déchiffrée : ni le
 * journal, ni l'exception que le panneau garde ne la reçoivent. La pile est
 * réglée comme dans l'image, d'après `docker/php-prod.ini`
 * (`LesErreursSqlNeRecopientPasLesValeursTest`). Les colonnes `query` et
 * `markdown` de l'exception n'en sont pas : avec `APP_DEBUG`, celui de la CI,
 * elles gardent les requêtes réussies, valeurs comprises, dont la recherche
 * du compte par son adresse (`.ai/rules/tests.md`) ; la production ne les
 * remplit pas.
 */
it('ne recopie l’adresse déchiffrée ni dans le journal ni dans l’exception d’une réinitialisation qui échoue', function (): void {
    $production = parse_ini_file(base_path('docker/php-prod.ini'));
    $ignorerLesArguments = is_array($production) ? ($production['zend.exception_ignore_args'] ?? null) : null;
    expect($ignorerLesArguments)->toBeString('docker/php-prod.ini ne règle pas zend.exception_ignore_args.');
    ini_set('zend.exception_ignore_args', is_string($ignorerLesArguments) ? $ignorerLesArguments : '0');

    $fichier = sys_get_temp_dir().'/lien-chiffre-'.bin2hex(random_bytes(6)).'.log';
    Config::set('logging.channels.lien_chiffre', ['driver' => 'single', 'path' => $fichier, 'level' => 'debug']);
    Config::set('logging.default', 'lien_chiffre');

    try {
        $compte = User::factory()->create(['email' => lienChiffreAdresse()]);
        $formulaire = FormulaireDeReinitialisation::pour($compte, lienChiffreMotDePasse());
        Event::listen(PasswordReset::class, static function (): never {
            throw new RuntimeException('Panne simulée pendant la réinitialisation.');
        });

        $reponse = post(route('password.store'), $formulaire)->assertInternalServerError();

        $exception = ExceptionEnregistree::query()->sole();
        $journal = is_file($fichier) ? (string) file_get_contents($fichier) : '';
        $traces = [
            'réponse' => (string) $reponse->getContent(),
            'journal' => $journal,
            ...Arr::map(
                Arr::except($exception->getAttributes(), ['query', 'markdown']),
                static fn (mixed $valeur): string => is_scalar($valeur) ? (string) $valeur : (string) json_encode($valeur),
            ),
        ];

        expect($exception->message)->toBe('Panne simulée pendant la réinitialisation.')
            ->and($journal)->toContain('Panne simulée pendant la réinitialisation.')
            ->and(array_keys(array_filter($traces, lienChiffreLaisseLireLAdresse(...))))->toBe([]);
    } finally {
        ini_restore('zend.exception_ignore_args');

        if (is_file($fichier)) {
            unlink($fichier);
        }
    }
});

/*
 * La page du lien porte le jeton dans son chemin et l'adresse chiffrée dans
 * sa requête. `SecurityHeaders` pose sur toute réponse
 * `strict-origin-when-cross-origin` : une ressource d'une autre origine ne
 * reçoit que l'origine du site, jamais ce chemin ni cette requête, et la page
 * n'en charge d'ailleurs aucune (CSP). `no-referrer` n'ôterait que l'en-tête
 * des requêtes vers le site lui-même, qui a déjà vu cette adresse en servant
 * la page, et casserait le retour après un refus : Inertia envoie
 * `X-Requested-With`, ses visites ne mettent donc pas à jour l'adresse que la
 * session garde pour revenir (`_previous.url`), et sans `Referer`, un
 * formulaire refusé plus tard dans le même onglet, celui de la connexion qui
 * suit la réinitialisation, ramènerait à cette page.
 */
it('sert la page du lien sous une politique de référent qui ne donne ni son chemin ni sa requête à une autre origine', function (): void {
    $compte = User::factory()->create(['email' => lienChiffreAdresse()]);

    get(LiensDesCourriels::lienDeReinitialisation($compte, Password::createToken($compte)))
        ->assertOk()
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
});
