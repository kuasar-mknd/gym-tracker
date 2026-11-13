<?php

declare(strict_types=1);

use App\Actions\Diagnostic\CreationDeJetonRefusee;
use App\Actions\Diagnostic\CreeUnJetonDeDiagnosticAction;
use App\Actions\Diagnostic\RevoqueLesJetonsDeDiagnosticAction;
use App\Enums\EvenementDAlerteDeDiagnostic;
use App\Enums\PorteeDeDiagnostic;
use App\Enums\RaisonDeRevocationDuJeton;
use App\Models\Admin;
use App\Models\JetonDeDiagnostic;
use App\Notifications\AlerteDeDiagnostic;
use App\Support\AlertesDeDiagnostic;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\QueueManager;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Testing\Fakes\NotificationFake;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Role;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\Support\FilamentAdminPanel;

/*
 * Les alertes de l'accès de diagnostic (#2032) : ce qui part, vers qui, avec
 * quoi, et la limite de chaque déclencheur dans le temps. L'horloge est
 * arrêtée, puis avancée : la limite se juge sur elle, et ses durées sont
 * écrites en dur ici (900 s, 3 600 s), jamais lues dans le code qu'elles jugent.
 */
beforeEach(function (): void {
    Carbon::setTestNow('2026-11-12 10:00:00');
    config()->set('app.diagnostic_enabled', true);
    config()->set('health.notifications.mail.to', 'alertes@example.org');
    Cache::flush();
    Notification::fake();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic $evenement): int
{
    /** @var array<string, array<string, array<class-string, list<array{notification: AlerteDeDiagnostic}>>>> $envoyees */
    $envoyees = Notification::sentNotifications();
    $alertes = collect($envoyees[AnonymousNotifiable::class] ?? [])
        ->flatMap(fn (array $parNotification): array => $parNotification[AlerteDeDiagnostic::class] ?? []);

    return $alertes->filter(fn (array $envoi): bool => $envoi['notification']->evenement === $evenement)->count();
}

/**
 * Un jeton créé par l'action, par un super administrateur doté de la double authentification.
 *
 * @return array{jeton: JetonDeDiagnostic, en_clair: string}
 */
function alertesDeDiagnosticCreerUnJeton(string $nom = 'Agent'): array
{
    $admin = FilamentAdminPanel::admin(['view-diagnostic']);
    $admin->assignRole(Role::findOrCreate(Admin::roleDuSuperAdministrateur(), 'admin'));
    $secret = new Google2FA()->generateSecretKey();
    $admin->forceFill(['app_authentication_secret' => $secret])->save();

    return resolve(CreeUnJetonDeDiagnosticAction::class)->execute(
        $admin->refresh(),
        $nom,
        [PorteeDeDiagnostic::Sante, PorteeDeDiagnostic::Exceptions],
        7,
        new Google2FA()->getCurrentOtp($secret),
    );
}

it('alerte à la création, sans le nom du jeton ni son identifiant entier', function (): void {
    $cree = alertesDeDiagnosticCreerUnJeton('Nom <b>venu de dehors</b>');

    Notification::assertSentOnDemand(AlerteDeDiagnostic::class, function (AlerteDeDiagnostic $alerte, array $canaux, AnonymousNotifiable $destinataire) use ($cree): bool {
        $courriel = $alerte->toMail($destinataire);
        $texte = $courriel->subject.' '.implode(' ', array_map(static fn (mixed $ligne): string => is_string($ligne) ? $ligne : '', $courriel->introLines));

        expect($destinataire->routes['mail'])->toBe(['alertes@example.org'])
            ->and($alerte->evenement)->toBe(EvenementDAlerteDeDiagnostic::Creation)
            ->and($alerte->jetons)->toBe([substr($cree['jeton']->identifiant, -4)])
            ->and($alerte->portees)->toBe(['sante', 'exceptions'])
            ->and($texte)->toContain('…'.substr($cree['jeton']->identifiant, -4), 'sante, exceptions', '19/11/2026 à 10:00')
            ->and($texte)->not->toContain('venu de dehors')
            ->and($texte)->not->toContain($cree['jeton']->identifiant)
            ->and($texte)->not->toContain($cree['en_clair']);

        return true;
    });
});

it('n’alerte pas d’une création refusée', function (): void {
    $admin = FilamentAdminPanel::admin(['view-diagnostic']);

    expect(fn () => resolve(CreeUnJetonDeDiagnosticAction::class)->execute($admin, 'Agent', [PorteeDeDiagnostic::Sante], 7, '000000'))
        ->toThrow(CreationDeJetonRefusee::class);

    Notification::assertNothingSent();
});

it('alerte d’une révocation par un seul courriel pour tous les jetons révoqués, avec sa raison', function (): void {
    $premier = JetonDeDiagnostic::factory()->create();
    $second = JetonDeDiagnostic::factory()->create();
    JetonDeDiagnostic::factory()->revoque()->create();

    resolve(RevoqueLesJetonsDeDiagnosticAction::class)->execute(RaisonDeRevocationDuJeton::Interrupteur);

    Notification::assertSentOnDemandTimes(AlerteDeDiagnostic::class, 1);
    Notification::assertSentOnDemand(AlerteDeDiagnostic::class, function (AlerteDeDiagnostic $alerte) use ($premier, $second): bool {
        expect($alerte->evenement)->toBe(EvenementDAlerteDeDiagnostic::Revocation)
            ->and($alerte->jetons)->toEqualCanonicalizing([substr($premier->identifiant, -4), substr($second->identifiant, -4)])
            ->and($alerte->raison)->toBe('interrupteur');

        return true;
    });
});

it('n’alerte pas d’une révocation qui ne révoque rien', function (): void {
    resolve(RevoqueLesJetonsDeDiagnosticAction::class)->execute(RaisonDeRevocationDuJeton::Manuelle);

    Notification::assertNothingSent();
});

it('alerte aussi d’une révocation automatique, par le mot de passe de l’administrateur', function (): void {
    $jeton = JetonDeDiagnostic::factory()->create();

    $jeton->createur()->firstOrFail()->forceFill(['password' => 'un-autre-mot-de-passe'])->save();

    expect(alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::Revocation))->toBe(1);
    Notification::assertSentOnDemand(AlerteDeDiagnostic::class, fn (AlerteDeDiagnostic $alerte): bool => $alerte->raison === 'mot-de-passe-change');
});

