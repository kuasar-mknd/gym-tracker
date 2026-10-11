<?php

declare(strict_types=1);

use Facebook\WebDriver\Exception\Internal\UnexpectedResponseException;
use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\HttpCommandExecutor;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverOptions;
use Illuminate\Support\Collection;
use Laravel\Dusk\Browser;
use Symfony\Component\Yaml\Yaml;
use Tests\DuskTestCase;

/**
 * Le 08/10/2026, le premier parcours de l'éclat 2 a tenu près de sept minutes
 * sans qu'aucune requête n'atteigne l'application, puis l'éclat a rapporté
 * « Timed out receiving message from renderer: 10.000 » sur l'appel de
 * `browse()` (#2029). Ce message était celui de la capture d'écran que Dusk
 * prend après un échec : le navigateur ne rendait plus rien, la capture a
 * levé à son tour, et son exception a remplacé celle du parcours, dont la
 * cause n'a jamais été connue. Rien ne bornait ces sept minutes que les
 * délais par défaut du pilote (trois cents secondes par page) et de
 * php-webdriver (cent quatre-vingts par ordre), que la suite ne réglait pas.
 */

/**
 * Un pilote dont le navigateur ne rend plus rien : la capture d'écran, la
 * source de la page et la lecture de la console expirent, comme sur l'éclat
 * du 08/10.
 */
function unParcoursBloquePiloteFige(): RemoteWebDriver
{
    return new class() extends RemoteWebDriver
    {
        /**
         * Public pour que l'essai l'instancie, quand celui de RemoteWebDriver
         * est protégé. L'exécuteur ne joint personne : chaque ordre que la
         * capture et les relevés envoient est surchargé ci-dessous.
         */
        public function __construct()
        {
            parent::__construct(new HttpCommandExecutor('http://127.0.0.1:1'), 'parcours-fige', DesiredCapabilities::chrome(), true);
        }

        #[\Override]
        public function takeScreenshot($save_as = null): string
        {
            throw new TimeoutException("timeout: Timed out receiving message from renderer: 10.000\n  (Session info: chrome=154.0.8037.97)");
        }

        #[\Override]
        public function getPageSource(): string
        {
            throw new TimeoutException('timeout: la source ne vient pas');
        }

        #[\Override]
        public function manage(): WebDriverOptions
        {
            throw new TimeoutException('timeout: la console ne répond plus');
        }
    };
}

/**
 * Lit la requête qu'un client a laissée sur une connexion en attente.
 *
 * @param  resource  $ecoute
 */
function unParcoursBloqueRequeteRecue($ecoute): string
{
    $connexion = stream_socket_accept($ecoute, 5);

    if ($connexion === false) {
        throw new RuntimeException('aucune requête n’a atteint le faux pilote');
    }

    stream_set_timeout($connexion, 2);
    $requete = '';

    while (! feof($connexion)) {
        $morceau = fread($connexion, 8192);

        if ($morceau === false || ($morceau === '' && stream_get_meta_data($connexion)['timed_out'])) {
            break;
        }

        $requete .= $morceau;

        if (preg_match('/Content-Length: (\d+)\r\n/i', $requete, $longueur) === 1
            && str_contains($requete, "\r\n\r\n")
            && strlen(explode("\r\n\r\n", $requete, 2)[1]) >= (int) $longueur[1]) {
            break;
        }
    }

    fclose($connexion);

    return $requete;
}

it('rapporte l’échec du parcours, et non celui de la capture qui le suit', function (): void {
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

        public function rendreLesNavigateurs(): void
        {
            self::$browsers = new Collection();
        }

        #[\Override]
        protected function getCallerName(): string
        {
            return 'parcoursFictif';
        }

        #[\Override]
        protected function signalerUnReleveImpossible(string $signalement): void
        {
            $this->signalements[] = $signalement;
        }
    };

    $navigateur = new Browser(unParcoursBloquePiloteFige());
    $navigateur->disableFitOnFailure();
    $navigateur->madeSourceAssertion = true;
    $dossierDesCaptures = Browser::$storeScreenshotsAt;
    Browser::$storeScreenshotsAt = sys_get_temp_dir();
    $parcours->confierLeNavigateur($navigateur);

    try {
        expect(fn () => $parcours->browse(function (Browser $navigateur): void {
            throw new RuntimeException('la première page n’est jamais venue');
        }))->toThrow(new RuntimeException('la première page n’est jamais venue'));

        expect($parcours->signalements)->toHaveCount(3)
            ->and($parcours->signalements[0])
            ->toStartWith('parcoursFictif : capture d’écran impossible (Facebook\WebDriver\Exception\TimeoutException : timeout: Timed out receiving message from renderer: 10.000')
            ->toEndWith("L’échec rapporté pour ce parcours est le sien, pas celui-ci (#2029).\n")
            ->and($parcours->signalements[1])
            ->toBe("parcoursFictif : source de la page impossible (Facebook\WebDriver\Exception\TimeoutException : timeout: la source ne vient pas). L’échec rapporté pour ce parcours est le sien, pas celui-ci (#2029).\n")
            ->and($parcours->signalements[2])
            ->toBe("parcoursFictif : console du navigateur impossible (Facebook\WebDriver\Exception\TimeoutException : timeout: la console ne répond plus). L’échec rapporté pour ce parcours est le sien, pas celui-ci (#2029).\n");

        /*
         * Hors d'un échec, la console garde sa parole : un parcours réussi dont
         * le navigateur ne la rend pas tombe, comme avant.
         */
        expect(fn () => $parcours->browse(function (Browser $navigateur): void {
        }))->toThrow(new TimeoutException('timeout: la console ne répond plus'));

        expect($parcours->signalements)->toHaveCount(3);
    } finally {
        $parcours->rendreLesNavigateurs();
        Browser::$storeScreenshotsAt = $dossierDesCaptures;
    }
});

