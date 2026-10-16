<?php

declare(strict_types=1);

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Jobs\EnvoyerLeLienDeReinitialisation;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Mail\Message;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\SentMessage;
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
 * mesure ci-dessous dit quelles formes la règle `email` admet et Mime refuse :
 * le commentaire dans la partie locale, seul. La partie locale entre
 * guillemets, le domaine littéral, le domaine sans point, la partie locale de
 * plus de 64 caractères et le commentaire dans le domaine passent l'une et
 * l'autre ; les points consécutifs, aucune.
 *
 * `AdresseQueLaMessagerieSaitEcrire` refuse ce que Mime refuse, aux quatre
 * entrées d'une adresse qui recevra un courriel : l'inscription, le profil, le
 * panneau, et l'adresse que rend un fournisseur de connexion, qui ne passait
 * par aucune règle `email`. Le refus est le message de toute adresse mal
 * formée, et ne dit rien d'un compte. Un compte dont l'adresse est antérieure
 * à la règle se connecte, enregistre son nom et change d'adresse comme avant ;
 * la connexion et « mot de passe oublié » ne jugent pas l'adresse davantage.
 *
 * Les courriels partent pour de bon, par le transport `array` des tests : une
 * adresse admise reçoit son lien.
 */

$admisesParLaRegleEmailRefuseesParMime = [
    'un commentaire après la partie locale' => ['camille(salle)@example.org'],
    'un commentaire avant la partie locale' => ['(salle)camille@example.org'],
    'un commentaire imbriqué' => ['camille((salle))@example.org'],
    'un commentaire dans la partie locale et dans le domaine' => ['camille(salle)@example(sud).org'],
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

dataset('adresses que la règle email admet et que Mime refuse', $admisesParLaRegleEmailRefuseesParMime);
dataset('adresses que Mime refuse d’écrire', [...$admisesParLaRegleEmailRefuseesParMime, ...$refuseesParLaRegleEmailCommeParMime]);
dataset('adresses que Mime écrit', $ecritesParMime);

/**
 * Le message de la règle `email`, celui de toute adresse mal formée.
 */
function adresseMessagerieMessageDuFormat(): string
{
    return 'Le champ adresse e-mail doit être une adresse e-mail valide.';
}

/**
 * Un courriel part-il à cette adresse ? Il part par le courrielleur de
 * l'application, comme les liens, et Symfony Mime refuse d'écrire ce qu'il ne
 * sait pas écrire.
 */
function adresseMessagerieUnCourrielPart(string $adresse): bool
{
    try {
        Mail::raw('Bonjour', static function (Message $message) use ($adresse): void {
            $message->to($adresse)->subject('Essai');
        });
    } catch (Throwable $erreur) {
        if ($erreur instanceof RfcComplianceException) {
            return false;
        }

        throw $erreur;
    }

    return true;
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
    return 'L\'adresse transmise par '.ucfirst($fournisseur).' ne peut pas recevoir nos courriels : notre messagerie ne sait pas l\'écrire comme destinataire. Si vous avez déjà un compte, connectez-vous avec son adresse email et votre mot de passe ; sinon, inscrivez-vous avec une autre adresse.';
}

it('mesure ce que la règle email admet et que Symfony Mime refuse d’écrire', function (string $adresse, bool $laRegleEmailLAdmet, bool $unCourrielPart): void {
    expect([
        'la règle email' => Validator::make(['email' => $adresse], ['email' => ['email']])->passes(),
        'Symfony Mime' => adresseMessagerieUnCourrielPart($adresse),
    ])->toBe([
        'la règle email' => $laRegleEmailLAdmet,
        'Symfony Mime' => $unCourrielPart,
    ]);
})->with([
    ...array_map(static fn (array $cas): array => [...$cas, true, false], $admisesParLaRegleEmailRefuseesParMime),
    ...array_map(static fn (array $cas): array => [...$cas, false, false], $refuseesParLaRegleEmailCommeParMime),
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
 * antérieure à la règle n'y change rien. Le job, qui échouerait à l'envoi, est
 * retenu ici : la réponse n'en dépend pas.
 */
it('ne change pas la réponse de « mot de passe oublié » pour une adresse que Mime refuse d’écrire', function (): void {
    Queue::fake([EnvoyerLeLienDeReinitialisation::class]);
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
        ->and($demander())->toBe($sansCompte);

    Queue::assertPushed(EnvoyerLeLienDeReinitialisation::class, 2);
});

/*
 * Un compte dont l'adresse est antérieure à la règle n'est pas bloqué : la
 * règle ne juge qu'une adresse qui change. Il se connecte, enregistre son nom
 * au profil et au panneau sans toucher à son adresse, et en change pour une
 * adresse que Mime écrit, qui reçoit son lien. L'ancienne adresse n'est pas
 * prévenue du changement (`SurveilleSonAdresse`) : Mime ne l'écrirait pas, et
 * l'avis ne laisserait qu'un job en échec, ou une erreur 500 quand la file est
 * synchrone, comme ici.
 */
it('laisse un compte dont l’adresse est antérieure à la règle se connecter, enregistrer son nom et changer d’adresse', function (): void {
    $compte = User::factory()->create(['name' => 'Camille Martin', 'email' => 'camille(salle)@example.org']);

    post(route('login'), ['email' => 'camille(salle)@example.org', 'password' => 'password'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard'));

    assertAuthenticatedAs($compte);

    actingAs($compte)
        ->patch(route('profile.update'), ['name' => 'Camille Durand', 'email' => 'camille(salle)@example.org'])
        ->assertSessionHasNoErrors();

    expect($compte->refresh()->name)->toBe('Camille Durand')
        ->and($compte->email)->toBe('camille(salle)@example.org');

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
});

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