it('ne part nulle part, sans rien planter, quand HEALTH_TO_ADDRESS est vide', function (): void {
    config()->set('health.notifications.mail.to', '');
    $jeton = JetonDeDiagnostic::factory()->create();
    $alertes = resolve(AlertesDeDiagnostic::class);

    resolve(RevoqueLesJetonsDeDiagnosticAction::class)->execute(RaisonDeRevocationDuJeton::Manuelle);
    $alertes->jetonCree($jeton);
    $alertes->premierUsage($jeton);
    $alertes->jetonInvalidePresente($jeton, now());
    $alertes->debitDepasse($jeton);

    Notification::assertNothingSent();
    expect(AlertesDeDiagnostic::adresses())->toBe([]);
});

it('lit une liste d’adresses et ignore les vides', function (): void {
    config()->set('health.notifications.mail.to', [' a@example.org ', '', 'b@example.org']);

    expect(AlertesDeDiagnostic::adresses())->toBe(['a@example.org', 'b@example.org']);
});

it('ne retient que des adresses seules et bien formées', function (string|array $configuree, array $attendues): void {
    config()->set('health.notifications.mail.to', $configuree);

    expect(AlertesDeDiagnostic::adresses())->toBe($attendues);
})->with([
    'une adresse' => ['alertes@example.org', ['alertes@example.org']],
    'deux adresses en une chaîne' => ['a@example.org, b@example.org', []],
    'un texte quelconque' => ['pas-une-adresse', []],
    'un texte et une adresse en liste' => [['pas-une-adresse', 'b@example.org'], ['b@example.org']],
    'vide' => ['', []],
]);