it('abandonne en secondes un ordre que le pilote ne rend jamais, et borne la page et les scripts', function (): void {
    $ecoute = stream_socket_server('tcp://127.0.0.1:0', $codeDErreur, $erreur);

    if ($ecoute === false) {
        throw new RuntimeException("faux pilote impossible : {$erreur}");
    }

    $parcours = new class('parcoursFictif') extends DuskTestCase
    {
        public const int SECONDES_POUR_UN_ORDRE = 1;

        public string $adresseDuPilote = '';

        public function parcoursFictif(): void
        {
        }

        public function demarrerLeNavigateur(): RemoteWebDriver
        {
            return $this->driver();
        }

        #[\Override]
        protected function urlDuPilote(): string
        {
            return $this->adresseDuPilote;
        }
    };

    $parcours->adresseDuPilote = 'http://'.stream_socket_get_name($ecoute, false);

    try {
        $debut = microtime(true);

        expect(fn (): RemoteWebDriver => $parcours->demarrerLeNavigateur())
            ->toThrow(UnexpectedResponseException::class, 'Operation timed out after');

        expect(microtime(true) - $debut)->toBeLessThan(10.0);

        $requete = unParcoursBloqueRequeteRecue($ecoute);
        $demande = json_decode(explode("\r\n\r\n", $requete, 2)[1] ?? '', true, 512, JSON_THROW_ON_ERROR);

        expect(data_get($demande, 'capabilities.firstMatch.0.timeouts'))->toBe([
            'pageLoad' => 60_000,
            'script' => 30_000,
        ]);
    } finally {
        fclose($ecoute);
    }

    /*
     * Le pilote borne la page et le script lui-même, et son message nomme ce
     * qui a manqué : la borne de php-webdriver doit venir après les siennes,
     * et avant les cent quatre-vingts secondes qu'il accorde sans réglage.
     */
    expect(DuskTestCase::SECONDES_POUR_UN_ORDRE)
        ->toBeGreaterThan(DuskTestCase::SECONDES_POUR_CHARGER_UNE_PAGE)
        ->toBeGreaterThan(DuskTestCase::SECONDES_POUR_UN_SCRIPT)
        ->toBeLessThan(180);
});

/*
 * Le journal du serveur disait seulement qu'aucune requête n'était arrivée ;
 * celui du pilote, qui horodate chaque ordre et sa réponse, aurait dit lequel
 * attendait. La CI le garde désormais, et le joint aux journaux d'un éclat
 * tombé.
 */
it('garde le journal du pilote avec ceux d’un éclat tombé', function (): void {
    $ci = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/ci.yml');
    $etapes = [];

    foreach ((array) data_get($ci, 'jobs.browser-shard.steps', []) as $etape) {
        $nom = data_get($etape, 'name');

        if (is_string($nom)) {
            $etapes[$nom] = $etape;
        }
    }

    $demarrage = data_get($etapes, 'Start Chrome Driver.run');
    $envoi = data_get($etapes, 'Upload Test Logs.with.path');

    expect($demarrage)->toBeString()
        ->toContain('--log-path=storage/logs/chromedriver.log')
        ->and(data_get($etapes, 'Upload Test Logs.if'))->toBe('failure()')
        ->and($envoi)->toBeString()
        ->and(preg_split('/\R/', trim(is_string($envoi) ? $envoi : '')))
        ->toContain('storage/logs/chromedriver.log')
        ->toContain('tests/Browser/console');
});
