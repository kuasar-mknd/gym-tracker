<?php

declare(strict_types=1);

use App\Support\Journaux\FormateurSansAdresse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Level;
use Monolog\LogRecord;

/*
 * Ni le jeton de diagnostic ni la valeur d'un en-tête `Bearer …` ne
 * s'écrivent dans le journal (#2032). Le message d'une exception, que la
 * table masque (`JetonsHorsDesExceptionsTest`), part aussi dans chaque canal
 * qui écrit, par le gestionnaire des exceptions, et une ligne écrite par
 * l'application ou un paquet peut citer l'un ou l'autre. Le journal se lit
 * quatorze jours dans le panneau, et sa copie sur stderr va dans les
 * journaux des conteneurs. Chaque canal qui écrit passe donc sa ligne,
 * message, contexte et pile compris, par le même masque que la table
 * (`FormateurSansAdresse`), comme pour les adresses
 * (`LeJournalNeGardeAucuneAdresseTest`).
 *
 * La pile est réglée comme dans l'image, d'après `docker/php-prod.ini`. Les
 * jetons se bâtissent par concaténation : aucun n'apparaît en clair dans le
 * dépôt (`AucunJetonDeDiagnosticNEstSuiviTest`).
 */

beforeEach(function (): void {
    $production = parse_ini_file(base_path('docker/php-prod.ini'));
    $ignorerLesArguments = is_array($production) ? ($production['zend.exception_ignore_args'] ?? null) : null;

    expect($ignorerLesArguments)->toBeString('docker/php-prod.ini ne règle pas zend.exception_ignore_args.');

    ini_set('zend.exception_ignore_args', is_string($ignorerLesArguments) ? $ignorerLesArguments : '0');
});

afterEach(function (): void {
    ini_restore('zend.exception_ignore_args');
});

/**
 * L'identifiant du jeton semé, ses douze caractères `[a-z0-9]`.
 */
function jetonsHorsDuJournalIdentifiant(): string
{
    return str_repeat('p5', 6);
}

/**
 * Le secret du jeton semé, ses quarante-huit caractères `[A-Za-z0-9]`.
 */
function jetonsHorsDuJournalSecret(): string
{
    return str_repeat('Hk2', 16);
}

/**
 * Le jeton de diagnostic semé, au format `gtdiag_<identifiant>_<secret>`.
 */
function jetonsHorsDuJournalJeton(): string
{
    return 'gtdiag_'.jetonsHorsDuJournalIdentifiant().'_'.jetonsHorsDuJournalSecret();
}

/**
 * La valeur d'un en-tête `Bearer` d'un autre porteur, qui n'a pas la forme du
 * jeton de diagnostic.
 */
function jetonsHorsDuJournalPorteur(): string
{
    return 'Bearer Porteur.'.str_repeat('N4c', 8).'-fin';
}

/**
 * Ce texte laisse-t-il lire un morceau du jeton semé ou du secret du
 * porteur ?
 */
function jetonsHorsDuJournalLaissentLire(string $texte): bool
{
    return array_any(
        [jetonsHorsDuJournalIdentifiant(), substr(jetonsHorsDuJournalSecret(), 0, 9), str_repeat('N4c', 3)],
        static fn (string $morceau): bool => str_contains($texte, $morceau),
    );
}

/**
 * Ce que les canaux ont écrit dans le dossier donné.
 */
function jetonsHorsDuJournalLire(string $dossier): string
{
    return implode("\n", array_map(
        static fn (mixed $fichier): string => is_string($fichier) ? (string) file_get_contents($fichier) : '',
        File::glob($dossier.'/*.log'),
    ));
}

it('écrit sans jeton ni valeur Bearer par les canaux de la production', function (string $canal, Closure $diriger): void {
    $dossier = sys_get_temp_dir().'/jetons-hors-du-journal-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($dossier);
    $jeton = jetonsHorsDuJournalJeton();
    $porteur = jetonsHorsDuJournalPorteur();

    try {
        $diriger($dossier);
        Log::forgetChannel($canal);
        Log::channel($canal)->error("Jeton refusé : {$jeton}", [
            'entete' => $porteur,
            'exception' => new RuntimeException("Refus du jeton {$jeton}, en-tête Authorization: {$porteur}"),
        ]);
        Log::forgetChannel($canal);
        $ecrit = jetonsHorsDuJournalLire($dossier);
    } finally {
        File::deleteDirectory($dossier);
    }

    expect($ecrit)->toContain('Jeton refusé : [masqué]')
        ->and($ecrit)->toContain('Refus du jeton [masqué], en-tête Authorization: Bearer [masqué]')
        ->and($ecrit)->toContain('"entete":"Bearer [masqué]"')
        ->and(jetonsHorsDuJournalLaissentLire($ecrit))->toBeFalse();
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

/*
 * Le chemin réel : le gestionnaire des exceptions rapporte celle d'une
 * requête dont le message recopie le jeton, reçu dans la chaîne de requête,
 * et la valeur `Bearer` d'un autre en-tête.
 */
it('écrit sans jeton ni valeur Bearer l’exception qu’une vraie requête recopie', function (): void {
    $dossier = sys_get_temp_dir().'/jetons-hors-du-journal-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($dossier);
    $jeton = jetonsHorsDuJournalJeton();

    Config::set('logging.default', 'single');
    Config::set('logging.channels.single.path', $dossier.'/laravel.log');
    Log::forgetChannel('single');

    Route::post('/_boum-du-journal', static function (Request $requete): never {
        throw new RuntimeException('Refus du jeton '.$requete->string('jeton')->toString().', en-tête '.$requete->headers->get('X-Transmis'));
    });

    try {
        $this->post("/_boum-du-journal?jeton={$jeton}", [], ['X-Transmis' => jetonsHorsDuJournalPorteur()])->assertStatus(500);
        Log::forgetChannel('single');
        $ecrit = jetonsHorsDuJournalLire($dossier);
    } finally {
        File::deleteDirectory($dossier);
    }

    expect($ecrit)->toContain('Refus du jeton [masqué], en-tête Bearer [masqué]')
        ->and(jetonsHorsDuJournalLaissentLire($ecrit))->toBeFalse();
});

/*
 * Un formateur qui rend un tableau (`NormalizerFormatter`), une ligne ou un
 * lot, est masqué texte par texte.
 */
it('masque chaque texte d’un tableau formaté', function (): void {
    $formateur = new FormateurSansAdresse(new NormalizerFormatter());
    $enregistrement = new LogRecord(
        new DateTimeImmutable(),
        'testing',
        Level::Error,
        'Jeton refusé : '.jetonsHorsDuJournalJeton(),
        ['entete' => jetonsHorsDuJournalPorteur(), 'profond' => ['jeton' => jetonsHorsDuJournalJeton()]],
    );

    $ligne = $formateur->format($enregistrement);
    $lot = $formateur->formatBatch([$enregistrement]);

    expect($ligne)->toBeArray()
        ->and(data_get($ligne, 'message'))->toBe('Jeton refusé : [masqué]')
        ->and(data_get($ligne, 'context'))->toBe(['entete' => 'Bearer [masqué]', 'profond' => ['jeton' => '[masqué]']])
        ->and($lot)->toBeArray()
        ->and(data_get($lot, '0.message'))->toBe('Jeton refusé : [masqué]')
        ->and(data_get($lot, '0.context'))->toBe(['entete' => 'Bearer [masqué]', 'profond' => ['jeton' => '[masqué]']]);
});
