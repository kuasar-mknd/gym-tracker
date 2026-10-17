<?php

declare(strict_types=1);

use App\Enums\MotifDeLaTentative;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Jobs\EnvoyerLeLienDeReinitialisation;
use App\Models\ExceptionEnregistree;
use App\Models\User;
use App\Notifications\AdresseDuCompteChangee;
use App\Notifications\LienDeChangementDAdresse;
use App\Notifications\TentativeSurTonAdresse;
use App\Rules\AdresseQueLaMessagerieSaitEcrire;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\TextInput;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Tests\Support\CeQueLeClientLit;
use Tests\Support\FilamentAdminPanel;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertAuthenticatedAs;
use function Pest\Laravel\assertGuest;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

/*
 * Une adresse de compte reçoit des courriels, que Symfony Mime écrit (#2075).
 *
 * La règle `email` de Laravel juge l'adresse selon la RFC 5322
 * (`RFCValidation` d'Egulias), qui admet un commentaire dans la partie locale
 * (« camille(salle)@example.org ») ; Symfony Mime la refuse comme
 * destinataire (`Address`, `RfcComplianceException`). Le compte était créé,
 * son lien d'ouverture ne partait jamais, et il restait en attente jusqu'à la
 * purge ; au profil, le lien de changement ne partait pas davantage. La
 * mesure ci-dessous dit quelles formes la règle `email` admet et que la
 * messagerie n'écrit pas telle quelle : le commentaire dans la partie locale,
 * et le chevron (`<`) dans une partie locale entre guillemets, que Mime lit,
 * dans une notification, comme l'ouverture d'une forme « Nom <adresse> ». La
 * partie locale entre guillemets sans chevron, le domaine littéral, le domaine
 * sans point, la partie locale de plus de 64 caractères et le commentaire dans
 * le domaine passent l'une et l'autre ; les points consécutifs, aucune.
 *
 * `AdresseQueLaMessagerieSaitEcrire` n'admet que ce que la messagerie écrit
 * tel quel, aux quatre entrées d'une adresse qui recevra un courriel :
 * l'inscription, le profil, le panneau, et l'adresse que rend un fournisseur
 * de connexion, qui ne passait par aucune règle `email`, et qui refuse
 * désormais aussi une adresse dont l'inscription refuserait la forme ou la
 * longueur. Le refus est le message de toute adresse mal formée, et ne dit
 * rien d'un compte. Le profil et le panneau ne jugent la forme que d'une
 * adresse qui change : un compte dont l'adresse est antérieure à la règle,
 * quelle qu'elle soit, enregistre son nom et change d'adresse comme avant ; il
 * ne reçoit pas de lien de vérification que la messagerie n'écrirait pas
 * telle quelle. La connexion et « mot de passe oublié » ne jugent pas
 * l'adresse davantage.
 *
 * Les courriels partent pour de bon, par le transport `array` des tests, à
 * l'inscription, au profil et au renvoi du lien de vérification : une adresse
 * admise reçoit son lien.
 */

$admisesParLaRegleEmailRefuseesParMime = [
    'un commentaire après la partie locale' => ['camille(salle)@example.org'],
    'un commentaire avant la partie locale' => ['(salle)camille@example.org'],
    'un commentaire imbriqué' => ['camille((salle))@example.org'],
    'un commentaire dans la partie locale et dans le domaine' => ['camille(salle)@example(sud).org'],
    'un chevron ouvrant entre guillemets' => ['"camille<"@example.org'],
    'des chevrons entre guillemets' => ['"camille<salle>"@example.org'],
    'une adresse entre chevrons, entre guillemets' => ['"camille<autre@example.net>"@example.org'],
];

$refuseesParLaRegleEmailCommeParMime = [
    'des points consécutifs' => ['camille..martin@example.org'],
    'un point en tête de la partie locale' => ['.camille@example.org'],
    'un point au bout du domaine' => ['camille@example.org.'],
    'un commentaire après le domaine' => ['camille@example.org(salle)'],
    'deux @ hors des guillemets' => ['camille@salle@example.org'],
];

$ecritesParMime = [
    'une adresse ordinaire' => ['camille.martin+salle@example.org'],
    'une partie locale entre guillemets' => ['"camille.martin"@example.org'],
    'un @ entre guillemets' => ['"camille@salle"@example.org'],
    'une parenthèse entre guillemets' => ['"camille(salle)"@example.org'],
    'un domaine littéral IPv4' => ['camille@[192.0.2.1]'],
    'un domaine littéral IPv6' => ['camille@[ipv6:2001:db8::1]'],
    'un domaine sans point' => ['camille@localhost'],
    'une partie locale de 65 caractères' => [str_repeat('c', 65).'@example.org'],
    'un commentaire dans le domaine' => ['camille@example(sud).org'],
];

$refuseesParLaRegleEmailEcritesParMime = [
    'un tiret bas dans le domaine' => ['camille@exa_mple.org'],
];

