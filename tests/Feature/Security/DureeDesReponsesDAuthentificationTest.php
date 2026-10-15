<?php

declare(strict_types=1);

use App\Jobs\EnvoyerLeLienDeReinitialisation;
use App\Models\Admin;
use App\Models\User;
use App\Support\DureeDesReponsesDAuthentification;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login as ConnexionDuPanneau;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\Support\DureeSimulee;
use Tests\Support\FilamentAdminPanel;
use Tests\Support\FormulaireDeReinitialisation;
use Tests\Support\TimeboxEspionnee;

use function Pest\Laravel\assertGuest;

/*
 * Une réponse d'authentification dure le même temps, que l'adresse ait un
 * compte ou non.
 *
 * Mesuré sur main, au coût bcrypt de production : une connexion ratée durait
 * 207 ms pour une adresse inconnue et 280 à 300 ms pour une adresse connue
 * (la vérification du mot de passe dépassait la Timebox de 200 ms de la
 * garde), une inscription 6 ms pour une adresse prise et 300 ms pour une
 * adresse libre, et la connexion du panneau 250 ms contre 350 ms pour un
 * administrateur. Toutes passent désormais par le plancher commun
 * (`DureeDesReponsesDAuthentification`, `auth.timebox_duration`), une seconde
 * par défaut, au-dessus de bcrypt. Le sommeil est simulé (`DureeSimulee`) :
 * la durée vue du client est le temps passé plus le sommeil demandé.
 */

/**
 * Le coût bcrypt que `.env.example` donne à la production, et que phpunit.xml
 * baisse à 4.
 */
function dureeAuthentificationCoutDeProduction(): int
{
    if (preg_match('/^BCRYPT_ROUNDS=(\d+)$/m', File::get(base_path('.env.example')), $cout) !== 1) {
        throw new RuntimeException('.env.example ne donne plus BCRYPT_ROUNDS.');
    }

    return (int) $cout[1];
}

/**
 * Le plancher que donnerait `config/auth.php` avec cette valeur
 * d'`AUTH_TIMEBOX_DURATION` (null : absente).
 */
function dureeAuthentificationPlancherAvec(?string $valeur): mixed
{
    $avant = [$_SERVER['AUTH_TIMEBOX_DURATION'] ?? null, $_ENV['AUTH_TIMEBOX_DURATION'] ?? null, getenv('AUTH_TIMEBOX_DURATION')];

    try {
        unset($_SERVER['AUTH_TIMEBOX_DURATION'], $_ENV['AUTH_TIMEBOX_DURATION']);
        putenv('AUTH_TIMEBOX_DURATION');

        if ($valeur !== null) {
            $_SERVER['AUTH_TIMEBOX_DURATION'] = $_ENV['AUTH_TIMEBOX_DURATION'] = $valeur;
            putenv('AUTH_TIMEBOX_DURATION='.$valeur);
        }

        /** @var array<string, mixed> $configuration */
        $configuration = require config_path('auth.php');

        return $configuration['timebox_duration'] ?? null;
    } finally {
        unset($_SERVER['AUTH_TIMEBOX_DURATION'], $_ENV['AUTH_TIMEBOX_DURATION']);
        putenv('AUTH_TIMEBOX_DURATION');

        if ($avant[0] !== null) {
            $_SERVER['AUTH_TIMEBOX_DURATION'] = $avant[0];
        }

        if ($avant[1] !== null) {
            $_ENV['AUTH_TIMEBOX_DURATION'] = $avant[1];
        }

        if (is_string($avant[2])) {
            putenv('AUTH_TIMEBOX_DURATION='.$avant[2]);
        }
    }
}

