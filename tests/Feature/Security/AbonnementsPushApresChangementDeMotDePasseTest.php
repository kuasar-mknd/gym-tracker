<?php

declare(strict_types=1);

use App\Enums\PersonalRecordType;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Exercise;
use App\Models\NotificationPreference;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Notifications\PersonalRecordAchieved;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Appareil;
use Tests\Support\FilamentAdminPanel;

use function Pest\Laravel\actingAs;

/*
 * Un mot de passe changé détache du compte les appareils abonnés aux
 * notifications push.
 *
 * Depuis #1940, changer son mot de passe ferme les autres sessions du compte.
 * Leurs appareils gardaient pourtant leur abonnement, et continuaient
 * d'afficher records, rappels et succès du compte sur l'écran verrouillé :
 * précisément l'appareil volé, partagé ou perdu dont la personne voulait se
 * défaire. Le serveur ne sait pas quel abonnement appartient à quelle
 * session, la table ne porte ni l'une ni l'autre : tous partent, quel que soit
 * le chemin du changement, et la page du profil rend le sien à l'appareil qui
 * a changé le mot de passe, en le transmettant de nouveau.
 *
 * Ce sont de vraies sessions, ouvertes par le formulaire de connexion
 * (`Tests\Support\Appareil`), et de vrais abonnements, transmis comme la page
 * les transmet. Le canal WebPush est le vrai, seul le transport est simulé.
 */

/**
 * Le mot de passe que le compte reçoit, du profil, par le courriel ou du
 * panneau.
 */
function detachementPushNouveauMotDePasse(): string
{
    return 'Un-nouveau-mot-de-passe-2026!';
}

/**
 * Un compte qui reçoit ses records en push, et l'un de ses records.
 *
 * @return array{0: User, 1: PersonalRecord}
 */
function detachementPushCompteAvecUnRecord(): array
{
    $compte = User::factory()->create();

    $record = PersonalRecord::factory()->create([
        'user_id' => $compte->id,
        'exercise_id' => Exercise::factory()->create()->id,
        'type' => PersonalRecordType::MaxWeight,
        'value' => 102.5,
    ])->refresh();

    NotificationPreference::factory()->create([
        'user_id' => $compte->id,
        'type' => 'personal_record',
        'is_enabled' => true,
        'is_push_enabled' => true,
        'value' => null,
    ]);

    return [$compte, $record];
}

/**
 * Remplace le transport par un témoin qui note chaque adresse à laquelle le
 * canal confie un message, sans rien envoyer.
 *
 * @return ArrayObject<int, string>
 */
function detachementPushAdressesPoussees(): ArrayObject
{
    /** @var ArrayObject<int, string> $adresses */
    $adresses = new ArrayObject();

    /** @var WebPush&Mockery\MockInterface $transport */
    $transport = Mockery::mock(WebPush::class);
    $transport->shouldReceive('queueNotification')->andReturnUsing(function (SubscriptionInterface $abonnement) use ($adresses): void {
        $adresses->append($abonnement->getEndpoint());
    });
    $transport->shouldReceive('flush')->andReturnUsing(fn (): Generator => yield from []);

    app()->when(WebPushChannel::class)->needs(WebPush::class)->give(fn (): WebPush => $transport);

    return $adresses;
}

/**
 * La page transmet l'abonnement que le navigateur tient, en JSON, comme
 * `useAbonnementPush` et le service worker l'envoient.
 *
 * @return TestResponse<Response>
 */
function detachementPushTransmettre(Appareil $appareil, string $endpoint): TestResponse
{
    return $appareil->envoyer('POST', '/push-subscriptions', [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => 'p256dh-key', 'auth' => 'auth-token'],
    ], ['Accept' => 'application/json']);
}

/**
 * Un appareil connecté au compte par le formulaire de connexion, puis abonné
 * aux notifications push sous l'adresse donnée.
 */
function detachementPushAppareilAbonne(User $compte, string $endpoint, string $motDePasse = 'password'): Appareil
{
    $appareil = new Appareil();

    $appareil->envoyer('POST', '/login', [
        'email' => $compte->email,
        'password' => $motDePasse,
    ])->assertRedirect(route('dashboard'));

    detachementPushTransmettre($appareil, $endpoint)->assertOk();

    return $appareil;
}

/**
 * Le formulaire du profil, envoyé depuis cet appareil.
 */
function detachementPushChangerLeMotDePasseDepuis(Appareil $appareil): void
{
    $appareil->envoyer('PUT', '/password', [
        'current_password' => 'password',
        'password' => detachementPushNouveauMotDePasse(),
        'password_confirmation' => detachementPushNouveauMotDePasse(),
    ], ['Referer' => url('/profile/edit')])
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile/edit');
}

/**
 * Les adresses que le serveur tient pour ce compte, dans l'ordre d'arrivée.
 *
 * @return list<string>
 */
function detachementPushAdressesDuCompte(User $compte): array
{
    /** @var list<string> $adresses */
    $adresses = $compte->pushSubscriptions()->orderBy('id')->pluck('endpoint')->all();

    return $adresses;
}