it('refuse la création quand HEALTH_TO_ADDRESS ne porte aucune adresse valide', function (string $configuree): void {
    config()->set('health.notifications.mail.to', $configuree);

    try {
        alertesDeDiagnosticCreerUnJeton();
        $motif = null;
    } catch (CreationDeJetonRefusee $refus) {
        $motif = $refus->motif;
    }

    expect($motif)->toBe(CreationDeJetonRefusee::ADRESSE_DES_ALERTES_VIDE);
})->with(['pas-une-adresse', 'a@example.org, b@example.org', '']);

/**
 * Remplace l'envoi des notifications par un faux qui appelle d'abord `$auEnvoi`.
 */
function alertesDeDiagnosticObserverLEnvoi(Closure $auEnvoi): void
{
    Notification::swap(new class($auEnvoi) extends NotificationFake
    {
        public function __construct(private readonly Closure $auEnvoi)
        {
        }

        #[Override]
        public function send($notifiables, $notification): void
        {
            ($this->auEnvoi)();

            parent::send($notifiables, $notification);
        }
    });
}

it('ne défait pas ce qu’il annonce quand l’envoi échoue', function (): void {
    Exceptions::fake();
    alertesDeDiagnosticObserverLEnvoi(static fn (): never => throw new RuntimeException('file injoignable'));
    $jeton = JetonDeDiagnostic::factory()->create();

    resolve(AlertesDeDiagnostic::class)->jetonCree($jeton);
    $revoques = resolve(RevoqueLesJetonsDeDiagnosticAction::class)->execute(RaisonDeRevocationDuJeton::Manuelle);

    expect($revoques)->toHaveCount(1)
        ->and($jeton->refresh()->revoque_le)->not->toBeNull();
    Exceptions::assertReported(RuntimeException::class);
});

it('ne lève rien quand la file est en panne dans une transaction englobante', function (): void {
    Exceptions::fake();
    Facade::clearResolvedInstance(ChannelManager::class);
    app()->forgetInstance(ChannelManager::class);
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.table', 'table_de_file_absente');
    $jeton = JetonDeDiagnostic::factory()->create();

    $revoques = DB::transaction(fn (): array => resolve(RevoqueLesJetonsDeDiagnosticAction::class)->execute(RaisonDeRevocationDuJeton::Manuelle));

    expect($revoques)->toHaveCount(1)
        ->and($jeton->refresh()->revoque_le)->not->toBeNull();
    Exceptions::assertReported(QueryException::class);
});

it('envoie la création après le verrou et la transaction de la création', function (): void {
    $niveau = DB::transactionLevel();
    $observe = [];
    alertesDeDiagnosticObserverLEnvoi(function () use (&$observe): void {
        $verrou = Cache::lock('diagnostic:creation', 5);
        $observe = ['niveau' => DB::transactionLevel(), 'verrou_libre' => $verrou->get()];
        $verrou->release();
    });

    alertesDeDiagnosticCreerUnJeton();

    expect($observe)->toBe(['niveau' => $niveau, 'verrou_libre' => true]);
});

it('ne fait jamais échouer la requête valide quand le cache tombe', function (string $methode): void {
    Exceptions::fake();
    $jeton = JetonDeDiagnostic::factory()->revoque()->create();
    $injoignable = Mockery::mock(Repository::class);
    $injoignable->shouldReceive('add', 'increment', 'put', 'forget')->andThrow(new RuntimeException('cache injoignable'));
    config()->set('cache.limiter', 'durable-essai');
    Cache::shouldReceive('store')->with('durable-essai')->andReturn($injoignable);

    $methode === 'jetonInvalidePresente' ? resolve(AlertesDeDiagnostic::class)->jetonInvalidePresente($jeton, now()) : resolve(AlertesDeDiagnostic::class)->{$methode}($jeton);

    Exceptions::assertReported(RuntimeException::class);
})->with(['premierUsage', 'jetonInvalidePresente', 'debitDepasse']);

