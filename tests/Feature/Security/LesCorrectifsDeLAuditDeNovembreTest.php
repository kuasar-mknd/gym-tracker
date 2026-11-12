<?php

declare(strict_types=1);

use App\Notifications\AdresseDuCompteChangee;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;

it('chiffre en file l’avis de changement d’adresse, qui porte deux adresses', function (): void {
    expect(new AdresseDuCompteChangee('nouvelle@example.org'))->toBeInstanceOf(ShouldBeEncrypted::class);
});

it('ne sert aucun fichier du disque local par une URL signée', function (): void {
    expect(config('filesystems.disks.local.serve'))->toBeFalse();
});

it('masque les adresses dans le canal de dernier recours comme dans les autres', function (): void {
    expect(config('logging.channels.emergency.tap'))->toBe([App\Support\Journaux\JournalSansAdresse::class]);
});

it('ne garde ni l’adresse ni les clefs d’un abonnement Web Push dans la table des exceptions', function (): void {
    $clefs = ['AAAAauthSecretDeLAppareil', 'BBBBp256dhDeLAppareil'];

    $exception = App\Models\ExceptionEnregistree::query()->create([
        'type' => RuntimeException::class,
        'code' => '0',
        'message' => 'Base indisponible',
        'file' => '/app/x.php',
        'line' => 1,
        'trace' => [],
        'method' => 'POST',
        'path' => 'push-subscriptions',
        'body' => ['endpoint' => 'https://push.example.org/abonnement-secret', 'keys' => ['auth' => $clefs[0], 'p256dh' => $clefs[1]]],
    ])->fresh();

    $ecrit = json_encode($exception->getAttributes());

    expect($ecrit)->not()->toContain($clefs[0]);
    expect($ecrit)->not()->toContain($clefs[1]);
    expect($ecrit)->not()->toContain('abonnement-secret');
});
