<?php

declare(strict_types=1);

use App\Notifications\AdresseDuCompteChangee;
use App\Support\TexteSansAdresse;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

it('chiffre en file l’avis de changement d’adresse, qui porte deux adresses', function (): void {
    expect(new AdresseDuCompteChangee('nouvelle@example.org'))->toBeInstanceOf(ShouldBeEncrypted::class);
});

it('ne sert aucun fichier du disque local par une URL signée', function (): void {
    expect(config('filesystems.disks.local.serve'))->toBeFalse();
});

it('masque les adresses dans le canal de dernier recours, celui que Laravel construit quand un canal ne se bâtit pas', function (): void {
    $fichier = sys_get_temp_dir().'/dernier-recours-'.bin2hex(random_bytes(6)).'.log';
    Config::set('logging.channels.emergency.path', $fichier);

    try {
        Log::channel('canal-qui-nexiste-pas')->error('Envoi refusé pour Titulaire.Recours@Example.org', [
            'destinataire' => 'titulaire.recours@example.org',
        ]);
        $ecrit = (string) file_get_contents($fichier);
    } finally {
        File::delete($fichier);
    }

    expect($ecrit)->toContain('Envoi refusé pour '.TexteSansAdresse::MASQUE)
        ->and(strtolower($ecrit))->not->toContain('titulaire.recours');
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