it('envoie quand la trace de la présentation ne s’écrit pas', function (): void {
    Exceptions::fake();
    $jeton = JetonDeDiagnostic::factory()->revoque()->create();
    Cache::extend('sans-trace', fn (): Repository => new Repository(new class() extends ArrayStore
    {
        #[Override]
        public function put($key, $value, $seconds)
        {
            if (str_contains((string) $key, 'presentation-d-un-jeton-mort')) {
                throw new RuntimeException('trace refusée');
            }

            return parent::put($key, $value, $seconds);
        }
    }));
    config()->set('cache.stores.sans-trace', ['driver' => 'sans-trace']);
    config()->set('cache.default', 'sans-trace');

    resolve(AlertesDeDiagnostic::class)->jetonInvalidePresente($jeton, now());

    Exceptions::assertReported(RuntimeException::class);
    expect(alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::JetonInvalidePresente))->toBe(1);
});

it('alerte du premier usage une seule fois par jeton, quel que soit le temps passé', function (): void {
    $jeton = JetonDeDiagnostic::factory()->create();
    $autre = JetonDeDiagnostic::factory()->create();
    $alertes = resolve(AlertesDeDiagnostic::class);

    $alertes->premierUsage($jeton);
    Carbon::setTestNow(now()->addDays(3));
    $alertes->premierUsage($jeton);
    $alertes->premierUsage($autre);

    expect(alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::PremierUsage))->toBe(2);
});

it('rend le créneau du premier usage quand rien n’a pu partir, et réessaie au prochain appel', function (): void {
    $jeton = JetonDeDiagnostic::factory()->create();
    $alertes = resolve(AlertesDeDiagnostic::class);

    config()->set('health.notifications.mail.to', '');
    $alertes->premierUsage($jeton);
    expect(alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::PremierUsage))->toBe(0);

    config()->set('health.notifications.mail.to', 'alertes@example.org');
    $alertes->premierUsage($jeton);
    $alertes->premierUsage($jeton);

    expect(alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::PremierUsage))->toBe(1);
});

it('rend le créneau du débit quand l’envoi échoue, et réessaie au prochain appel', function (): void {
    Exceptions::fake();
    $jeton = JetonDeDiagnostic::factory()->create();
    $alertes = resolve(AlertesDeDiagnostic::class);

    alertesDeDiagnosticObserverLEnvoi(static fn (): never => throw new RuntimeException('file injoignable'));
    $alertes->debitDepasse($jeton);

    Notification::swap(new NotificationFake());
    $alertes->debitDepasse($jeton);
    $alertes->debitDepasse($jeton);

    expect(alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::DebitDepasse))->toBe(1);
});

it('pose le créneau du jour et la trace d’un jeton mort d’après l’instant de la garde, même si minuit est passé depuis', function (): void {
    $jeton = JetonDeDiagnostic::factory()->expire()->create();
    $instantDeLaGarde = Carbon::parse('2026-11-12 23:59:59');
    Carbon::setTestNow($instantDeLaGarde);
    Cache::put('diagnostic:alerte:presentations:'.$jeton->identifiant, AlertesDeDiagnostic::PRESENTATIONS_AVANT_LA_CADENCE_JOURNALIERE, 3600);
    Carbon::setTestNow('2026-11-13 00:00:01');

    resolve(AlertesDeDiagnostic::class)->jetonInvalidePresente($jeton, $instantDeLaGarde);

    expect(Cache::has('diagnostic:alerte:jeton-mort:jour:'.$jeton->identifiant.':2026-11-12'))->toBeTrue()
        ->and(Cache::has('diagnostic:alerte:jeton-mort:jour:'.$jeton->identifiant.':2026-11-13'))->toBeFalse()
        ->and(Cache::get(AlertesDeDiagnostic::CLE_DE_LA_DERNIERE_PRESENTATION))->toBe($instantDeLaGarde->toIso8601String());
});

it('ne pose la marque de la minute, sur un cache `database`, que si l’envoi a abouti', function (): void {
    config()->set('cache.default', 'database');
    config()->set('health.notifications.mail.to', '');
    $jeton = JetonDeDiagnostic::factory()->expire()->create();
    $alertes = resolve(AlertesDeDiagnostic::class);

    $alertes->jetonInvalidePresente($jeton, now());
    config()->set('health.notifications.mail.to', 'alertes@example.org');
    $alertes->jetonInvalidePresente($jeton, now());

    expect(alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::JetonInvalidePresente))->toBe(1);
});

