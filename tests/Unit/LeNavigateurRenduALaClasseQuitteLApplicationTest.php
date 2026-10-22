<?php

declare(strict_types=1);

use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\DriverCommand;
use Facebook\WebDriver\Remote\HttpCommandExecutor;
use Facebook\WebDriver\Remote\WebDriverCommand;
use Facebook\WebDriver\Remote\WebDriverResponse;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;
use Tests\Support\PiloteDesParcours;

/**
 * Dusk rend le navigateur d'un parcours à sa classe, sur la dernière page du
 * parcours, et le suivant repart de là. Une écriture que cette page n'avait pas
 * vu revenir (la base l'avait, la réponse était en route) était coupée par la
 * première visite du parcours suivant, mise en file au nom du compte, et
 * rejouée par sa première page : sous `DatabaseTruncation`, la base vidée
 * reprend ses identifiants à 1, et le compte suivant porte le même. Une coche
 * dont la réponse était retenue 1,5 s après l'écriture faisait tomber
 * `RestTimerTest` sur un 404 rejoué dans sa console ; une création retenue 2 s,
 * `WorkoutSetEntryRegressionTest` sur trois séries au lieu de deux (#2100).
 * La page arrêtée, un brouillon de série resté sans envoi se rejouait de même
 * sur la série de même identifiant : la sortie vide la file et les brouillons.
 * Ces parcours se rejouent dans Chrome ; ce test-ci tient l'ordre des gestes
 * et les clés vidées.
 */

/**
 * Les ordres reçus, nommés, l'adresse visitée et le script envoyé à leur suite.
 *
 * @param  list<array{string, array<mixed>}>  $ordres
 * @return list<string>
 */
function leNavigateurRenduOrdres(array $ordres): array
{
    return array_map(static function (array $ordre): string {
        [$nom, $parametres] = $ordre;

        return match ($nom) {
            DriverCommand::GET => $nom.' '.(is_string($parametres['url'] ?? null) ? $parametres['url'] : '?'),
            DriverCommand::EXECUTE_SCRIPT => $nom.' '.(is_string($parametres['script'] ?? null) ? $parametres['script'] : '?'),
            default => $nom,
        };
    }, $ordres);
}