/*
 * Ce que la messagerie coupe avant de l'écrire, ou refuse : Mime ôte les
 * blancs autour de l'adresse (`trim()`), et Laravel refuse un saut de ligne
 * (`ensureAddressIsSafe()`). Aucune entrée ne les admet (`TrimStrings`, la
 * règle `email`, le motif ASCII) ; la règle, qui juge aussi l'adresse d'un
 * compte existant, ne les admet pas davantage.
 */
$coupeesParLaMessagerie = [
    'un saut de ligne au bout' => ["camille@example.org\n"],
    'un saut de ligne en tête' => ["\ncamille@example.org"],
    'un retour chariot au bout' => ["camille@example.org\r"],
    'une tabulation au bout' => ["camille@example.org\t"],
    'une espace en tête' => [' camille@example.org'],
];

dataset('adresses que la règle email admet et que Mime refuse', $admisesParLaRegleEmailRefuseesParMime);
dataset('adresses que Mime refuse d’écrire', [...$admisesParLaRegleEmailRefuseesParMime, ...$refuseesParLaRegleEmailCommeParMime]);
dataset('adresses que Mime écrit', $ecritesParMime);

/*
 * Ce que l'inscription refuse et que Mime écrirait : la connexion sociale ne
 * passait par aucune de ces règles, et créait le compte. Une adresse de plus
 * de 255 caractères ne tient pas dans sa colonne.
 */
dataset('adresses que l’inscription refuse et que Mime écrit', [
    ...$refuseesParLaRegleEmailEcritesParMime,
    'plus de 255 caractères' => [str_repeat('c', 244).'@example.org'],
]);

/*
 * Ce qu'un compte peut porter d'avant la règle : la connexion sociale créait
 * le compte de toute adresse en ASCII imprimable, et le panneau comme
 * l'inscription admettaient un commentaire dans la partie locale.
 */
dataset('adresses d’un compte antérieur à la règle', [
    ...$admisesParLaRegleEmailRefuseesParMime,
    ...$refuseesParLaRegleEmailCommeParMime,
    ...$refuseesParLaRegleEmailEcritesParMime,
]);

/*
 * Deux adresses de compte d'avant la règle que la connexion admet : l'une que
 * Mime refuse d'écrire, l'autre qu'une notification écrirait à une autre
 * adresse.
 */
dataset('adresses d’un compte que la messagerie n’écrit pas telle quelle', [
    'un commentaire' => ['camille(salle)@example.org'],
    'une adresse entre chevrons, entre guillemets' => ['"camille<autre@example.net>"@example.org'],
]);

/**
 * Le message de la règle `email`, celui de toute adresse mal formée.
 */
function adresseMessagerieMessageDuFormat(): string
{
    return 'Le champ adresse e-mail doit être une adresse e-mail valide.';
}

/**
 * Un courriel part-il à cette adresse, telle quelle ? Il part comme chaque
 * courriel de l'application, par une notification : celle qui prévient une
 * ancienne adresse, par sa route à la demande, sans attendre la file. Laravel
 * refuse un saut de ligne, Symfony Mime refuse ce qu'il ne sait pas écrire,
 * coupe les blancs autour, et lit un chevron comme l'ouverture d'une forme
 * « Nom <adresse> » : le courriel part alors à une autre adresse, ou ne part
 * pas.
 */
function adresseMessagerieUnCourrielPart(string $adresse): bool
{
    try {
        Notification::route('mail', $adresse)->notifyNow(new AdresseDuCompteChangee('camille.martin@example.org'));
    } catch (InvalidArgumentException) {
        return false;
    }

    return adresseMessagerieDestinataires() === [$adresse];
}

/**
 * Les destinataires de chaque courriel parti jusqu'ici, par le transport
 * `array` des tests.
 *
 * @return list<string>
 */
function adresseMessagerieDestinataires(): array
{
    $transport = Mail::mailer()->getSymfonyTransport();
    assert($transport instanceof ArrayTransport);

    $destinataires = [];

    foreach ($transport->messages() as $envoi) {
        assert($envoi instanceof SentMessage);

        foreach ($envoi->getEnvelope()->getRecipients() as $destinataire) {
            $destinataires[] = $destinataire->getAddress();
        }
    }

    return $destinataires;
}

/**
 * Le destinataire de chaque courriel parti jusqu'ici, tel que l'écrit son
 * en-tête `To` : l'adresse et le nom qui l'accompagne.
 *
 * @return list<list<array{adresse: string, nom: string}>>
 */
function adresseMessagerieEnTetesDesDestinataires(): array
{
    $transport = Mail::mailer()->getSymfonyTransport();
    assert($transport instanceof ArrayTransport);

    $enTetes = [];

    foreach ($transport->messages() as $envoi) {
        assert($envoi instanceof SentMessage);
        $message = $envoi->getOriginalMessage();
        assert($message instanceof Email);

        $enTetes[] = array_values(array_map(
            static fn (Address $destinataire): array => ['adresse' => $destinataire->getAddress(), 'nom' => $destinataire->getName()],
            $message->getTo(),
        ));
    }

    return $enTetes;
}