it('prend une seconde par défaut, et la valeur numérique d’AUTH_TIMEBOX_DURATION', function (): void {
    expect(DureeDesReponsesDAuthentification::PLANCHER_PAR_DEFAUT_MICROSECONDES)->toBe(1_000_000)
        ->and(dureeAuthentificationPlancherAvec(null))->toBe(1_000_000)
        ->and(dureeAuthentificationPlancherAvec(''))->toBe(1_000_000)
        ->and(dureeAuthentificationPlancherAvec('une seconde'))->toBe(1_000_000)
        ->and(dureeAuthentificationPlancherAvec('2000000'))->toBe(2_000_000)
        ->and(dureeAuthentificationPlancherAvec('0'))->toBe(0);

    // phpunit.xml le met à 0, et la garde comme le courtier le lisent.
    expect(DureeDesReponsesDAuthentification::microsecondes())->toBe(0);
    config(['auth.timebox_duration' => 1_234_567]);
    expect(DureeDesReponsesDAuthentification::microsecondes())->toBe(1_234_567);
});

it('dépasse un hachage et une vérification bcrypt au coût de production', function (): void {
    config(['hashing.bcrypt.rounds' => dureeAuthentificationCoutDeProduction()]);
    Hash::forgetDrivers();

    $pire = 0.0;
    $empreinte = '';

    foreach (range(1, 3) as $_tour) {
        $debut = hrtime(true);
        $empreinte = Hash::make('Un-mot-de-passe-solide-42!');
        Hash::check('pas-le-bon', $empreinte);
        $pire = max($pire, (hrtime(true) - $debut) / 1_000 / 2);
    }

    expect(password_get_info($empreinte)['options']['cost'] ?? null)->toBe(dureeAuthentificationCoutDeProduction())
        ->and($pire)->toBeLessThan(DureeDesReponsesDAuthentification::PLANCHER_PAR_DEFAUT_MICROSECONDES);
});

/**
 * Chaque réponse d'authentification qui ne doit rien dire d'une adresse :
 * une fonction qui prépare le cas puis rend la requête à mesurer.
 *
 * @return array<string, array{0: Closure(): (Closure(): mixed)}>
 */
function dureeAuthentificationCas(): array
{
    $inscription = static fn (string $adresse): Closure => static fn (): mixed => test()->post(route('register'), [
        'name' => 'Camille',
        'email' => $adresse,
        'password' => 'Un-mot-de-passe-solide-42!',
        'password_confirmation' => 'Un-mot-de-passe-solide-42!',
    ])->assertRedirect(route('login'));
    $connexion = static fn (string $adresse, string $motDePasse): Closure => static fn (): mixed => test()->post(route('login'), [
        'email' => $adresse,
        'password' => $motDePasse,
    ])->assertSessionHasErrors('email');
    $oubli = static fn (string $adresse): Closure => static fn (): mixed => test()->post(route('password.email'), ['email' => $adresse])
        ->assertSessionHasNoErrors();
    $panneau = static fn (string $adresse): Closure => static function () use ($adresse): mixed {
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        return Livewire::test(ConnexionDuPanneau::class)
            ->fillForm(['email' => $adresse, 'password' => 'pas-le-bon-mot-de-passe'])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);
    };

    return [
        'inscription, adresse libre' => [static fn (): Closure => $inscription('libre@example.org')],
        'inscription, adresse prise' => [static function () use ($inscription): Closure {
            User::factory()->create(['email' => 'prise@example.org']);

            return $inscription('prise@example.org');
        }],
        'connexion, adresse inconnue' => [static fn (): Closure => $connexion('personne@example.org', 'password')],
        'connexion, mauvais mot de passe' => [static function () use ($connexion): Closure {
            User::factory()->create(['email' => 'prise@example.org']);

            return $connexion('prise@example.org', 'pas-le-bon');
        }],
        'connexion, compte en attente sans son lien' => [static function () use ($connexion): Closure {
            User::factory()->enAttente()->create(['email' => 'en-attente@example.org']);

            return $connexion('en-attente@example.org', 'password');
        }],
        'mot de passe oublié, adresse inconnue' => [static fn (): Closure => $oubli('personne@example.org')],
        'mot de passe oublié, adresse connue' => [static function () use ($oubli): Closure {
            User::factory()->create(['email' => 'prise@example.org']);

            return $oubli('prise@example.org');
        }],
        'réinitialisation, jeton faux d’un compte' => [static function (): Closure {
            $compte = User::factory()->create(['email' => 'prise@example.org']);
            Password::createToken($compte);

            return static fn (): mixed => test()->post(
                route('password.store'),
                FormulaireDeReinitialisation::pour($compte, 'Un-nouveau-mot-de-passe-2026!', 'un-jeton-quelconque'),
            )->assertSessionHasErrors('token');
        }],
        'réinitialisation, adresse du lien qui ne se déchiffre pas' => [static function (): Closure {
            $compte = User::factory()->create(['email' => 'prise@example.org']);
            $formulaire = FormulaireDeReinitialisation::pour($compte, 'Un-nouveau-mot-de-passe-2026!');
            $formulaire['adresse'] = strrev($formulaire['adresse']);

            return static fn (): mixed => test()->post(route('password.store'), $formulaire)->assertSessionHasErrors('token');
        }],
        'réinitialisation, lien d’avant le chiffrement (adresse en clair)' => [static function (): Closure {
            $compte = User::factory()->create(['email' => 'prise@example.org']);
            $jeton = Password::createToken($compte);

            return static fn (): mixed => test()->post(route('password.store'), [
                'token' => $jeton,
                'email' => 'prise@example.org',
                'password' => 'Un-nouveau-mot-de-passe-2026!',
                'password_confirmation' => 'Un-nouveau-mot-de-passe-2026!',
            ])->assertSessionHasErrors('token');
        }],
        'panneau, adresse inconnue' => [static fn (): Closure => $panneau('personne@example.org')],
        'panneau, administrateur' => [static function () use ($panneau): Closure {
            Admin::factory()->create(['email' => 'admin@example.org']);

            return $panneau('admin@example.org');
        }],
    ];
}