it('détache l’autre appareil quand le mot de passe change depuis le profil, et laisse celui qui l’a changé se réabonner', function (): void {
    [$compte, $record] = detachementPushCompteAvecUnRecord();
    $telephone = detachementPushAppareilAbonne($compte, 'https://fcm.googleapis.com/fcm/send/telephone');
    $tablettePerdue = detachementPushAppareilAbonne($compte, 'https://fcm.googleapis.com/fcm/send/tablette-perdue');
    $adresses = detachementPushAdressesPoussees();

    detachementPushChangerLeMotDePasseDepuis($telephone);

    // Le serveur ne sait pas quelle ligne est celle du téléphone : tout part.
    expect(detachementPushAdressesDuCompte($compte))->toBe([]);

    // La page du profil retransmet l'abonnement du téléphone, sous sa session
    // rouverte ; la tablette, dont la session est fermée, ne le peut plus, ni
    // par sa page encore ouverte ni par son service worker.
    detachementPushTransmettre($telephone, 'https://fcm.googleapis.com/fcm/send/telephone')->assertOk();
    detachementPushTransmettre($tablettePerdue, 'https://fcm.googleapis.com/fcm/send/tablette-perdue')->assertUnauthorized();

    expect(detachementPushAdressesDuCompte($compte))->toBe(['https://fcm.googleapis.com/fcm/send/telephone']);

    $compte->notify(new PersonalRecordAchieved($record));

    expect($adresses->getArrayCopy())->toBe(['https://fcm.googleapis.com/fcm/send/telephone']);
});

it('ne laisse aucun abonnement au compte quand le mot de passe est réinitialisé par courriel', function (): void {
    [$compte, $record] = detachementPushCompteAvecUnRecord();
    detachementPushAppareilAbonne($compte, 'https://fcm.googleapis.com/fcm/send/telephone');
    $tablettePerdue = detachementPushAppareilAbonne($compte, 'https://fcm.googleapis.com/fcm/send/tablette-perdue');
    $adresses = detachementPushAdressesPoussees();

    // Depuis un appareil où personne n'est connecté : il n'a rien à garder.
    new Appareil()->envoyer('POST', '/reset-password', [
        'token' => Password::createToken($compte),
        'email' => $compte->email,
        'password' => detachementPushNouveauMotDePasse(),
        'password_confirmation' => detachementPushNouveauMotDePasse(),
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    detachementPushTransmettre($tablettePerdue, 'https://fcm.googleapis.com/fcm/send/tablette-perdue')->assertUnauthorized();

    expect(detachementPushAdressesDuCompte($compte))->toBe([]);

    $compte->notify(new PersonalRecordAchieved($record));

    expect($adresses->getArrayCopy())->toBe([]);
});

/**
 * Le panneau ne sait rien des appareils du compte, et l'administrateur qui
 * donne un nouveau mot de passe n'en est pas un.
 */
it('ne laisse aucun abonnement au compte quand le panneau lui donne un nouveau mot de passe', function (): void {
    Model::preventSilentlyDiscardingAttributes(false);
    [$compte, $record] = detachementPushCompteAvecUnRecord();
    $compte->updatePushSubscription('https://fcm.googleapis.com/fcm/send/telephone', 'p256dh-key', 'auth-token');
    $compte->updatePushSubscription('https://fcm.googleapis.com/fcm/send/tablette-perdue', 'p256dh-key', 'auth-token');
    $adresses = detachementPushAdressesPoussees();

    actingAs(FilamentAdminPanel::admin(FilamentAdminPanel::crudPermissions('User')), 'admin');
    Livewire::test(EditUser::class, ['record' => $compte->getKey()])
        ->fillForm(['password' => detachementPushNouveauMotDePasse()])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(detachementPushAdressesDuCompte($compte))->toBe([]);

    $compte->notify(new PersonalRecordAchieved($record));

    expect($adresses->getArrayCopy())->toBe([]);
});

/**
 * Le retrait suit le mot de passe, pas l'enregistrement du compte : le nom,
 * l'adresse ou le jeton « se souvenir de moi » que chaque déconnexion
 * renouvelle ne coûtent aucun abonnement. Et il ne touche que le compte
 * concerné.
 */
it('ne retire les abonnements que du compte dont le mot de passe change, et seulement quand il change', function (): void {
    $compte = User::factory()->create();
    $compte->updatePushSubscription('https://fcm.googleapis.com/fcm/send/telephone', 'p256dh-key', 'auth-token');
    $compte->updatePushSubscription('https://fcm.googleapis.com/fcm/send/ordinateur', 'p256dh-key', 'auth-token');
    $voisin = User::factory()->create();
    $voisin->updatePushSubscription('https://fcm.googleapis.com/fcm/send/voisin', 'p256dh-key', 'auth-token');

    $compte->update(['name' => 'Un autre nom']);
    $compte->setRememberToken('un-autre-jeton-de-rappel');
    $compte->save();

    expect(detachementPushAdressesDuCompte($compte))->toBe([
        'https://fcm.googleapis.com/fcm/send/telephone',
        'https://fcm.googleapis.com/fcm/send/ordinateur',
    ]);

    $compte->update(['password' => detachementPushNouveauMotDePasse()]);

    expect(detachementPushAdressesDuCompte($compte))->toBe([])
        ->and(detachementPushAdressesDuCompte($voisin))->toBe(['https://fcm.googleapis.com/fcm/send/voisin']);
});

/**
 * Le canal lit les adresses par la relation du compte : une instance qui les
 * avait déjà chargées les aurait encore en mémoire. Une notification mise en
 * file relit le compte, et ses relations, avant de partir ; une notification
 * envoyée sans la file (`notifyNow()`) par cette instance, dans la même
 * requête, partirait vers les appareils retirés.
 */
it('n’envoie rien aux appareils retirés depuis l’instance qui avait chargé leurs adresses', function (): void {
    [$compte, $record] = detachementPushCompteAvecUnRecord();
    $compte->updatePushSubscription('https://fcm.googleapis.com/fcm/send/tablette-perdue', 'p256dh-key', 'auth-token');
    $compte->load('pushSubscriptions');
    $adresses = detachementPushAdressesPoussees();

    $compte->update(['password' => detachementPushNouveauMotDePasse()]);
    $compte->notifyNow(new PersonalRecordAchieved($record));

    expect($adresses->getArrayCopy())->toBe([]);
});