/**
 * Vide la file `database` par un worker, un job à la fois (`--once`), un essai
 * par job. Un worker continu (`--stop-when-empty`) s'arrêterait après le
 * premier job dès que la mémoire du processus de test dépasse sa borne
 * (`--memory`, 128 Mo), ce qu'elle fait au fil d'une suite (`.ai/rules/tests.md`).
 */
function adresseMessagerieViderLaFile(): void
{
    foreach (range(1, 10) as $_tour) {
        if (DB::table('jobs')->doesntExist()) {
            return;
        }

        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 1, '--sleep' => 0]);
    }
}

/**
 * Le lien de confirmation que porte le courriel parti à cette adresse ; le
 * test échoue s'il n'y en a pas.
 */
function adresseMessagerieLienDeConfirmationRecuPar(string $adresse): string
{
    $transport = Mail::mailer()->getSymfonyTransport();
    assert($transport instanceof ArrayTransport);

    foreach ($transport->messages() as $envoi) {
        assert($envoi instanceof SentMessage);
        $message = $envoi->getOriginalMessage();
        assert($message instanceof Email);
        $corps = html_entity_decode((string) $message->getHtmlBody(), ENT_QUOTES | ENT_HTML5);

        if ($message->getTo()[0]->getAddress() === $adresse && preg_match('#https?://[^\s"<>]+/adresse/confirmer\?[^\s"<>]+#', $corps, $lien) === 1) {
            return $lien[0];
        }
    }

    throw new LogicException('Aucun lien de confirmation n’est parti à cette adresse.');
}

/**
 * L'inscription telle que la page la poste, comme la première de cet
 * appareil : session et limiteurs à neuf.
 *
 * @return TestResponse<Response>
 */
function adresseMessagerieInscrire(string $adresse): TestResponse
{
    test()->flushSession();
    Cache::flush();

    /** @var TestResponse<Response> $reponse */
    $reponse = test()->from(route('register'))
        ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->post(route('register'), [
            'name' => 'Camille Martin',
            'email' => $adresse,
            'password' => 'Un-mot-de-passe-solide-42!',
            'password_confirmation' => 'Un-mot-de-passe-solide-42!',
        ]);

    test()->flushHeaders();

    return $reponse;
}

/**
 * La demande de changement d'adresse telle que le profil la poste, avec le
 * mot de passe du compte (`password`), session et limiteurs à neuf.
 *
 * @return TestResponse<Response>
 */
function adresseMessagerieChangerDAdresse(User $compte, string $adresse): TestResponse
{
    test()->flushSession();
    Cache::flush();

    /** @var TestResponse<Response> $reponse */
    $reponse = actingAs($compte)
        ->from(route('profile.edit'))
        ->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
        ->patch(route('profile.update'), ['name' => $compte->name, 'email' => $adresse, 'current_password' => 'password']);

    test()->flushHeaders();

    return $reponse;
}

/**
 * Le retour d'un fournisseur avec cette adresse, garantie, construit comme
 * son pilote le construit (voir `ConnexionSocialeAdresseExacteTest`).
 */
function adresseMessagerieRetourSocial(string $fournisseur, string $adresse, string $identifiant = '4242'): SocialiteUser
{
    return match ($fournisseur) {
        'google' => new SocialiteUser()
            ->setRaw(['sub' => $identifiant, 'email' => $adresse, 'email_verified' => true, 'name' => 'Camille Martin'])
            ->map(['id' => $identifiant, 'nickname' => null, 'name' => 'Camille Martin', 'email' => $adresse, 'avatar' => null]),
        'github' => new SocialiteUser()
            ->setRaw(['id' => (int) $identifiant, 'login' => 'cmartin', 'name' => 'Camille Martin', 'email' => $adresse])
            ->map(['id' => (int) $identifiant, 'nickname' => 'cmartin', 'name' => 'Camille Martin', 'email' => $adresse, 'avatar' => null]),
        'apple' => new SocialiteUser()
            ->setRaw(['sub' => $identifiant, 'email' => $adresse, 'email_verified' => 'true', 'is_private_email' => 'false'])
            ->map(['id' => $identifiant, 'name' => null, 'email' => $adresse]),
        default => throw new InvalidArgumentException($fournisseur),
    };
}

/**
 * Le fournisseur rend ce retour à la route de rappel.
 */
function adresseMessagerieLeFournisseurRend(string $fournisseur, SocialiteUser $retour): void
{
    Socialite::shouldReceive('driver')->with($fournisseur)->andReturn(new readonly class($retour) implements Provider
    {
        public function __construct(private SocialiteUser $retour)
        {
        }

        public function redirect(): never
        {
            throw new LogicException('Ce test n’emprunte pas la redirection.');
        }

        public function user(): SocialiteUser
        {
            return $this->retour;
        }
    });
}

function adresseMessagerieRefusSocial(string $fournisseur): string
{
    return 'L\'adresse transmise par '.ucfirst($fournisseur).' n\'est pas une adresse email que nous acceptons, et l\'inscription la refuserait de même. Si vous avez déjà un compte, connectez-vous avec son adresse email et votre mot de passe ; sinon, inscrivez-vous avec une autre adresse.';
}

/*
 * La règle dit d'une adresse ce qu'en fait la messagerie : elle n'admet que
 * celle à laquelle un courriel part, telle quelle.
 */