it('dure au moins le plancher commun, une seule fois, sommeil compris', function (Closure $preparer): void {
    Notification::fake();
    $duree = DureeSimulee::demarrer();
    /** @var Closure(): mixed $requete */
    $requete = $preparer();
    Cache::flush();

    $mesure = $duree->mesurer($requete);

    expect($mesure['total'])->toBeGreaterThanOrEqual($duree->plancher)
        ->and($mesure['total'])->toBeLessThanOrEqual($duree->plancher + $mesure['reel'] + 1_000)
        ->and($mesure['dormi'])->toBeGreaterThan(0.0)
        ->and($mesure['reel'])->toBeLessThan($duree->plancher);
})->with(dureeAuthentificationCas());

/*
 * La Timebox d'un contrôleur englobe celle de la garde ou du courtier, réglée
 * sur le même plancher : en vrai, l'extérieure voit passer le sommeil de
 * l'intérieure et ne redort pas, et rien ne couvre un travail fait après
 * l'intérieure. La mesure le voit comme le client.
 */
it('compte une fois le sommeil de deux Timebox emboîtées, deux fois celui de deux Timebox successives', function (): void {
    $duree = DureeSimulee::demarrer();
    $plancher = $duree->plancher;
    $rien = static fn (): null => null;

    $emboitees = $duree->mesurer(static fn (): mixed => new Timebox()->call(
        static fn (): mixed => new Timebox()->call($rien, $plancher),
        $plancher,
    ));
    $travailLentApres = $duree->mesurer(static function () use ($rien, $plancher): void {
        new Timebox()->call(static function () use ($rien, $plancher): void {
            new Timebox()->call($rien, $plancher);
            Sleep::usleep(300_000);
        }, $plancher);
    });
    $successives = $duree->mesurer(static function () use ($rien, $plancher): void {
        new Timebox()->call($rien, $plancher);
        new Timebox()->call($rien, $plancher);
    });

    expect($emboitees['total'])->toBeGreaterThanOrEqual($plancher)
        ->and($emboitees['total'])->toBeLessThanOrEqual($plancher + $emboitees['reel'] + 1_000)
        ->and($travailLentApres['total'])->toBeGreaterThanOrEqual($plancher + 300_000)
        ->and($travailLentApres['total'])->toBeLessThanOrEqual($plancher + 300_000 + $travailLentApres['reel'] + 1_000)
        ->and($successives['total'])->toBeGreaterThanOrEqual(2 * $plancher)
        ->and($successives['total'])->toBeLessThanOrEqual(2 * $plancher + $successives['reel'] + 1_000);
});

