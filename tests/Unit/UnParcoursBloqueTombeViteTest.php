<?php

declare(strict_types=1);

use Facebook\WebDriver\Exception\Internal\UnexpectedResponseException;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\Exception\TimeoutException;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\DriverCommand;
use Facebook\WebDriver\Remote\HttpCommandExecutor;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\WebDriverCommand;
use Facebook\WebDriver\Remote\WebDriverResponse;
use Facebook\WebDriver\WebDriverOptions;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Laravel\Dusk\Concerns\ProvidesBrowser;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Tests\DuskTestCase;
use Tests\Support\NavigateurDesParcours;
use Tests\Support\PiloteDesParcours;

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
 * Le pilote des parcours, quand il ne rend plus de réponse : chaque ordre part
 * vers une adresse qui accepte la connexion sans jamais répondre, ou que plus
 * rien n'écoute, et lève une `WebDriverCurlException`, comme un ordre resté
 * sans réponse dans SECONDES_POUR_UN_ORDRE (ici deux dixièmes de seconde).
 * Chaque ordre que le parcours demande s'inscrit au carnet, avec le temps
 * qu'il a pris, qu'il soit parti ou non.
 *
 * @param  ArrayObject<int, array{string, float}>  $carnet
 */
function unParcoursBloquePiloteSansReponse(string $adresse, ArrayObject $carnet): PiloteDesParcours
{
    return new class($adresse, $carnet) extends PiloteDesParcours
    {
        /**
         * @param  ArrayObject<int, array{string, float}>  $carnet
         */
        public function __construct(string $adresse, private readonly ArrayObject $carnet)
        {
            $executeur = new HttpCommandExecutor($adresse);
            $executeur->setRequestTimeout(200);

            parent::__construct($executeur, 'pilote-sans-reponse', DesiredCapabilities::chrome(), true);
        }

        /**
         * @param  string  $command_name
         * @param  array<mixed>  $params
         */
        #[\Override]
        public function execute($command_name, $params = []): mixed
        {
            $debut = microtime(true);

            try {
                return parent::execute($command_name, $params);
            } finally {
                $this->carnet[] = [$command_name, microtime(true) - $debut];
            }
        }
    };
}

/**
 * Les ordres inscrits au carnet d'un pilote, dans l'ordre.
 *
 * @param  ArrayObject<int, array{string, float}>  $carnet
 * @return list<string>
 */
function unParcoursBloqueOrdresRecus(ArrayObject $carnet): array
{
    return array_values(array_map(static fn (array $ordre): string => $ordre[0], $carnet->getArrayCopy()));
}

/**
 * Le temps qu'a pris la fermeture inscrite au carnet d'un pilote, ou null s'il
 * n'en a reçu aucune.
 *
 * @param  ArrayObject<int, array{string, float}>  $carnet
 */
function unParcoursBloqueDureeDeLaFermeture(ArrayObject $carnet): ?float
{
    foreach ($carnet as [$ordre, $duree]) {
        if ($ordre === DriverCommand::QUIT) {
            return $duree;
        }
    }

    return null;
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
         * Ce pilote a répondu, par des erreurs : son navigateur reste à la
         * classe, comme Dusk le fait.
         */
        expect($parcours->navigateursGardes()->all())->toBe([$navigateur]);

        /*
         * Hors d'un échec, la console garde sa parole : un parcours réussi dont
         * le navigateur ne la rend pas tombe, comme avant.
         */
        expect(fn () => $parcours->browse(function (Browser $navigateur): void {
        }))->toThrow(new TimeoutException('timeout: la console ne répond plus'));

        expect($parcours->signalements)->toHaveCount(3)
            ->and($parcours->navigateursGardes()->all())->toBe([$navigateur]);
    } finally {
        $parcours->oublierLesNavigateurs();
        Browser::$storeScreenshotsAt = $dossierDesCaptures;
    }
});

/*
 * Dusk ne démarre un navigateur que si la classe n'en garde aucun. Rendu à la
 * classe, le navigateur d'un pilote muet faisait tomber chacun des parcours
 * suivants sur un ordre sans réponse, après SECONDES_POUR_UN_ORDRE, puis
 * attendre autant sa capture et sa console : mesuré avec un vrai navigateur
 * figé, 30 s par parcours pour une borne de 10 s. Avec la borne de 90 s, les
 * huit parcours de WorkoutSetEntryRegressionTest auraient dépassé les vingt
 * minutes de l'éclat.
 */