it('alerte d’un jeton mort présenté une fois par quinze minutes', function (): void {
    $jeton = JetonDeDiagnostic::factory()->expire()->create();
    $autre = JetonDeDiagnostic::factory()->revoque()->create();
    $alertes = resolve(AlertesDeDiagnostic::class);
    $envoyees = fn (): int => alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::JetonInvalidePresente);

    $alertes->jetonInvalidePresente($jeton, now());
    $alertes->jetonInvalidePresente($jeton, now());
    $alertes->jetonInvalidePresente($autre, now());

    expect($envoyees())->toBe(2);

    Carbon::setTestNow(now()->addSeconds(899));
    $alertes->jetonInvalidePresente($jeton, now());
    expect($envoyees())->toBe(2);

    Carbon::setTestNow(now()->addSecond());
    $alertes->jetonInvalidePresente($jeton, now());
    expect($envoyees())->toBe(3);
});

it('passe à un courriel par jour civil dès la onzième présentation, sans jamais dépasser un par quinze minutes', function (): void {
    $jeton = JetonDeDiagnostic::factory()->revoque()->create();
    $alertes = resolve(AlertesDeDiagnostic::class);
    $envoyees = fn (): int => alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::JetonInvalidePresente);

    foreach (range(1, 10) as $presentation) {
        $alertes->jetonInvalidePresente($jeton, now());
        expect($envoyees())->toBe($presentation);

        if ($presentation < 10) {
            Carbon::setTestNow(now()->addSeconds(900));
        }
    }

    $alertes->jetonInvalidePresente($jeton, now());
    expect($envoyees())->toBe(10);

    Carbon::setTestNow(now()->addSeconds(900));
    $alertes->jetonInvalidePresente($jeton, now());
    expect($envoyees())->toBe(11);

    Carbon::setTestNow(now()->addSeconds(900));
    $alertes->jetonInvalidePresente($jeton, now());
    $alertes->jetonInvalidePresente($jeton, now());
    expect($envoyees())->toBe(11);

    Carbon::setTestNow('2026-11-13 00:00:00');
    $alertes->jetonInvalidePresente($jeton, now());
    expect($envoyees())->toBe(12);

    Carbon::setTestNow(now()->addSeconds(900));
    $alertes->jetonInvalidePresente($jeton, now());
    expect($envoyees())->toBe(12);
});

it('pose la trace que lit le contrôle de santé à chaque présentation, même quand le courriel ne part pas', function (): void {
    $jeton = JetonDeDiagnostic::factory()->revoque()->create();
    $alertes = resolve(AlertesDeDiagnostic::class);

    $alertes->jetonInvalidePresente($jeton, now());
    Carbon::setTestNow(now()->addMinutes(5));
    $alertes->jetonInvalidePresente($jeton, now());

    expect(alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::JetonInvalidePresente))->toBe(1)
        ->and(Cache::get(AlertesDeDiagnostic::CLE_DE_LA_DERNIERE_PRESENTATION))->toBe(now()->toIso8601String());
});

it('alerte d’un débit dépassé une fois par heure et par jeton', function (): void {
    $jeton = JetonDeDiagnostic::factory()->create();
    $autre = JetonDeDiagnostic::factory()->create();
    $alertes = resolve(AlertesDeDiagnostic::class);

    $alertes->debitDepasse($jeton);
    $alertes->debitDepasse($jeton);
    $alertes->debitDepasse($autre);
    expect(alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::DebitDepasse))->toBe(2);

    Carbon::setTestNow(now()->addSeconds(3599));
    $alertes->debitDepasse($jeton);
    expect(alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::DebitDepasse))->toBe(2);

    Carbon::setTestNow(now()->addSecond());
    $alertes->debitDepasse($jeton);
    expect(alertesDeDiagnosticLesEnvoyees(EvenementDAlerteDeDiagnostic::DebitDepasse))->toBe(3);
});