it('passe par une Timebox réglée sur le plancher commun', function (Closure $preparer): void {
    Notification::fake();
    $duree = DureeSimulee::demarrer(2_345_678);
    $durees = new ArrayObject();
    app()->bind(Timebox::class, static fn (): Timebox => new TimeboxEspionnee($durees));
    /** @var Closure(): mixed $requete */
    $requete = $preparer();
    Cache::flush();

    $duree->mesurer($requete);

    expect($durees->getArrayCopy())->not->toBeEmpty()
        ->and(array_unique($durees->getArrayCopy()))->toBe([2_345_678]);
})->with(dureeAuthentificationCas());

/*
 * La demande de changement d'adresse ne lit pas l'adresse demandée : elle
 * enregistre le nom et met `TraiterUnChangementDAdresse` en file, qui la
 * cherche. Rien n'y dure plus longtemps pour une adresse prise, et le
 * plancher retiendrait un processus une seconde par demande, sous un limiteur
 * qui compte par compte et non par adresse IP.
 */
it('répond sans plancher à une demande de changement d’adresse, libre ou prise', function (string $adresse): void {
    Notification::fake();
    $duree = DureeSimulee::demarrer();
    $durees = new ArrayObject();
    app()->bind(Timebox::class, static fn (): Timebox => new TimeboxEspionnee($durees));
    User::factory()->create(['email' => 'prise@example.org']);
    $compte = User::factory()->create();

    $mesure = $duree->mesurer(static fn (): mixed => test()->actingAs($compte)->patch(route('profile.update'), [
        'name' => 'Camille',
        'email' => $adresse,
        'current_password' => 'password',
    ])->assertSessionHasNoErrors()->assertSessionHas('status', 'lien-de-changement-d-adresse-envoye'));

    expect($mesure['dormi'])->toBe(0.0)
        ->and($durees->getArrayCopy())->toBe([]);
})->with(['libre@example.org', 'prise@example.org']);

/*
 * Le travailleur de la file n'a pas de client qui mesure sa durée : le
 * plancher n'y protège rien, et l'endormir une seconde par demande de lien
 * occuperait Horizon pour rien.
 */
it('n’endort pas le travailleur qui envoie le lien de réinitialisation', function (string $adresse): void {
    Notification::fake();
    $duree = DureeSimulee::demarrer();
    $compte = User::factory()->create(['email' => 'connue@example.org']);

    $mesure = $duree->mesurer(static function () use ($adresse): void {
        new EnvoyerLeLienDeReinitialisation($adresse, 'fr')->handle();
    });

    expect($mesure['dormi'])->toBe(0.0)
        ->and(config('auth.timebox_duration'))->toBe($duree->plancher)
        ->and(DureeDesReponsesDAuthentification::microsecondes())->toBe($duree->plancher);

    if ($adresse === 'connue@example.org') {
        Notification::assertSentTo($compte, ResetPassword::class);
    } else {
        Notification::assertNothingSent();
    }
})->with(['connue@example.org', 'inconnue@example.org']);

it('rend la main sans attendre à une connexion réussie', function (): void {
    $duree = DureeSimulee::demarrer();
    User::factory()->create(['email' => 'membre@example.org']);

    $mesure = $duree->mesurer(static fn (): mixed => test()->post(route('login'), [
        'email' => 'membre@example.org',
        'password' => 'password',
    ])->assertRedirect(route('dashboard')));

    expect($mesure['dormi'])->toBe(0.0);
});

/*
 * Le panneau déclare la double authentification (`AppAuthentication`) :
 * Filament fait alors durer sa Timebox à chaque étape d'une connexion,
 * réussie ou non, pour ne pas dire quel compte l'a activée. Réglée sur le
 * plancher commun, une connexion réussie au panneau l'attend donc à chaque
 * étape : mot de passe, puis code s'il y en a un.
 */