it('écarte le navigateur d’un pilote qui ne répond plus, et le parcours suivant en démarre un neuf', function (): void {
    $ecoute = stream_socket_server('tcp://127.0.0.1:0', $codeDErreur, $erreur);
    $fermee = stream_socket_server('tcp://127.0.0.1:0', $codeDErreur, $erreur);

    if ($ecoute === false || $fermee === false) {
        throw new RuntimeException("faux pilote impossible : {$erreur}");
    }

    $adresseMuette = 'http://'.stream_socket_get_name($ecoute, false);
    $adresseMorte = 'http://'.stream_socket_get_name($fermee, false);
    fclose($fermee);

    $parcours = new class('parcoursFictif') extends DuskTestCase
    {
        public const int SECONDES_POUR_FERMER_UN_NAVIGATEUR_MUET = 1;

        /**
         * @var list<string>
         */
        public array $signalements = [];

        public int $navigateursDemarres = 0;

        public function parcoursFictif(): void
        {
        }

        public function confierLeNavigateur(Browser $navigateur): void
        {
            self::$browsers = new Collection([$navigateur]);
            $this->signalements = [];
        }

        /**
         * @return Collection<array-key, mixed>
         */
        public function navigateursGardes(): Collection
        {
            return new Collection(self::$browsers);
        }

        /**
         * @return Collection<array-key, mixed>
         */
        public function navigateursDuParcoursSuivant(): Collection
        {
            return $this->createBrowsersFor(function (Browser $navigateur): void {
            });
        }

        public function oublierLesNavigateurs(): void
        {
            self::$browsers = new Collection();
        }

        #[\Override]
        protected function driver(): RemoteWebDriver
        {
            $this->navigateursDemarres++;

            return unParcoursBloquePiloteFige();
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

    $ecart = ') ; il ne reçoit plus que la fermeture de ses navigateurs, écartés, et le parcours suivant en démarre un neuf (#2029).'."\n";
    $dossierDesCaptures = Browser::$storeScreenshotsAt;
    Browser::$storeScreenshotsAt = sys_get_temp_dir();

    try {
        /*
         * La capture ne vient pas : ni la source ni la console ne sont
         * demandées, et la fermeture n'attend que sa propre borne, une seconde
         * ici, et non celle des autres ordres.
         */
        /** @var ArrayObject<int, array{string, float}> $carnetMuet */
        $carnetMuet = new ArrayObject();
        $navigateurMuet = new Browser(unParcoursBloquePiloteSansReponse($adresseMuette, $carnetMuet));
        $navigateurMuet->disableFitOnFailure();
        $navigateurMuet->madeSourceAssertion = true;
        $parcours->confierLeNavigateur($navigateurMuet);

        expect(fn () => $parcours->browse(function (Browser $navigateur): void {
            throw new RuntimeException('le parcours est tombé');
        }))->toThrow(new RuntimeException('le parcours est tombé'));

        expect(unParcoursBloqueOrdresRecus($carnetMuet))->toBe([DriverCommand::SCREENSHOT, DriverCommand::QUIT])
            ->and(unParcoursBloqueDureeDeLaFermeture($carnetMuet))->toBeGreaterThanOrEqual(0.9)->toBeLessThan(5.0)
            ->and($parcours->navigateursGardes()->all())->toBe([])
            ->and($parcours->signalements)->toHaveCount(3)
            ->and($parcours->signalements[0])
            ->toStartWith('parcoursFictif : capture d’écran impossible (Facebook\WebDriver\Exception\Internal\WebDriverCurlException : Curl error thrown for http GET to /session/pilote-sans-reponse/screenshot')
            ->and($parcours->signalements[1])
            ->toStartWith('parcoursFictif : le pilote ne répond plus (Curl error thrown for http GET to /session/pilote-sans-reponse/screenshot')
            ->toEndWith($ecart)
            ->and($parcours->signalements[2])
            ->toStartWith('parcoursFictif : fermeture du navigateur écarté impossible (Facebook\WebDriver\Exception\Internal\WebDriverCurlException : Curl error thrown for http DELETE to /session/pilote-sans-reponse');

        $suivants = $parcours->navigateursDuParcoursSuivant();

        expect($parcours->navigateursDemarres)->toBe(1)
            ->and($suivants)->toHaveCount(1)
            ->and($suivants->first())->toBeInstanceOf(NavigateurDesParcours::class)
            ->and($suivants->first())->not->toBe($navigateurMuet);

        /*
         * Le parcours tombe lui-même sur un ordre sans réponse, ici parce que
         * plus rien n'écoute : aucun relevé n'est tenté.
         */
        /** @var ArrayObject<int, array{string, float}> $carnetMort */
        $carnetMort = new ArrayObject();
        $parcours->confierLeNavigateur(new Browser(unParcoursBloquePiloteSansReponse($adresseMorte, $carnetMort)));

        expect(fn () => $parcours->browse(function (Browser $navigateur): void {
            $navigateur->driver->getTitle();
        }))->toThrow(UnexpectedResponseException::class, 'Curl error thrown for http GET to /session/pilote-sans-reponse/title');

        expect(unParcoursBloqueOrdresRecus($carnetMort))->toBe([DriverCommand::GET_TITLE, DriverCommand::QUIT])
            ->and($parcours->navigateursGardes()->all())->toBe([])
            ->and($parcours->signalements)->toHaveCount(2)
            ->and($parcours->signalements[0])
            ->toStartWith('parcoursFictif : le pilote ne répond plus (Curl error thrown for http GET to /session/pilote-sans-reponse/title')
            ->toEndWith($ecart);

        /*
         * Un parcours réussi dont la console ne vient pas échoue comme avant,
         * et son navigateur est écarté de même.
         */
        /** @var ArrayObject<int, array{string, float}> $carnetMortEnFin */
        $carnetMortEnFin = new ArrayObject();
        $parcours->confierLeNavigateur(new Browser(unParcoursBloquePiloteSansReponse($adresseMorte, $carnetMortEnFin)));

        expect(fn () => $parcours->browse(function (Browser $navigateur): void {
        }))->toThrow(UnexpectedResponseException::class, 'Curl error thrown for http POST to /session/pilote-sans-reponse/log');

        expect(unParcoursBloqueOrdresRecus($carnetMortEnFin))->toBe([DriverCommand::GET_LOG, DriverCommand::QUIT])
            ->and($parcours->navigateursGardes()->all())->toBe([])
            ->and($parcours->signalements)->toHaveCount(2)
            ->and($parcours->signalements[0])
            ->toStartWith('parcoursFictif : le pilote ne répond plus (Curl error thrown for http POST to /session/pilote-sans-reponse/log')
            ->toEndWith($ecart);
    } finally {
        $parcours->oublierLesNavigateurs();
        Browser::$storeScreenshotsAt = $dossierDesCaptures;
        fclose($ecoute);
    }
});

/*
 * Dusk avale une partie des exceptions : ses attentes (`waitUsing()`) prennent
 * celle de leur condition pour une condition fausse, et la recherche
 * d'éléments de `click()` pour un élément absent. Un ordre resté sans réponse
 * y devenait « Waited 5 seconds for selector » après quatre-vingt-dix
 * secondes, et `browse()`, qui ne le voyait pas, relevait la capture d'écran,
 * qui attendait à son tour derrière lui : mesuré avec un vrai navigateur figé
 * pendant un `waitFor()`, 26,2 s pour une borne ramenée à 10 s.
 */
it('fait tomber le parcours sur l’ordre resté sans réponse que Dusk avale, sans relevé ni délai de plus', function (): void {
    $ecoute = stream_socket_server('tcp://127.0.0.1:0', $codeDErreur, $erreur);

    if ($ecoute === false) {
        throw new RuntimeException("faux pilote impossible : {$erreur}");
    }

    $adresseMuette = 'http://'.stream_socket_get_name($ecoute, false);

    $parcours = new class('parcoursFictif') extends DuskTestCase
    {
        public const int SECONDES_POUR_FERMER_UN_NAVIGATEUR_MUET = 1;

        /**
         * @var list<string>
         */
        public array $signalements = [];

        public ?PiloteDesParcours $pilote = null;

        public function parcoursFictif(): void
        {
        }

        public function confierLePilote(PiloteDesParcours $pilote): void
        {
            $this->pilote = $pilote;
            $this->signalements = [];
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
        protected function driver(): PiloteDesParcours
        {
            return $this->pilote ?? throw new RuntimeException('aucun pilote confié');
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

    $ecart = ') ; il ne reçoit plus que la fermeture de ses navigateurs, écartés, et le parcours suivant en démarre un neuf (#2029).'."\n";
    $dossierDesCaptures = Browser::$storeScreenshotsAt;
    Browser::$storeScreenshotsAt = sys_get_temp_dir();

    try {
        /*
         * Une attente dont la condition attend sa réponse plus longtemps que
         * l'attente elle-même : elle tombe sur l'ordre, pas sur son délai.
         */
        /** @var ArrayObject<int, array{string, float}> $carnetDeLAttente */
        $carnetDeLAttente = new ArrayObject();
        /** @var ArrayObject<int, Browser> $navigateursDuParcours */
        $navigateursDuParcours = new ArrayObject();
        $parcours->confierLePilote(unParcoursBloquePiloteSansReponse($adresseMuette, $carnetDeLAttente));

        expect(fn () => $parcours->browse(function (Browser $navigateur) use ($navigateursDuParcours): void {
            $navigateursDuParcours[] = $navigateur;
            $navigateur->waitFor('[dusk="jamais"]', 1);
        }))->toThrow(UnexpectedResponseException::class, 'Curl error thrown for http POST to /session/pilote-sans-reponse/element with params: {"using":"css selector","value":"body [dusk=\"jamais\"]"}');

        expect($navigateursDuParcours[0] ?? null)->toBeInstanceOf(NavigateurDesParcours::class)
            ->and(unParcoursBloqueOrdresRecus($carnetDeLAttente))->toBe([DriverCommand::FIND_ELEMENT, DriverCommand::QUIT])
            ->and($parcours->navigateursGardes()->all())->toBe([])
            ->and($parcours->signalements)->toHaveCount(2)
            ->and($parcours->signalements[0])
            ->toStartWith('parcoursFictif : le pilote ne répond plus (Curl error thrown for http POST to /session/pilote-sans-reponse/element')
            ->toEndWith($ecart)
            ->and($parcours->signalements[1])
            ->toStartWith('parcoursFictif : fermeture du navigateur écarté impossible (Facebook\WebDriver\Exception\Internal\WebDriverCurlException : Curl error thrown for http DELETE to /session/pilote-sans-reponse');

        /*
         * `click()` prend l'ordre resté sans réponse pour un élément absent :
         * le parcours tombe sur le message de Dusk, mais le pilote est su
         * muet, et le signalement nomme l'ordre.
         */
        /** @var ArrayObject<int, array{string, float}> $carnetDuClic */
        $carnetDuClic = new ArrayObject();
        $parcours->confierLePilote(unParcoursBloquePiloteSansReponse($adresseMuette, $carnetDuClic));

        expect(fn () => $parcours->browse(function (Browser $navigateur): void {
            $navigateur->click('[dusk="jamais"]');
        }))->toThrow(new NoSuchElementException('Unable to locate element with selector [[dusk="jamais"]].'));

        expect(unParcoursBloqueOrdresRecus($carnetDuClic))->toBe([DriverCommand::FIND_ELEMENTS, DriverCommand::QUIT])
            ->and($parcours->navigateursGardes()->all())->toBe([])
            ->and($parcours->signalements)->toHaveCount(2)
            ->and($parcours->signalements[0])
            ->toStartWith('parcoursFictif : le pilote ne répond plus (Curl error thrown for http POST to /session/pilote-sans-reponse/elements')
            ->toEndWith($ecart);
    } finally {
        $parcours->oublierLesNavigateurs();
        Browser::$storeScreenshotsAt = $dossierDesCaptures;
        fclose($ecoute);
    }
});

/*
 * Derrière un ordre resté sans réponse, le pilote ferait attendre chaque ordre
 * suivant autant, et une saisie de Dusk en essaie trois. Seule la fermeture
 * part encore. Un pilote qui répond, même par une erreur, reste entendu.
 */
it('ne fait plus partir que la fermeture après un ordre resté sans réponse, et pas après une réponse en erreur', function (): void {
    $ecoute = stream_socket_server('tcp://127.0.0.1:0', $codeDErreur, $erreur);

    if ($ecoute === false) {
        throw new RuntimeException("faux pilote impossible : {$erreur}");
    }

    /** @var ArrayObject<int, array{string, float}> $carnet */
    $carnet = new ArrayObject();
    $pilote = unParcoursBloquePiloteSansReponse('http://'.stream_socket_get_name($ecoute, false), $carnet);

    try {
        expect(fn () => $pilote->getTitle())
            ->toThrow(UnexpectedResponseException::class, 'Curl error thrown for http GET to /session/pilote-sans-reponse/title');

        $sansReponse = $pilote->ordreSansReponse();
        $refus = null;

        try {
            $pilote->getCurrentURL();
        } catch (UnexpectedResponseException $exception) {
            $refus = $exception;
        }

        expect($sansReponse)->toBeInstanceOf(UnexpectedResponseException::class)
            ->and($refus)->toBeInstanceOf(UnexpectedResponseException::class)
            ->and($refus === null ? null : $refus::class)->toBe(UnexpectedResponseException::class)
            ->and($refus?->getMessage())->toBe('L’ordre « getCurrentUrl » n’est pas envoyé : le pilote n’a pas répondu à un ordre précédent du parcours (#2029).')
            ->and($refus?->getPrevious())->toBe($sansReponse);

        expect(fn () => $pilote->quit())
            ->toThrow(UnexpectedResponseException::class, 'Curl error thrown for http DELETE to /session/pilote-sans-reponse');

        $durees = array_column($carnet->getArrayCopy(), 1, 0);

        expect(unParcoursBloqueOrdresRecus($carnet))->toBe([DriverCommand::GET_TITLE, DriverCommand::GET_CURRENT_URL, DriverCommand::QUIT])
            ->and($durees[DriverCommand::GET_TITLE])->toBeGreaterThanOrEqual(0.15)
            ->and($durees[DriverCommand::GET_CURRENT_URL])->toBeLessThan(0.05)
            ->and($durees[DriverCommand::QUIT])->toBeGreaterThanOrEqual(0.15)
            ->and($pilote->ordreSansReponse())->toBe($sansReponse);
    } finally {
        fclose($ecoute);
    }

    /*
     * Le pilote répond, par une erreur, puis par une réponse illisible : il
     * reste entendu, et l'ordre suivant part.
     */
    $executeur = new class('http://127.0.0.1:1') extends HttpCommandExecutor
    {
        public int $ordresRecus = 0;

        #[\Override]
        public function execute(WebDriverCommand $command): WebDriverResponse
        {
            $this->ordresRecus++;

            if ($this->ordresRecus === 1) {
                throw new TimeoutException('timeout: Timed out receiving message from renderer: 10.000');
            }

            if ($this->ordresRecus === 2) {
                throw UnexpectedResponseException::forJsonDecodingError(JSON_ERROR_SYNTAX, '<html>');
            }

            return new WebDriverResponse('pilote-qui-repond')->setValue('GymTracker');
        }
    };
    $piloteQuiRepond = new class($executeur) extends PiloteDesParcours
    {
        public function __construct(HttpCommandExecutor $executeur)
        {
            parent::__construct($executeur, 'pilote-qui-repond', DesiredCapabilities::chrome(), true);
        }
    };

    expect(fn () => $piloteQuiRepond->getTitle())->toThrow(TimeoutException::class)
        ->and(fn () => $piloteQuiRepond->getTitle())->toThrow(UnexpectedResponseException::class, 'JSON decoding of remote response failed.')
        ->and($piloteQuiRepond->getTitle())->toBe('GymTracker')
        ->and($piloteQuiRepond->ordreSansReponse())->toBeNull();
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

        expect(microtime(true) - $debut)->toBeGreaterThanOrEqual(0.9)->toBeLessThan(10.0);

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
 * `DuskTestCase::browse()` reprend celui de Dusk, `newBrowser()` le remplace,
 * et l'écart d'un navigateur muet compte sur `createBrowsersFor()`, qui ne
 * démarre un navigateur que si la classe n'en garde aucun. Une montée de
 * laravel/dusk ne touche que composer.json et composer.lock : sans ce test,
 * une version qui changerait l'un d'eux serait masquée en silence.
 */
it('fait relire les surcharges de DuskTestCase quand Dusk change le code qu’elles reprennent', function (): void {
    $empreintes = [];

    foreach (['browse', 'createBrowsersFor', 'newBrowser'] as $methode) {
        $reflexion = new ReflectionMethod(ProvidesBrowser::class, $methode);
        $fichier = $reflexion->getFileName();
        $source = $fichier === false ? false : file($fichier);
        $premiereLigne = $reflexion->getStartLine();
        $derniereLigne = $reflexion->getEndLine();

        if ($source === false || $premiereLigne === false || $derniereLigne === false) {
            throw new RuntimeException("source de ProvidesBrowser::{$methode}() introuvable");
        }

        $lignes = array_slice($source, $premiereLigne - 1, $derniereLigne - $premiereLigne + 1);
        $empreintes[$methode] = hash('sha256', implode('', $lignes));
    }

    expect($empreintes)->toBe([
        'browse' => 'b50efa7fcc652f8e7ba6bb8a755fd510f421587762c476c4726039d6a8099eec',
        'createBrowsersFor' => 'dc2bd781ff5457ee7dc67b4c9e2ec9fe8f6ecdcbf92ab920f286a597007b8cbd',
        'newBrowser' => '5d292d734cd4e6a4e66baf69c97d67c0a3074ec06435fb0e15966eb71dd7e7cc',
    ], 'Dusk a changé le code que reprennent browse(), newBrowser() et l’écart d’un navigateur muet de tests/DuskTestCase.php : relire ces surcharges contre la nouvelle version (.ai/rules/browser.md, #2029), puis reporter ici les nouvelles empreintes.');
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

/*
 * Le parcours figé du 08/10 était le premier de son éclat, et trente-cinq
 * autres l'ont suivi : les dernières lignes du journal du pilote ne parlaient
 * plus de lui. Le vidage d'un éclat tombé cherche donc sur tout le journal
 * l'ordre resté sans réponse et les erreurs du pilote.
 */
it('nomme, au vidage d’un éclat tombé, l’ordre resté sans réponse et les erreurs du pilote, loin de la fin du journal', function (): void {
    $ci = Yaml::parseFile(dirname(__DIR__, 2).'/.github/workflows/ci.yml');
    $vidage = null;

    foreach ((array) data_get($ci, 'jobs.browser-shard.steps', []) as $etape) {
        if (data_get($etape, 'name') === 'Dump Logs on Failure') {
            $vidage = data_get($etape, 'run');
        }
    }

    expect($vidage)->toBeString();

    $session = static fn (string $lettre): string => str_repeat($lettre, 32);
    $ligne = static fn (string $heure, string $texte): string => "[10-08-2026 {$heure}][INFO]: {$texte}";
    $journal = [
        $ligne('20:00:30.100000', "[{$session('a')}] COMMAND InitSession {"),
        $ligne('20:00:30.300000', "[{$session('a')}] RESPONSE InitSession {"),
        $ligne('20:00:30.400000', "[{$session('a')}] COMMAND Navigate {"),
        '[10-08-2026 20:01:30.400000][SEVERE]: Timed out receiving message from renderer: 60.000',
        $ligne('20:01:30.410000', "[{$session('a')}] RESPONSE Navigate ERROR timeout: Timed out receiving message from renderer: -0.003"),
        $ligne('20:01:30.500000', "[{$session('a')}] COMMAND Screenshot {"),
        $ligne('20:03:00.000000', "[{$session('b')}] COMMAND InitSession {"),
        '[10-08-2026 20:03:00.100000][SEVERE]: CreatePlatformSocket() failed: Address family not supported by protocol (97)',
        $ligne('20:03:00.200000', "[{$session('b')}] RESPONSE InitSession {"),
    ];

    for ($parcoursSuivant = 0; $parcoursSuivant < 200; $parcoursSuivant++) {
        $journal[] = $ligne('20:04:00.000000', "[{$session('b')}] COMMAND FindElement {");
        $journal[] = $ligne('20:04:00.100000', "[{$session('b')}] RESPONSE FindElement ERROR no such element: Unable to locate element");
        $journal[] = $ligne('20:04:00.200000', "[{$session('b')}] COMMAND GetTitle {");
        $journal[] = $ligne('20:04:00.300000', "[{$session('b')}] RESPONSE GetTitle \"GymTracker\"");
    }

    $dossier = sys_get_temp_dir().'/vidage-du-pilote-'.bin2hex(random_bytes(6));
    mkdir($dossier.'/storage/logs', 0777, true);
    file_put_contents($dossier.'/storage/logs/chromedriver.log', implode("\n", $journal)."\n");

    try {
        $processus = new Process(['bash', '-e', '-c', is_string($vidage) ? $vidage : ''], $dossier);
        $processus->mustRun();
        $sortie = $processus->getOutput();
    } finally {
        new Filesystem()->deleteDirectory($dossier);
    }

    expect(trim(Str::betweenFirst($sortie, "--- Ordres du pilote restés sans réponse ---\n", '---')))
        ->toBe($ligne('20:01:30.500000', "[{$session('a')}] COMMAND Screenshot {"))
        ->and(trim(Str::betweenFirst($sortie, "--- Erreurs du pilote ---\n", '---')))
        ->toBe(implode("\n", [
            '[10-08-2026 20:01:30.400000][SEVERE]: Timed out receiving message from renderer: 60.000',
            $ligne('20:01:30.410000', "[{$session('a')}] RESPONSE Navigate ERROR timeout: Timed out receiving message from renderer: -0.003"),
        ]));
});