it('rend le navigateur à la classe hors de l’application, la file et les brouillons vidés, que le parcours réussisse ou tombe', function (): void {
    $parcours = new class('parcoursFictif') extends DuskTestCase
    {
        /**
         * @var list<string>
         */
        public array $signalements = [];

        public function parcoursFictif(): void
        {
        }

        public function confierLeNavigateur(Browser $navigateur): void
        {
            self::$browsers = new Collection([$navigateur]);
        }

        /**
         * @return Collection<array-key, mixed>
         */
        public function navigateursGardes(): Collection
        {
            return new Collection(self::$browsers);
        }

        public function oublierLesNavigateurs(): void
        {
            self::$browsers = new Collection();
        }

        #[\Override]
        protected function getCallerName(): string
        {
            return 'parcoursFictif';
        }

        #[\Override]
        protected function signaler(string $signalement): void
        {
            $this->signalements[] = $signalement;
        }
    };

    // Un exécuteur qui répond à tout ordre, comme un pilote sain, et note chacun avec ses paramètres.
    $executeur = new class('http://127.0.0.1:1') extends HttpCommandExecutor
    {
        /**
         * @var list<array{string, array<mixed>}>
         */
        public array $ordres = [];

        #[\Override]
        public function execute(WebDriverCommand $command): WebDriverResponse
        {
            $this->ordres[] = [$command->getName(), $command->getParameters() ?? []];

            return new WebDriverResponse('pilote-qui-repond')->setValue(match ($command->getName()) {
                DriverCommand::SCREENSHOT => base64_encode('capture'),
                DriverCommand::GET_LOG => [],
                default => null,
            });
        }
    };
    $pilote = new class($executeur) extends PiloteDesParcours
    {
        public function __construct(HttpCommandExecutor $executeur)
        {
            parent::__construct($executeur, 'pilote-qui-repond', DesiredCapabilities::chrome(), true);
        }
    };
    $navigateur = new Browser($pilote);
    $navigateur->disableFitOnFailure();

    $dossierDesCaptures = Browser::$storeScreenshotsAt;
    $dossierDesConsoles = Browser::$storeConsoleLogAt;
    $adresseDeBase = Browser::$baseUrl;
    $dossier = sys_get_temp_dir().'/navigateur-rendu-'.Str::random(8);
    mkdir($dossier);
    Browser::$storeScreenshotsAt = $dossier;
    Browser::$storeConsoleLogAt = $dossier;
    Browser::$baseUrl = 'http://127.0.0.1:8000';

    $sortie = [
        DriverCommand::GET.' http://127.0.0.1:8000'.DuskTestCase::PAGE_HORS_DE_L_APPLICATION,
        DriverCommand::EXECUTE_SCRIPT.' '.DuskTestCase::SCRIPT_QUI_VIDE_LES_ECRITURES,
    ];

    try {
        $parcours->confierLeNavigateur($navigateur);

        // La console est relevée sur la page du parcours, puis la page s'arrête.
        $parcours->browse(function (Browser $navigateur): void {
        });

        expect(leNavigateurRenduOrdres($executeur->ordres))->toBe([DriverCommand::GET_LOG, ...$sortie])
            ->and($parcours->navigateursGardes()->all())->toBe([$navigateur])
            ->and($parcours->signalements)->toBe([]);

        // Tombé, le parcours garde sa capture et sa console, prises avant.
        $executeur->ordres = [];

        expect(fn () => $parcours->browse(function (Browser $navigateur): void {
            throw new RuntimeException('le parcours est tombé');
        }))->toThrow(new RuntimeException('le parcours est tombé'));

        expect(leNavigateurRenduOrdres($executeur->ordres))->toBe([DriverCommand::SCREENSHOT, DriverCommand::GET_LOG, ...$sortie])
            ->and($parcours->navigateursGardes()->all())->toBe([$navigateur])
            ->and($parcours->signalements)->toBe([]);
    } finally {
        $parcours->oublierLesNavigateurs();
        Browser::$storeScreenshotsAt = $dossierDesCaptures;
        Browser::$storeConsoleLogAt = $dossierDesConsoles;
        Browser::$baseUrl = $adresseDeBase;
        new Filesystem()->deleteDirectory($dossier);
    }
});

it('vide les clés sous lesquelles l’application range sa file et ses brouillons, sur une page que le serveur sert sans elle', function (): void {
    $racine = dirname(__DIR__, 2);
    $file = (string) file_get_contents($racine.'/resources/js/Utils/seanceEnAttente.js');
    $brouillons = (string) file_get_contents($racine.'/resources/js/composables/useBrouillonsDeSeries.js');

    // Renommées dans l'application, la sortie viderait d'autres clés que les siennes.
    expect($file)->toContain("export const CLE_DE_LA_FILE = '".DuskTestCase::CLE_DE_LA_FILE_DES_ECRITURES."'")
        ->and($brouillons)->toContain("const PREFIXE_DES_BROUILLONS = '".DuskTestCase::PREFIXE_DES_BROUILLONS."'")
        ->and(DuskTestCase::SCRIPT_QUI_VIDE_LES_ECRITURES)
        ->toContain(json_encode(DuskTestCase::CLE_DE_LA_FILE_DES_ECRITURES, JSON_THROW_ON_ERROR))
        ->toContain(json_encode(DuskTestCase::PREFIXE_DES_BROUILLONS, JSON_THROW_ON_ERROR));

    // Un fichier de public/ : le serveur le sert tel quel, sans session ni code de l'application.
    expect(is_file($racine.'/public'.DuskTestCase::PAGE_HORS_DE_L_APPLICATION))->toBeTrue();
});