it('mesure ce que la règle email admet et que Symfony Mime refuse d’écrire', function (string $adresse, bool $laRegleEmailLAdmet, bool $unCourrielPart): void {
    expect([
        'la règle email' => Validator::make(['email' => $adresse], ['email' => ['email']])->passes(),
        'AdresseQueLaMessagerieSaitEcrire' => AdresseQueLaMessagerieSaitEcrire::laMessagerieSaitLEcrire($adresse),
        'Symfony Mime' => adresseMessagerieUnCourrielPart($adresse),
    ])->toBe([
        'la règle email' => $laRegleEmailLAdmet,
        'AdresseQueLaMessagerieSaitEcrire' => $unCourrielPart,
        'Symfony Mime' => $unCourrielPart,
    ]);
})->with([
    ...array_map(static fn (array $cas): array => [...$cas, true, false], $admisesParLaRegleEmailRefuseesParMime),
    ...array_map(static fn (array $cas): array => [...$cas, false, false], $refuseesParLaRegleEmailCommeParMime),
    ...array_map(static fn (array $cas): array => [...$cas, false, true], $refuseesParLaRegleEmailEcritesParMime),
    ...array_map(static fn (array $cas): array => [...$cas, false, false], $coupeesParLaMessagerie),
    ...array_map(static fn (array $cas): array => [...$cas, true, true], $ecritesParMime),
]);

it('refuse à l’inscription une adresse que Symfony Mime refuse d’écrire, par le message du format, sans rien créer ni envoyer', function (string $adresse): void {
    adresseMessagerieInscrire($adresse)->assertSessionHasErrors(['email' => adresseMessagerieMessageDuFormat()]);

    assertGuest();
    expect(User::query()->count())->toBe(0)
        ->and(adresseMessagerieDestinataires())->toBe([]);
})->with('adresses que Mime refuse d’écrire');

it('inscrit une adresse que Symfony Mime écrit, et lui envoie le lien qui ouvre le compte', function (string $adresse): void {
    adresseMessagerieInscrire($adresse)->assertSessionHasNoErrors()->assertRedirect(route('login'));

    $compte = User::query()->sole();

    expect($compte->email)->toBe($adresse)
        ->and($compte->estEnAttente())->toBeTrue()
        ->and(adresseMessagerieDestinataires())->toBe([$adresse]);
})->with('adresses que Mime écrit');

it('refuse au profil une nouvelle adresse que Symfony Mime refuse d’écrire, par le message du format, sans rien envoyer', function (string $adresse): void {
    $compte = User::factory()->create(['name' => 'Titulaire', 'email' => 'titulaire@example.org']);

    adresseMessagerieChangerDAdresse($compte, $adresse)->assertSessionHasErrors(['email' => adresseMessagerieMessageDuFormat()]);

    expect($compte->refresh()->email)->toBe('titulaire@example.org')
        ->and(adresseMessagerieDestinataires())->toBe([]);
})->with('adresses que Mime refuse d’écrire');

it('envoie au profil le lien de changement à une nouvelle adresse que Symfony Mime écrit', function (string $adresse): void {
    $compte = User::factory()->create(['name' => 'Titulaire', 'email' => 'titulaire@example.org']);

    adresseMessagerieChangerDAdresse($compte, $adresse)
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'lien-de-changement-d-adresse-envoye');

    expect(adresseMessagerieDestinataires())->toBe([$adresse]);
})->with('adresses que Mime écrit');

it('refuse au panneau une adresse que Symfony Mime refuse d’écrire, à la création comme à la modification', function (string $adresse): void {
    actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('User')), 'admin');
    $compte = User::factory()->create(['email' => 'camille@example.org']);

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Camille Martin', 'email' => $adresse, 'default_rest_time' => 90, 'password' => 'Un-mot-de-passe-solide-42!'])
        ->call('create')
        ->assertHasFormErrors(['email']);

    Livewire::test(EditUser::class, ['record' => $compte->getKey()])
        ->fillForm(['email' => $adresse])
        ->call('save')
        ->assertHasFormErrors(['email']);

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('edit')->table($compte), ['email' => $adresse])
        ->assertHasFormErrors(['email']);

    expect(User::query()->count())->toBe(1)
        ->and($compte->refresh()->email)->toBe('camille@example.org');
})->with('adresses que Mime refuse d’écrire');

it('crée au panneau un compte sur une adresse que Symfony Mime écrit, puis l’enregistre sans en changer', function (string $adresse): void {
    actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('User')), 'admin');

    Livewire::test(CreateUser::class)
        ->fillForm(['name' => 'Camille Martin', 'email' => $adresse, 'default_rest_time' => 90, 'password' => 'Un-mot-de-passe-solide-42!'])
        ->call('create')
        ->assertHasNoFormErrors();

    $compte = User::query()->sole();

    Livewire::test(EditUser::class, ['record' => $compte->getKey()])
        ->fillForm(['name' => 'Camille Durand'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($compte->refresh()->email)->toBe($adresse)
        ->and($compte->name)->toBe('Camille Durand');
})->with('adresses que Mime écrit');