it('fait attendre le plancher à chaque étape d’une connexion réussie au panneau', function (bool $doubleAuthentification): void {
    $duree = DureeSimulee::demarrer();
    $admin = FilamentAdminPanel::admin(['ViewAny:User']);
    $application = AppAuthentication::make();

    if ($doubleAuthentification) {
        $application->saveSecret($admin, $application->generateSecret());
    }

    $motDePasse = $duree->mesurer(static fn (): Testable => Livewire::test(ConnexionDuPanneau::class)
        ->fillForm(['email' => $admin->email, 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors());
    $etapes = [$motDePasse];

    if ($doubleAuthentification) {
        expect(auth('admin')->check())->toBeFalse();

        $code = $application->getCurrentCode($admin);
        $etapes[] = $duree->mesurer(static function () use ($motDePasse, $code): void {
            $motDePasse['reponse']->set('data.multiFactor.app.code', $code)->call('authenticate');
        });
        $motDePasse['reponse']->assertHasNoErrors();
    }

    expect(auth('admin')->id())->toBe($admin->id);

    foreach ($etapes as $etape) {
        expect($etape['dormi'])->toBeGreaterThan(0.0)
            ->and($etape['total'])->toBeGreaterThanOrEqual($duree->plancher)
            ->and($etape['total'])->toBeLessThanOrEqual($duree->plancher + $etape['reel'] + 1_000);
    }
})->with(['sans double authentification' => false, 'avec double authentification' => true]);

/*
 * Le bon mot de passe d'un compte en attente, sans le lien suivi, fait
 * repartir le lien : un travail que les autres refus ne font pas. Il se fait
 * dans la Timebox de la garde, qui tient le refus ; après elle, rien ne le
 * couvrirait, celle du contrôleur s'étant écoulée avec elle.
 */
it('renvoie le lien d’un compte en attente dans le plancher : un envoi lent ne rallonge pas le refus', function (): void {
    $duree = DureeSimulee::demarrer();
    User::factory()->enAttente()->create(['email' => 'en-attente@example.org']);
    $envois = 0;
    Event::listen(NotificationSending::class, static function () use (&$envois): void {
        $envois++;
        Sleep::usleep(300_000);
    });
    $connexion = static fn (string $adresse): Closure => static fn (): mixed => test()->post(route('login'), [
        'email' => $adresse,
        'password' => 'password',
    ])->assertSessionHasErrors(['email' => __('auth.failed')]);

    $inconnue = $duree->mesurer($connexion('personne@example.org'));
    $enAttente = $duree->mesurer($connexion('en-attente@example.org'));

    expect($envois)->toBe(1)
        ->and($enAttente['total'])->toBeGreaterThanOrEqual($duree->plancher)
        ->and(abs($enAttente['total'] - $inconnue['total']))->toBeLessThan(100_000);
    assertGuest();
});

/*
 * Le limiteur de la connexion compte les essais ratés sous une adresse depuis
 * une adresse IP, que l'adresse ait un compte ou non : son refus ne dit rien
 * du compte, et le faire dormir occuperait un processus pour rien.
 */
it('répond sans attendre au refus du limiteur de la connexion, compte ou non', function (string $adresse): void {
    $this->freezeSecond();
    $duree = DureeSimulee::demarrer();
    User::factory()->create(['email' => 'membre@example.org']);
    $cle = Str::transliterate($adresse.'|127.0.0.1');

    foreach (range(1, 5) as $_essai) {
        RateLimiter::hit($cle);
    }

    $secondes = RateLimiter::availableIn($cle);

    $mesure = $duree->mesurer(static fn (): mixed => test()->post(route('login'), [
        'email' => $adresse,
        'password' => 'password',
    ])->assertSessionHasErrors(['email' => __('auth.throttle', ['seconds' => $secondes, 'minutes' => ceil($secondes / 60)])]));

    expect($mesure['dormi'])->toBe(0.0);
    assertGuest();
})->with(['membre@example.org', 'personne@example.org']);
