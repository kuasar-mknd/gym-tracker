<?php

declare(strict_types=1);

use App\Enums\PersonalRecordType;
use App\Models\Exercise;
use App\Models\NotificationPreference;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Notifications\PersonalRecordAchieved;
use Minishlink\WebPush\SubscriptionInterface;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;

/*
 * À la déconnexion, la page fait oublier au serveur l'adresse push de
 * l'appareil (#1926). Le serveur ne sait pas quel appareil se déconnecte :
 * il reçoit une adresse et la retire. Deux garanties en dépendent.
 *
 * L'oubli doit être définitif : une adresse oubliée ne reçoit plus rien, sans
 * quoi l'écran verrouillé d'un appareil partagé continue d'afficher les
 * records et les rappels du compte parti.
 *
 * Et il ne doit toucher que le compte connecté : l'adresse circule en clair
 * dans le corps de la requête, et n'importe quel compte peut en poster une.
 * Retirer la ligne d'un autre couperait ses notifications sans qu'il le sache.
 *
 * Ces contrôles pilotent le vrai canal, seul le transport étant simulé.
 */

/**
 * Un compte qui reçoit ses records en push, sur l'adresse donnée.
 *
 * @return array{0: User, 1: PersonalRecord}
 */
function compteAbonneAvantOubli(string $endpoint): array
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

    $compte->updatePushSubscription($endpoint, 'p256dh-key', 'auth-token');

    return [$compte, $record];
}

/**
 * Remplace le transport par un témoin qui note chaque adresse à laquelle le
 * canal confie un message, sans rien envoyer.
 *
 * @return ArrayObject<int, string>
 */
function adressesPoussesApresOubli(): ArrayObject
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

it('n’envoie plus rien à une adresse que son compte a fait oublier', function (): void {
    $endpoint = 'https://fcm.googleapis.com/fcm/send/appareil-partage';
    [$compte, $record] = compteAbonneAvantOubli($endpoint);
    $adresses = adressesPoussesApresOubli();

    $this->actingAs($compte)
        ->postJson(route('push-subscriptions.destroy'), ['endpoint' => $endpoint])
        ->assertOk();

    $compte->notify(new PersonalRecordAchieved($record));

    expect($adresses->getArrayCopy())->toBe([]);
    $this->assertDatabaseMissing('push_subscriptions', ['endpoint' => $endpoint]);
});

it('laisse intacte l’adresse d’un autre compte qu’on lui demande d’oublier', function (): void {
    $endpoint = 'https://fcm.googleapis.com/fcm/send/appareil-du-proprietaire';
    [$proprietaire, $record] = compteAbonneAvantOubli($endpoint);
    $intrus = User::factory()->create();
    $adresses = adressesPoussesApresOubli();

    // Le serveur répond comme pour une adresse inconnue : il ne dit pas à
    // l'intrus que l'adresse existe ailleurs.
    $this->actingAs($intrus)
        ->postJson(route('push-subscriptions.destroy'), ['endpoint' => $endpoint])
        ->assertOk();

    $this->assertDatabaseHas('push_subscriptions', [
        'subscribable_type' => User::class,
        'subscribable_id' => $proprietaire->id,
        'endpoint' => $endpoint,
    ]);

    $proprietaire->notify(new PersonalRecordAchieved($record));

    expect($adresses->getArrayCopy())->toBe([$endpoint]);
});

it('n’oublie que l’adresse nommée, pas les autres appareils du compte', function (): void {
    $endpoint = 'https://fcm.googleapis.com/fcm/send/telephone-prete';
    [$compte, $record] = compteAbonneAvantOubli($endpoint);
    $compte->updatePushSubscription('https://fcm.googleapis.com/fcm/send/ordinateur', 'p256dh-key', 'auth-token');
    $adresses = adressesPoussesApresOubli();

    $this->actingAs($compte)
        ->postJson(route('push-subscriptions.destroy'), ['endpoint' => $endpoint])
        ->assertOk();

    $compte->notify(new PersonalRecordAchieved($record));

    expect($adresses->getArrayCopy())->toBe(['https://fcm.googleapis.com/fcm/send/ordinateur']);
});