it('ne porte dans la file que des scalaires et une énumération fermée', function (): void {
    $jeton = JetonDeDiagnostic::factory()->create(['nom' => 'Nom venu de dehors']);

    resolve(AlertesDeDiagnostic::class)->jetonCree($jeton);

    Notification::assertSentOnDemand(AlerteDeDiagnostic::class, function (AlerteDeDiagnostic $alerte): bool {
        $serialisee = serialize($alerte);

        expect($serialisee)->not->toContain('venu de dehors')
            ->and($serialisee)->not->toContain(JetonDeDiagnostic::class);

        return true;
    });
});

it('porte cinq essais et un délai croissant, que Horizon ne lui donnerait pas', function (): void {
    $alerte = new AlerteDeDiagnostic(EvenementDAlerteDeDiagnostic::Creation, ['abcd']);

    $travail = new SendQueuedNotifications(collect([new AnonymousNotifiable()]), $alerte);

    expect($travail->tries)->toBe(5)
        ->and($travail->backoff())->toBe([60, 300, 900]);
});

it('rejoue un envoi dont le courrier tombe en panne passagère, au lieu de le perdre avec les réglages de Horizon', function (): void {
    Facade::clearResolvedInstance(ChannelManager::class);
    app()->forgetInstance(ChannelManager::class);
    config()->set('queue.default', 'database');
    config()->set('mail.default', 'en-panne');
    config()->set('mail.mailers.en-panne', ['transport' => 'en-panne']);
    Mail::extend('en-panne', fn (): AbstractTransport => new class() extends AbstractTransport
    {
        #[Override]
        protected function doSend(SentMessage $message): void
        {
            throw new RuntimeException('SMTP injoignable');
        }

        #[Override]
        public function __toString(): string
        {
            return 'en-panne';
        }
    });
    Notification::route('mail', 'alertes@example.org')->notify(new AlerteDeDiagnostic(EvenementDAlerteDeDiagnostic::Creation, ['abcd']));

    $file = app('queue');
    assert($file instanceof QueueManager);
    $worker = new Worker($file, app('events'), app(ExceptionHandler::class), static fn (): bool => false);
    $worker->setCache(app('cache.store'));
    $travail = $file->connection('database')->pop('default');
    assert($travail !== null);

    try {
        $worker->process('database', $travail, new WorkerOptions(sleep: 0, maxTries: config()->integer('horizon.defaults.supervisor-1.tries')));
    } catch (RuntimeException) {
    }

    expect($travail->hasFailed())->toBeFalse()
        ->and($travail->isReleased())->toBeTrue()
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(DB::table('jobs')->pluck('available_at')->all())->toEqual([now()->addSeconds(60)->getTimestamp()]);
});

it('dit « jetons » et « viennent d’être révoqués » quand plusieurs jetons sont révoqués, avec la raison en clair', function (): void {
    $alerte = new AlerteDeDiagnostic(EvenementDAlerteDeDiagnostic::Revocation, ['abcd', 'efgh'], raison: RaisonDeRevocationDuJeton::MotDePasseChange->value);
    $lignes = $alerte->toMail(new AnonymousNotifiable())->introLines;

    expect($lignes[0])->toBe('Les jetons …abcd, …efgh viennent d’être révoqués (raison : mot de passe changé).')
        ->and($alerte->toMail(new AnonymousNotifiable())->subject)->toEndWith('jetons révoqués');
});

it('garde le singulier pour un seul jeton révoqué, et chaque raison a son libellé', function (RaisonDeRevocationDuJeton $raison, string $libelle): void {
    $lignes = new AlerteDeDiagnostic(EvenementDAlerteDeDiagnostic::Revocation, ['abcd'], raison: $raison->value)
        ->toMail(new AnonymousNotifiable())->introLines;

    expect($lignes[0])->toBe("Le jeton …abcd vient d’être révoqué (raison : {$libelle}).");
})->with([
    [RaisonDeRevocationDuJeton::Manuelle, 'révocation manuelle'],
    [RaisonDeRevocationDuJeton::Interrupteur, 'accès de diagnostic fermé'],
    [RaisonDeRevocationDuJeton::MotDePasseChange, 'mot de passe changé'],
    [RaisonDeRevocationDuJeton::DoubleAuthentificationRetiree, 'double authentification retirée'],
    [RaisonDeRevocationDuJeton::AdministrateurSupprime, 'administrateur supprimé'],
]);