it('refuse à la connexion sociale une adresse que Symfony Mime refuse d’écrire, sans créer de compte', function (string $adresse): void {
    adresseMessagerieLeFournisseurRend('google', adresseMessagerieRetourSocial('google', $adresse));

    get(route('social.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', adresseMessagerieRefusSocial('google'));

    assertGuest();
    expect(User::query()->count())->toBe(0);
})->with('adresses que Mime refuse d’écrire');

it('refuse de même l’adresse que rend chaque fournisseur', function (string $fournisseur): void {
    adresseMessagerieLeFournisseurRend($fournisseur, adresseMessagerieRetourSocial($fournisseur, 'camille(salle)@example.org'));

    get(route('social.callback', $fournisseur))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', adresseMessagerieRefusSocial($fournisseur));

    assertGuest();
    expect(User::query()->count())->toBe(0);
})->with(['google', 'github', 'apple']);

/*
 * La connexion sociale ne crée pas davantage un compte que l'inscription
 * refuserait : sans quoi le compte naîtrait sous une adresse que le profil et
 * le panneau ne jugent plus, faute de changement, et que « mot de passe
 * oublié » refuse.
 */
it('refuse à la connexion sociale une adresse que l’inscription refuse et que Mime écrirait, sans créer de compte', function (string $adresse): void {
    adresseMessagerieLeFournisseurRend('google', adresseMessagerieRetourSocial('google', $adresse));

    get(route('social.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', adresseMessagerieRefusSocial('google'));

    assertGuest();
    expect(User::query()->count())->toBe(0);
})->with('adresses que l’inscription refuse et que Mime écrit');

it('crée à la connexion sociale le compte d’une adresse que Symfony Mime écrit', function (string $adresse): void {
    adresseMessagerieLeFournisseurRend('google', adresseMessagerieRetourSocial('google', $adresse));

    get(route('social.callback', 'google'))->assertRedirect(route('dashboard'));

    $compte = User::query()->sole();

    assertAuthenticatedAs($compte);
    expect($compte->email)->toBe($adresse)
        ->and($compte->hasVerifiedEmail())->toBeTrue();
})->with('adresses que Mime écrit');

/*
 * Le refus ne dit rien d'un compte : c'est le message de toute adresse mal
 * formée, et la réponse entière est la même, qu'un compte plus ancien porte
 * l'adresse ou non (`InscriptionMemeReponseTest`).
 */
it('refuse à l’inscription, à l’identique, une adresse que Mime refuse d’écrire, qu’un compte la porte ou non, comme toute adresse mal formée', function (): void {
    $this->freezeSecond();

    $sansCompte = CeQueLeClientLit::de(adresseMessagerieInscrire('camille(salle)@example.org'), route('register'));

    User::factory()->create(['email' => 'camille(salle)@example.org']);

    $avecCompte = CeQueLeClientLit::de(adresseMessagerieInscrire('camille(salle)@example.org'), route('register'));
    $malFormee = CeQueLeClientLit::de(adresseMessagerieInscrire('camille..martin@example.org'), route('register'));

    expect($sansCompte['statut'])->toBe(302)
        ->and($sansCompte['session']['errors'] ?? null)->toBe(['default' => ['email' => [adresseMessagerieMessageDuFormat()]]])
        ->and($avecCompte)->toBe($sansCompte)
        ->and($malFormee['session']['errors'] ?? null)->toBe($sansCompte['session']['errors']);
});

it('refuse au profil, à l’identique, une nouvelle adresse que Mime refuse d’écrire, qu’un compte la porte ou non', function (): void {
    $this->freezeSecond();
    $compte = User::factory()->create(['name' => 'Titulaire', 'email' => 'titulaire@example.org']);

    $sansCompte = CeQueLeClientLit::de(adresseMessagerieChangerDAdresse($compte, 'camille(salle)@example.org'), route('profile.edit'));

    User::factory()->create(['email' => 'camille(salle)@example.org']);

    $avecCompte = CeQueLeClientLit::de(adresseMessagerieChangerDAdresse($compte, 'camille(salle)@example.org'), route('profile.edit'));

    expect($sansCompte['statut'])->toBe(303)
        ->and($sansCompte['session']['errors'] ?? null)->toBe(['default' => ['email' => [adresseMessagerieMessageDuFormat()]]])
        ->and($avecCompte)->toBe($sansCompte)
        ->and(adresseMessagerieDestinataires())->toBe([]);
});

/*
 * « Mot de passe oublié » répond la même chose pour toute adresse bien formée
 * (#1951) : la règle ne s'y ajoute pas, et un compte dont l'adresse est
 * antérieure à la règle n'y change rien. Le job tourne ici dans la requête,
 * la file des tests étant synchrone : il trouve le compte, crée son jeton, et
 * n'envoie rien (`User::routeNotificationForMail()`). L'envoi refusé faisait
 * auparavant répondre 500 à la seconde demande.
 */
it('ne change pas la réponse de « mot de passe oublié » pour une adresse que Mime refuse d’écrire', function (): void {
    $this->freezeSecond();

    $demander = static function (): array {
        test()->flushSession();
        Cache::flush();

        /** @var TestResponse<Response> $reponse */
        $reponse = test()->from(route('password.request'))->post(route('password.email'), ['email' => 'camille(salle)@example.org']);

        return CeQueLeClientLit::de($reponse, route('password.request'));
    };

    $sansCompte = $demander();

    User::factory()->create(['email' => 'camille(salle)@example.org']);

    expect($sansCompte['session']['status'] ?? null)->toBe(__('passwords.sent'))
        ->and($demander())->toBe($sansCompte)
        ->and(DB::table('password_reset_tokens')->count())->toBe(1)
        ->and(adresseMessagerieDestinataires())->toBe([]);
});

/*
 * Un compte dont l'adresse est antérieure à la règle n'est pas bloqué : la
 * règle ne juge qu'une adresse qui change. Il se connecte, enregistre son nom
 * au profil et au panneau sans toucher à son adresse, et en change pour une
 * adresse que Mime écrit, qui reçoit son lien. L'ancienne adresse n'est pas
 * prévenue du changement (`SurveilleSonAdresse`) : la messagerie ne
 * l'écrirait pas telle quelle, et l'avis ne laisserait qu'un job en échec, une
 * erreur 500 quand la file est synchrone, comme ici, ou partirait à une autre
 * adresse.
 */
it('laisse un compte dont l’adresse est antérieure à la règle se connecter, enregistrer son nom et changer d’adresse', function (string $adresse): void {
    $compte = User::factory()->create(['name' => 'Camille Martin', 'email' => $adresse]);

    post(route('login'), ['email' => $adresse, 'password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($compte);

    actingAs($compte)
        ->patch(route('profile.update'), ['name' => 'Camille Durand', 'email' => $adresse])
        ->assertSessionHasNoErrors();

    expect($compte->refresh()->name)->toBe('Camille Durand')
        ->and($compte->email)->toBe($adresse);

    adresseMessagerieChangerDAdresse($compte, 'camille.martin@example.org')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status', 'lien-de-changement-d-adresse-envoye');

    actingAs($compte)
        ->get(adresseMessagerieLienDeConfirmationRecuPar('camille.martin@example.org'))
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHas('status', 'adresse-confirmee');

    expect($compte->refresh()->email)->toBe('camille.martin@example.org')
        ->and($compte->hasVerifiedEmail())->toBeTrue()
        ->and(adresseMessagerieDestinataires())->toBe(['camille.martin@example.org']);
})->with('adresses d’un compte que la messagerie n’écrit pas telle quelle');

it('laisse le panneau enregistrer un compte dont l’adresse est antérieure à la règle, puis la changer', function (): void {
    actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('User')), 'admin');
    $compte = User::factory()->create(['name' => 'Camille Martin', 'email' => 'camille(salle)@example.org']);

    Livewire::test(EditUser::class, ['record' => $compte->getKey()])
        ->fillForm(['name' => 'Camille Durand'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($compte->refresh()->name)->toBe('Camille Durand')
        ->and($compte->email)->toBe('camille(salle)@example.org');

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('edit')->table($compte), ['email' => 'camille.martin@example.org'])
        ->assertHasNoFormErrors();

    expect($compte->refresh()->email)->toBe('camille.martin@example.org')
        ->and(adresseMessagerieDestinataires())->toBe([]);
});

/*
 * Le format ne se juge que d'une adresse qui change, au profil comme au
 * panneau : la règle `email` refusait aussi l'adresse inchangée d'un compte
 * que la connexion sociale avait créé sans elle (points consécutifs, tiret
 * bas dans le domaine…), qui ne pouvait plus enregistrer son nom. Un tel
 * compte, sans mot de passe connu, ne peut pas non plus changer d'adresse.
 */
it('laisse un compte relié à un fournisseur, dont l’adresse est antérieure à la règle, enregistrer son nom au profil', function (string $adresse): void {
    $compte = User::factory()->create(['name' => 'Avant', 'email' => $adresse]);
    $compte->forceFill(['provider' => 'google', 'provider_id' => '4242', 'liaison_prouvee_le' => now()])->save();

    actingAs($compte)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), ['name' => 'Après', 'email' => $adresse])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($compte->refresh()->name)->toBe('Après')
        ->and($compte->email)->toBe($adresse)
        ->and(adresseMessagerieDestinataires())->toBe([]);
})->with('adresses d’un compte antérieur à la règle');

it('laisse le panneau enregistrer le nom d’un compte dont l’adresse est antérieure à la règle, par la page comme par la table', function (string $adresse): void {
    actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('User')), 'admin');
    $compte = User::factory()->create(['name' => 'Avant', 'email' => $adresse]);

    Livewire::test(EditUser::class, ['record' => $compte->getKey()])
        ->fillForm(['name' => 'Par la page'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($compte->refresh()->name)->toBe('Par la page');

    Livewire::test(ListUsers::class)
        ->callAction(TestAction::make('edit')->table($compte), ['name' => 'Par la table'])
        ->assertHasNoFormErrors();

    expect($compte->refresh()->name)->toBe('Par la table')
        ->and($compte->email)->toBe($adresse);
})->with('adresses d’un compte antérieur à la règle');

/*
 * Le champ du panneau n'est pas `type="email"` : le navigateur, qui juge selon
 * la spécification HTML, refuserait d'envoyer le formulaire d'un compte dont
 * l'adresse actuelle porte un commentaire, un tiret bas dans le domaine ou un
 * point final, avant que le serveur ne la laisse passer.
 */
it('saisit l’adresse au panneau sans la validation du navigateur, au clavier d’une adresse', function (): void {
    actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('User')), 'admin');
    $compte = User::factory()->create(['email' => 'camille(salle)@example.org']);

    Livewire::test(EditUser::class, ['record' => $compte->getKey()])
        ->assertFormFieldExists('email', static fn (TextInput $champ): bool => $champ->getType() === 'text' && $champ->getInputMode() === 'email');
});

