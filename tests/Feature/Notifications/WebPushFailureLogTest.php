<?php

declare(strict_types=1);

use App\Enums\PersonalRecordType;
use App\Models\Exercise;
use App\Models\NotificationPreference;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Notifications\PersonalRecordAchieved;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\MessageSentReport;
use Minishlink\WebPush\WebPush;
use NotificationChannels\WebPush\WebPushChannel;

/*
 * Un push refusé doit laisser une trace, sinon la panne est invisible.
 *
 * Le canal demande à Apple ou à Google de délivrer, lit leur rapport et
 * s'arrête là : il émet `NotificationFailed`, et rien ne l'écoutait. Un appareil
 * pouvait donc cesser de recevoir pendant des semaines — abonnement révoqué,
 * clef VAPID changée, point de terminaison périmé — sans qu'aucun journal,
 * aucune trace et aucune alerte ne le disent. Ce n'était pas une panne difficile
 * à diagnostiquer, c'était une panne qu'aucune observation ne pouvait atteindre.
 *
 * Ces contrôles pilotent le VRAI canal, seul le transport HTTP étant simulé.
 */

/**
 * Un compte prêt à recevoir un push, avec l'abonnement voulu.
 *
 * @return array{0: User, 1: PersonalRecord}
 */
function comptePushAbonneA(string $endpoint): array
{
    $user = User::factory()->create();

    $record = PersonalRecord::factory()->create([
        'user_id' => $user->id,
        'exercise_id' => Exercise::factory()->create(['name' => 'Développé Couché'])->id,
        'type' => PersonalRecordType::MaxWeight,
        'value' => 102.5,
    ])->refresh();

    NotificationPreference::factory()->create([
        'user_id' => $user->id,
        'type' => 'personal_record',
        'is_enabled' => true,
        'is_push_enabled' => true,
        'value' => null,
    ]);

    $user->updatePushSubscription($endpoint, 'p256dh-key', 'auth-token');

    return [$user, $record];
}

/**
 * Branche un transport qui rend le rapport voulu au lieu d'appeler le réseau.
 */
function transportPushQuiRepond(MessageSentReport $rapport): void
{
    /** @var WebPush&Mockery\MockInterface $transport */
    $transport = Mockery::mock(WebPush::class);
    $transport->shouldReceive('queueNotification')->andReturnNull();
    $transport->shouldReceive('flush')->andReturnUsing(function () use ($rapport): Generator {
        yield $rapport;
    });

    app()->when(WebPushChannel::class)->needs(WebPush::class)->give(fn (): WebPush => $transport);
}

it('journalise un envoi refusé, avec de quoi le diagnostiquer', function (): void {
    $endpoint = 'https://web.push.apple.com/abcdef-le-secret-de-l-appareil';
    [$user, $record] = comptePushAbonneA($endpoint);

    transportPushQuiRepond(new MessageSentReport(
        new Request('POST', $endpoint),
        new Response(410),
        false,
        'Gone',
    ));

    $journal = Log::spy();

    $user->notify(new PersonalRecordAchieved($record));

    $journal->shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $contexte): bool => $message === 'Envoi push refusé.'
            && $contexte['statut'] === 410
            && $contexte['expire'] === true
            && $contexte['raison'] === 'Gone'
            && $contexte['titre'] === 'Nouveau Record ! 🏆');
});

it('ne recopie pas le point de terminaison, qui est une capacité', function (): void {
    $endpoint = 'https://web.push.apple.com/abcdef-le-secret-de-l-appareil';
    [$user, $record] = comptePushAbonneA($endpoint);

    transportPushQuiRepond(new MessageSentReport(
        new Request('POST', $endpoint),
        new Response(400),
        false,
        'Bad Request',
    ));

    $journal = Log::spy();

    $user->notify(new PersonalRecordAchieved($record));

    // Qui détient l'URL entière peut écrire à l'appareil : le journal n'en
    // retient que l'hôte, ce qui suffit à savoir à quel service on parlait.
    $journal->shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $contexte): bool => $contexte['hote'] === 'web.push.apple.com'
            && ! str_contains(json_encode($contexte, JSON_THROW_ON_ERROR), 'le-secret-de-l-appareil'));
});

it('ne dit rien quand la remise réussit', function (): void {
    $endpoint = 'https://web.push.apple.com/abcdef-le-secret-de-l-appareil';
    [$user, $record] = comptePushAbonneA($endpoint);

    transportPushQuiRepond(new MessageSentReport(new Request('POST', $endpoint), new Response(201)));

    $journal = Log::spy();

    $user->notify(new PersonalRecordAchieved($record));

    $journal->shouldNotHaveReceived('warning');
});
