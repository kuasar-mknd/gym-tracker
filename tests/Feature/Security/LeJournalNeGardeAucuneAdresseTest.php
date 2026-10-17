<?php

declare(strict_types=1);

use App\Support\Journaux\JournalSansAdresse;
use App\Support\TexteSansAdresse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\NullHandler;

/*
 * Chaque canal du journal qui écrit sort ses lignes sans adresse de courriel
 * en clair, message, contexte et pile d'une exception compris (#2076). C'est
 * une défense : les erreurs d'envoi n'en citent plus
 * (`CourrielsSansAdresseDansLeursErreursTest`), mais un transport de secours
 * de Symfony écrit lui-même l'erreur de chacun de ses transports, et un
 * paquet peut écrire la sienne.
 */

/**
 * Les canaux de `config/logging.php` qui écrivent : tous, sauf la pile, qui
 * reprend les gestionnaires de ses canaux, et ceux qui n'écrivent rien.
 *
 * @return array<string, array<string, mixed>>
 */
function journalSansAdresseCanauxQuiEcrivent(): array
{
    /** @var array<string, array<string, mixed>> $canaux */
    $canaux = config('logging.channels');

    return array_filter(
        $canaux,
        static fn (array $canal): bool => isset($canal['driver'])
            && $canal['driver'] !== 'stack'
            && ($canal['handler'] ?? null) !== NullHandler::class,
    );
}

/**
 * Écrit, par le canal donné, une ligne dont le message, le contexte et
 * l'exception citent une adresse, et rend ce que le canal a écrit.
 */
function journalSansAdresseEcrirePar(string $canal, string $dossier): string
{
    Log::forgetChannel($canal);
    Log::channel($canal)->error('Envoi refusé pour Titulaire.Journal@Example.org', [
        'destinataire' => 'titulaire.journal@example.org',
        'exception' => new RuntimeException('550 5.1.1 <titulaire.journal@example.org>: Recipient address rejected'),
    ]);
    Log::forgetChannel($canal);

    return implode("\n", array_map(
        static fn (mixed $fichier): string => is_string($fichier) ? (string) file_get_contents($fichier) : '',
        File::glob($dossier.'/*.log'),
    ));
}

it('pose le masque des adresses sur chaque canal qui écrit', function (): void {
    $canaux = journalSansAdresseCanauxQuiEcrivent();

    expect($canaux)->toHaveKeys(['single', 'daily', 'stderr'])
        ->and(array_keys(array_filter(
            $canaux,
            static fn (array $canal): bool => ! in_array(JournalSansAdresse::class, is_array($canal['tap'] ?? null) ? $canal['tap'] : [], true),
        )))->toBe([]);
});

it('écrit sans adresse par les canaux de la production', function (string $canal, Closure $diriger): void {
    $dossier = sys_get_temp_dir().'/journal-sans-adresse-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($dossier);

    try {
        $diriger($dossier);
        $ecrit = journalSansAdresseEcrirePar($canal, $dossier);
    } finally {
        File::deleteDirectory($dossier);
    }

    expect($ecrit)->toContain('Envoi refusé pour '.TexteSansAdresse::MASQUE)
        ->and($ecrit)->toContain('550 5.1.1 <'.TexteSansAdresse::MASQUE.'>: Recipient address rejected')
        ->and(strtolower($ecrit))->not->toContain('titulaire.journal');
})->with([
    'single' => ['single', static function (string $dossier): void {
        Config::set('logging.channels.single.path', $dossier.'/laravel.log');
    }],
    'daily' => ['daily', static function (string $dossier): void {
        Config::set('logging.channels.daily.path', $dossier.'/app.log');
    }],
    'stderr' => ['stderr', static function (string $dossier): void {
        Config::set('logging.channels.stderr.handler_with.stream', $dossier.'/stderr.log');
    }],
    'la pile, de stderr et daily, comme la production' => ['stack', static function (string $dossier): void {
        Config::set('logging.channels.stack.channels', ['stderr', 'daily']);
        Config::set('logging.channels.stderr.handler_with.stream', $dossier.'/stderr.log');
        Config::set('logging.channels.daily.path', $dossier.'/app.log');
        Log::forgetChannel('stderr');
        Log::forgetChannel('daily');
    }],
]);