/*
 * Un compte non vérifié qui n'attend pas son lien d'ouverture (inscrit avant
 * l'attente, ou dont le panneau a changé l'adresse) reçoit le lien de
 * vérification de Laravel, qui part dans la requête : Mime refusait l'adresse
 * et la page répondait 500. Rien ne part plus, et la page renvoie au profil.
 */
it('n’envoie pas de lien de vérification à une adresse que Mime refuse d’écrire, et dit d’en changer', function (string $adresse): void {
    $compte = User::factory()->unverified()->create(['email' => $adresse]);

    expect($compte->estEnAttente())->toBeFalse();

    actingAs($compte)
        ->from(route('verification.notice'))
        ->post(route('verification.send'))
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHas('status', 'adresse-que-la-messagerie-n-ecrit-pas');

    expect(adresseMessagerieDestinataires())->toBe([]);
})->with('adresses d’un compte que la messagerie n’écrit pas telle quelle');

it('envoie toujours le lien de vérification à un compte non vérifié dont Mime écrit l’adresse', function (): void {
    $compte = User::factory()->unverified()->create(['email' => 'camille@example.org']);

    actingAs($compte)
        ->from(route('verification.notice'))
        ->post(route('verification.send'))
        ->assertRedirect(route('verification.notice'))
        ->assertSessionHas('status', 'verification-link-sent');

    expect(adresseMessagerieDestinataires())->toBe(['camille@example.org']);
});

/*
 * Le lien d'ouverture d'un compte en attente part en file : il n'aurait pas
 * fait de 500, mais un job en échec à chaque bon mot de passe. La connexion
 * répond comme avant.
 */
it('ne renvoie pas le lien d’ouverture à un compte en attente dont Mime refuse d’écrire l’adresse', function (): void {
    Notification::fake();
    User::factory()->enAttente()->create(['email' => 'camille(salle)@example.org']);

    post(route('login'), ['email' => 'camille(salle)@example.org', 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    assertGuest();
    Notification::assertNothingSent();
});

it('n’envoie aucun lien de vérification à une adresse que Mime refuse d’écrire, que le compte soit en attente ou non', function (bool $enAttente, string $adresse): void {
    Notification::fake();
    $fabrique = User::factory()->unverified();
    $compte = ($enAttente ? $fabrique->enAttente() : $fabrique)->create(['email' => $adresse]);

    $compte->sendEmailVerificationNotification();

    expect($compte->estEnAttente())->toBe($enAttente)
        ->and($compte->laMessagerieSaitEcrireSonAdresse())->toBeFalse();
    Notification::assertNothingSent();
})->with(['en attente' => [true], 'hors attente' => [false]])->with([
    ...$admisesParLaRegleEmailRefuseesParMime,
    ...$coupeesParLaMessagerie,
]);

/*
 * Aucun courriel ne part à un compte dont la messagerie n'écrit pas l'adresse
 * telle quelle (décision du propriétaire) : sa route est vide
 * (`User::routeNotificationForMail()`), et le canal `mail` n'envoie rien, sans
 * erreur, à une route vide. Le lien « mot de passe oublié », mis en file par
 * la demande, termine son job sans échec, comme l'avis d'une tentative sur
 * l'adresse ; un avis envoyé aussitôt ne lève rien. Avant, Mime refusait
 * l'adresse à commentaire (job en échec, erreur masquée), Laravel refusait un
 * saut de ligne, Mime coupait une espace ou une tabulation, et lisait
 * l'adresse entre les chevrons d'une partie locale entre guillemets : le
 * courriel partait alors à une autre adresse.
 */
it('n’envoie aucun courriel à un compte dont la messagerie n’écrit pas l’adresse telle quelle, sans erreur ni job en échec', function (string $adresse): void {
    config(['queue.default' => 'database']);
    $compte = User::factory()->create(['email' => $adresse]);

    EnvoyerLeLienDeReinitialisation::dispatch($adresse, 'fr');
    $compte->notify(new TentativeSurTonAdresse(MotifDeLaTentative::Inscription));

    expect(DB::table('jobs')->count())->toBe(2);

    adresseMessagerieViderLaFile();
    $compte->notifyNow(new TentativeSurTonAdresse(MotifDeLaTentative::ChangementDAdresse));
    $compte->notify(new ResetPassword('un-jeton-quelconque'));

    expect($compte->routeNotificationFor('mail'))->toBeNull()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('password_reset_tokens')->count())->toBe(1)
        ->and(ExceptionEnregistree::query()->count())->toBe(0)
        ->and(adresseMessagerieDestinataires())->toBe([]);
})->with([
    ...$admisesParLaRegleEmailRefuseesParMime,
    ...$coupeesParLaMessagerie,
]);

/*
 * Un compte dont la messagerie écrit l'adresse reçoit ses courriels comme
 * avant, en file comme aussitôt : à son adresse seule, sans nom, comme la
 * route de Laravel la rendait (`$this->email`), quelle que soit la forme de
 * l'adresse que Mime écrit.
 */
it('envoie toujours ses courriels à un compte dont la messagerie écrit l’adresse, à l’adresse seule, comme Laravel', function (string $adresse): void {
    config(['queue.default' => 'database']);
    $compte = User::factory()->create(['name' => 'Camille Martin', 'email' => $adresse]);

    EnvoyerLeLienDeReinitialisation::dispatch($adresse, 'fr');
    $compte->notify(new TentativeSurTonAdresse(MotifDeLaTentative::Inscription));
    adresseMessagerieViderLaFile();
    $compte->notifyNow(new TentativeSurTonAdresse(MotifDeLaTentative::ChangementDAdresse));

    expect($compte->routeNotificationFor('mail'))->toBe($adresse)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(adresseMessagerieEnTetesDesDestinataires())->toBe(array_fill(0, 3, [['adresse' => $adresse, 'nom' => '']]));
})->with('adresses que Mime écrit');

/*
 * Une notification envoyée à plusieurs comptes d'un coup, par la façade, part
 * à chacun dont la messagerie écrit l'adresse, et à lui seul : la route vide
 * d'un compte ne retient pas les autres.
 */
it('n’envoie une notification adressée à plusieurs comptes qu’à ceux dont la messagerie écrit l’adresse', function (): void {
    $comptes = collect([
        User::factory()->create(['email' => 'camille(salle)@example.org']),
        User::factory()->create(['email' => 'camille.martin@example.org']),
        User::factory()->create(['email' => '"camille<autre@example.net>"@example.org']),
        User::factory()->create(['email' => 'camille.durand@example.org']),
    ]);

    Notification::sendNow($comptes, new TentativeSurTonAdresse(MotifDeLaTentative::Inscription));

    expect(adresseMessagerieDestinataires())->toBe(['camille.martin@example.org', 'camille.durand@example.org']);
});

/*
 * Une notification à la demande (`Notification::route()`) part à l'adresse
 * qu'elle nomme, sans passer par la route d'un compte : un compte qui porte la
 * même adresse n'y change rien, ni pour une adresse que la messagerie écrit,
 * ni pour une autre, que Mime refuse comme avant. Chaque envoi de ce genre
 * juge donc lui-même son adresse : l'avis d'un changement d'adresse ne part
 * pas à une ancienne adresse que la messagerie n'écrit pas
 * (`SurveilleSonAdresse`, plus haut), et le lien de changement ne part qu'à
 * une adresse que le profil a jugée.
 */
it('laisse une notification à la demande partir à l’adresse qu’elle nomme, comme avant', function (): void {
    User::factory()->create(['email' => 'camille.martin@example.org']);
    User::factory()->create(['email' => 'camille(salle)@example.org']);

    Notification::route('mail', 'camille.martin@example.org')
        ->notifyNow(new LienDeChangementDAdresse('https://gym.example.org/adresse/confirmer'));

    expect(adresseMessagerieEnTetesDesDestinataires())->toBe([[['adresse' => 'camille.martin@example.org', 'nom' => '']]])
        ->and(static function (): void {
            Notification::route('mail', 'camille(salle)@example.org')
                ->notifyNow(new LienDeChangementDAdresse('https://gym.example.org/adresse/confirmer'));
        })
        ->toThrow(RfcComplianceException::class);
});

/*
 * La connexion sociale ne refuse que le compte qu'elle créerait : un compte
 * existant, relié à l'identité ou porteur de l'adresse exacte, s'ouvre comme
 * avant.
 */
it('ouvre par un fournisseur le compte dont l’adresse est antérieure à la règle, par son identité comme par son adresse', function (?string $identiteDejaReliee): void {
    $compte = User::factory()->create(['email' => 'camille(salle)@example.org']);

    if ($identiteDejaReliee !== null) {
        $compte->forceFill(['provider' => 'google', 'provider_id' => $identiteDejaReliee, 'liaison_prouvee_le' => now()])->save();
    }

    adresseMessagerieLeFournisseurRend('google', adresseMessagerieRetourSocial('google', 'camille(salle)@example.org', '4242'));

    get(route('social.callback', 'google'))->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($compte);
    expect(User::query()->count())->toBe(1)
        ->and($compte->refresh()->provider_id)->toBe('4242');
})->with([
    'identité déjà reliée' => ['4242'],
    'rattachement par l’adresse exacte' => [null],
]);
