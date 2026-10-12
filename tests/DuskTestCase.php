<?php

declare(strict_types=1);

namespace Tests;

use Closure;
use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Exception\Internal\UnexpectedResponseException;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Dusk\Browser;
use Laravel\Dusk\TestCase as BaseTestCase;
use RuntimeException;
use Tests\Support\GardeDesParcours;
use Tests\Support\NavigateurDesParcours;
use Tests\Support\PiloteDesParcours;
use Throwable;

abstract class DuskTestCase extends BaseTestCase
{
    /**
     * Le temps qu'une page a pour se charger, en secondes.
     *
     * Sans réglage, le pilote attend une navigation trois cents secondes, et
     * php-webdriver abandonne sa requête au bout de cent quatre-vingts : le
     * 08/10/2026, le premier parcours de l'éclat 2 a tenu près de sept minutes
     * sans qu'aucune requête n'atteigne l'application, pour finir sur une
     * erreur qui ne disait pas ce qui attendait (#2029). Sur les dix éclats
     * tombés de septembre et d'octobre dont le journal du serveur est gardé,
     * la première requête est arrivée deux à vingt-quatre secondes après le
     * départ de Dusk, base remise à neuf et navigateur démarré compris :
     * soixante laissent sa marge à un exécuteur lent, et font tomber une page
     * qui ne vient pas sur la ligne de sa visite, avec le message du pilote
     * (« Timed out receiving message from renderer »).
     */
    public const int SECONDES_POUR_CHARGER_UNE_PAGE = 60;

    /**
     * Le temps qu'un script exécuté dans la page a pour rendre sa valeur.
     *
     * La valeur par défaut du pilote, écrite ici pour que les bornes se lisent
     * au même endroit, et que celle des ordres reste au-dessus.
     */
    public const int SECONDES_POUR_UN_SCRIPT = 30;

    /**
     * Le temps qu'un ordre envoyé au pilote a pour recevoir sa réponse.
     *
     * php-webdriver en accorde cent quatre-vingts. Plus long que la page et
     * que le script, que le pilote borne lui-même : son message, qui nomme ce
     * qui a manqué, arrive le premier, et celui-ci ne parle que pour un ordre
     * dont le pilote ne rend jamais la réponse, comme un script, une recherche
     * d'élément ou la lecture de la console dans un rendu figé. Cet ordre lève
     * une `WebDriverCurlException`, et `PiloteDesParcours` le retient même
     * quand Dusk l'avale : le parcours tombe sur lui, ou sur l'échec que Dusk
     * en a tiré, en quatre-vingt-dix secondes, et rien ne lui est plus envoyé
     * que la fermeture.
     */
    public const int SECONDES_POUR_UN_ORDRE = 90;

    /**
     * Le temps qu'a un navigateur écarté pour confirmer sa fermeture.
     *
     * Le pilote traite les ordres d'une session l'un après l'autre : derrière
     * un ordre resté sans réponse, la fermeture attendrait son tour en vain,
     * SECONDES_POUR_UN_ORDRE durant. Elle reste envoyée, pour que le pilote
     * ferme le navigateur s'il reprend, mais sa réponse n'est attendue que
     * quelques secondes.
     */
    public const int SECONDES_POUR_FERMER_UN_NAVIGATEUR_MUET = 5;

    protected function setUp(): void
    {
        static::assertViteIsNotInDevMode();

        parent::setUp();

        Browser::macro('resizeToIphoneMini', fn (): object => $this->resize(375, 812));

        Browser::macro('resizeToIphone15', fn (): object => $this->resize(390, 844));

        Browser::macro('resizeToIphoneMax', fn (): object => $this->resize(430, 932));

        /**
         * Clicks once the element has actually stopped moving.
         *
         * `scrollIntoView` returns before the page has finished scrolling, so
         * the pattern it replaces — scroll, then click on the next line — aims
         * at a coordinate the button has already left. Selenium then refuses
         * with "element click intercepted: other element would receive the
         * click", naming whatever slid under the cursor. On this app that is
         * usually the heading of the card the previous step just added, which
         * is why the failure looked random and only ever hit the longest test.
         *
         * Two conditions, both of them the ones Selenium itself checks: the
         * element's box has to be in the same place twice running, and the
         * point at its centre has to resolve back to it. Waiting for that is
         * not a retry — the click only ever happens once, when it can land.
         *
         * Et un temoin verifie ensuite que le clic a bien atterri sur la cible.
         * Les deux conditions ci-dessus sont necessaires, pas suffisantes : en
         * CI, un clic a deja ete perdu sans qu'aucune exception ne soit levee
         * (#1503). Le test echouait alors dix secondes plus loin, sur une
         * attente d'etat, ce qui ne disait ni ou ni quand. Il echoue desormais
         * au clic, en nommant l'element reellement touche.
         */
        Browser::macro('clickWhenSettled', function (string $selector, int $seconds = 15): object {
            /** @var Browser $this */
            $probe = <<<'JS'
                const el = document.querySelector(SELECTOR);
                if (!el) { return null; }
                el.scrollIntoView({ block: 'center', behavior: 'instant' });
                const box = el.getBoundingClientRect();
                if (box.width === 0 || box.height === 0) { return null; }
                const x = box.left + box.width / 2;
                const y = box.top + box.height / 2;
                const hit = document.elementFromPoint(x, y);
                return {
                    top: Math.round(box.top),
                    left: Math.round(box.left),
                    onTop: !!hit && (hit === el || el.contains(hit)),
                };
            JS;

            $script = str_replace('SELECTOR', json_encode($selector, JSON_THROW_ON_ERROR), $probe);
            $previous = null;

            /*
             * Un temoin, pose AVANT la boucle d'attente.
             *
             * Un clic perdu ne leve rien : ni exception WebDriver, ni erreur
             * console. Le test echouait donc bien plus loin, sur une attente
             * d'etat, et l'enquete repartait de la scene dix secondes apres les
             * faits (#1503).
             *
             * Ce recepteur en phase de capture note ce que le navigateur a
             * REELLEMENT reçu : la cible, et si elle est bien le bouton vise.
             * Il est pose sur `document` en capture, donc rien ne peut
             * l'empecher de voir un clic qui atteint la page — meme un
             * gestionnaire qui appelle `stopPropagation()`.
             *
             * Avant la boucle, et non juste avant le clic : l'installer entre
             * la derniere sonde et le clic ajouterait un aller-retour WebDriver
             * dans l'intervalle meme qu'on soupconne. Personne d'autre que le
             * test ne clique pendant ce temps, donc le dernier clic vu est le
             * notre.
             */
            $temoin = <<<'JS'
                window.__dernierClic = null;
                if (window.__espionDeClic) {
                    document.removeEventListener('click', window.__espionDeClic, true);
                }
                window.__espionDeClic = (evenement) => {
                    const cible = evenement.target;
                    window.__dernierClic = {
                        balise: cible instanceof Element ? cible.tagName.toLowerCase() : String(cible),
                        dusk: cible instanceof Element ? (cible.closest('[dusk]')?.getAttribute('dusk') ?? null) : null,
                    };
                };
                document.addEventListener('click', window.__espionDeClic, true);
            JS;

            $this->script($temoin);

            $this->waitUsing($seconds, 100, function () use ($script, &$previous): bool {
                /** @var Browser $this */
                $now = $this->script($script)[0] ?? null;

                if (! is_array($now) || $now['onTop'] !== true) {
                    $previous = null;

                    return false;
                }

                $settled = $previous !== null
                    && $previous['top'] === $now['top']
                    && $previous['left'] === $now['left'];

                $previous = $now;

                return $settled;
            }, "[{$selector}] never stopped moving long enough to be clicked");

            $this->click($selector);

            /** @var array{balise: string, dusk: string|null}|null $recu */
            $recu = $this->script('return window.__dernierClic;')[0] ?? null;

            $vise = trim($selector, '[]');
            $vise = str_starts_with($vise, 'dusk=') ? trim(substr($vise, 5), '"\'') : null;

            if ($vise !== null && ($recu === null || $recu['dusk'] !== $vise)) {
                throw new RuntimeException(sprintf(
                    'Le clic sur [%s] n’a pas atteint sa cible : %s. La boite etait stable et '
                    .'`elementFromPoint` rendait bien ce bouton — c’est donc entre la sonde et le '
                    .'clic natif que le point a change de main. Voir #1503.',
                    $selector,
                    $recu === null
                        ? 'aucun evenement `click` n’a atteint le document'
                        : sprintf(
                            'il a atterri sur <%s>%s',
                            $recu['balise'],
                            $recu['dusk'] === null ? ', hors de tout element marque' : ", marque [dusk=\"{$recu['dusk']}\"]"
                        )
                ));
            }

            return $this;
        });

        /*
         * Une ligne optimiste porte `temp-…` jusqu'a la reponse du serveur ;
         * les tests l'attendaient par une pause. Le DOM le montre.
         */
        Browser::macro('waitForServerIds', function (int $seconds = 15): object {
            /** @var Browser $this */
            return $this->waitUntil(
                'Array.from(document.querySelectorAll("[data-line-id]")).every((el) => !String(el.dataset.lineId).startsWith("temp-"))',
                $seconds,
                'une ligne attend encore son identifiant serveur',
            );
        });

        /*
         * Les mesures de mise en page attendaient la fin du rendu par une
         * pause. Deux releves identiques de suite, polices chargees, le disent.
         */
        Browser::macro('waitForStableLayout', function (int $seconds = 10): object {
            /** @var Browser $this */
            $releve = 'return [document.fonts.status, document.documentElement.scrollWidth, document.documentElement.scrollHeight, document.getElementsByTagName("*").length].join();';
            $precedent = null;

            $this->waitUsing($seconds, 150, function () use ($releve, &$precedent): bool {
                /** @var Browser $this */
                $actuel = $this->script($releve)[0] ?? null;
                $stable = is_string($actuel) && $actuel === $precedent && str_starts_with($actuel, 'loaded');
                $precedent = $actuel;

                return $stable;
            }, 'la mise en page ne s\'est jamais stabilisee');

            return $this;
        });

        Browser::macro('disableAnimations', function (): object {
            /** @var Browser $this */
            $this->script("
                const style = document.createElement('style');
                style.type = 'text/css';
                style.innerHTML = '* { transition: none !important; animation: none !important; scroll-behavior: auto !important; }';
                document.head.appendChild(style);
            ");

            return $this;
        });

        /**
         * Picks a duration on the hh:mm:ss wheels.
         *
         * The wheels are scroll containers, and a flick is not something
         * WebDriver can perform — but each column is a spinbutton, so the
         * keyboard drives it exactly as assistive technology would. Home puts a
         * column at zero whatever it was showing, then PageDown counts by ten
         * and ArrowDown by one, which keeps this to a handful of keystrokes
         * rather than sixty.
         *
         * Unlike the <input type="time"> this replaced, the keys can be sent as
         * one burst: each keydown is handled on its own and nothing here depends
         * on the browser's segment-advance timing.
         */
        Browser::macro('pickDuration', function (string $selector, int $hours, int $minutes, int $seconds): object {
            /** @var Browser $this */
            $this->click($selector)->waitFor($selector.'-hours', 10);

            foreach (['hours' => $hours, 'minutes' => $minutes, 'seconds' => $seconds] as $part => $value) {
                $this->keys(
                    $selector.'-'.$part,
                    WebDriverKeys::HOME,
                    ...array_fill(0, intdiv($value, 10), WebDriverKeys::PAGE_DOWN),
                    ...array_fill(0, $value % 10, WebDriverKeys::ARROW_DOWN),
                );
            }

            return $this->click($selector.'-confirm')->waitUntilMissing($selector.'-hours', 10);
        });

        Browser::macro('assertNoConsoleExceptions', function (): object {
            /** @var Browser $this */
            /**
             * Three blanket suppressions used to sit here — 'Failed to send
             * logs', 'navigator.vibrate' and 'Cross-Origin-Opener-Policy'.
             * Counted across the 74 console transcripts one full run leaves
             * behind, each occurred exactly zero times, at any level. They were
             * also unreachable by construction: all three describe warnings,
             * and the filter below only ever sees SEVERE.
             *
             * They are gone rather than kept "just in case", because a
             * suppression nobody can point to an occurrence of is indistinguishable
             * from one that will quietly swallow a real error later. If CI
             * proves one is needed, it comes back with the transcript attached.
             */
            $logs = $this->driver->manage()->getLog('browser');
            $failures = collect($logs)->filter(
                fn ($log): bool => ($log['level'] ?? '') === 'SEVERE'
            )->reject(
                /**
                 * Chrome logs this at SEVERE, but it is the browser commenting
                 * on the transport rather than the application failing: over
                 * plain http the origin is not "potentially trustworthy", so
                 * the COOP header is moot and it says so once per navigation.
                 * CI serves https and never sees it; locally every Dusk test
                 * against laravel.test failed on it, which is a fast way to
                 * teach everyone that a red browser suite means nothing.
                 */
                static function (mixed $log): bool {
                    $message = is_array($log) ? ($log['message'] ?? '') : '';

                    return is_string($message)
                        && str_contains($message, 'Cross-Origin-Opener-Policy header has been ignored');
                }
            );

            \PHPUnit\Framework\Assert::assertTrue(
                $failures->isEmpty(),
                "Console exceptions found:\n".$failures->implode('message', "\n")
            );

            return $this;
        });
    }

    /**
     * Juge la configuration du parcours avant que les traits ne touchent à la base.
     *
     * `DatabaseTruncation` lance `migrate:fresh` dès le premier test, et sous
     * Sail `artisan dusk` visait la base de développement (#1909). Ici, et non
     * dans `beforeTruncatingDatabase()` : la méthode vide du trait, que porte
     * la classe de test, masquerait celle-ci. La base est celle de la connexion
     * par défaut, que `migrate:fresh` vide, et l'adresse celle que reçoit le
     * navigateur.
     *
     * @return array<mixed>
     */
    #[\Override]
    protected function setUpTraits(): array
    {
        GardeDesParcours::verifier(
            DB::connection()->getDatabaseName(),
            $this->baseUrl(),
            $this->urlDuPilote(),
        );

        return parent::setUpTraits();
    }

    /**
     * `npm run dev` writes public/hot, which points @vite at http://localhost:5173.
     * The browser driving these tests runs in the Selenium container, where
     * localhost is itself — so no asset ever loads and every test dies on a
     * 30-second wait for #main-content. Fail loudly instead.
     *
     * Appelée en tête de `setUp()`, avant que l'application ne démarre : le
     * chemin se résout donc depuis `__DIR__`, `base_path()` n'ayant encore
     * aucune application à interroger.
     */
    protected static function assertViteIsNotInDevMode(): void
    {
        if (! file_exists(dirname(__DIR__).'/public/hot')) {
            return;
        }

        throw new \RuntimeException(
            'public/hot exists: the Vite dev server is running, so assets resolve to '
            .'localhost:5173, which is unreachable from the Selenium container. '
            .'Stop `npm run dev` and run `vendor/bin/sail npm run build` before Dusk.'
        );
    }

    /**
     * Block until a condition on the database holds, or fail.
     *
     * The workout page writes through a debounced request, so tests used to sit
     * on a fixed ->pause() long enough for it to land. That is a guess: too short
     * and the assertion races the write on a slow runner, too long and every run
     * pays for it. Polling the outcome the test already asserts turns the guess
     * into a condition, and returns as soon as it is met.
     */
    protected function waitForDatabase(
        callable $condition,
        int $seconds = 15,
        string $message = 'the write never reached the database',
        ?callable $etatAuMomentDeLEchec = null,
    ): void {
        /** @var (callable(): string)|null $etatAuMomentDeLEchec */
        $deadline = microtime(true) + $seconds;

        do {
            if ($condition() === true) {
                return;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        /*
         * L'etat reel, releve au moment de l'echec.
         *
         * Une attente qui echoue dit seulement que la condition n'a jamais ete
         * vraie — pas ce que la base contenait a la place. Sur un defaut qui ne
         * se reproduit qu'en CI (#1489), cette difference est tout : « la valeur
         * n'est jamais arrivee » et « elle est arrivee puis a ete ecrasee » ne
         * se corrigent pas au meme endroit, et une capture d'ecran ne tranche
         * pas entre les deux.
         */
        $etat = $etatAuMomentDeLEchec === null ? '' : "\nEtat en base : ".$etatAuMomentDeLEchec();

        $this->fail(sprintf('Waited %d seconds: %s%s', $seconds, $message, $etat));
    }

    /**
     * L'adresse du pilote qui commande le navigateur : ChromeDriver sur la
     * machine (la CI), ou le Selenium de Sail.
     *
     * Une seule lecture pour `driver()` et pour la garde des parcours : la
     * garde juge ainsi l'adresse que le parcours emploiera vraiment.
     *
     * La suite ne démarre aucun pilote, elle se branche sur celui qui écoute
     * déjà : la CI lance ChromeDriver dans une étape à part du job
     * browser-shard, Sail fournit le conteneur selenium. Un démarrage de plus
     * ici ferait double emploi avec la CI (#1927).
     */
    protected function urlDuPilote(): string
    {
        $url = $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL');

        return is_string($url) && $url !== '' ? $url : 'http://127.0.0.1:9515';
    }

    /**
     * Mène un parcours, et rapporte son échec à lui, pas celui des relevés
     * qui le suivent.
     *
     * Le `browse()` de Dusk relève la capture d'écran et la source d'un
     * parcours tombé, puis la console dans son `finally`, avant de relancer
     * l'exception du parcours ; un relevé qui échoue à son tour lève la sienne,
     * qui part à la place. Le 08/10/2026, un navigateur qui ne rendait plus
     * rien a refusé sa capture en dix secondes : l'éclat a rapporté « Timed out
     * receiving message from renderer: 10.000 » sur l'appel de `browse()`, et
     * la cause du parcours est partie avec elle (#2029). Le même déroulé, à
     * ceci près qu'après un échec, un relevé impossible se signale à côté de
     * l'échec, qui reste celui que la suite rapporte. Sur un parcours réussi,
     * une console illisible échoue comme avant.
     *
     * Un navigateur dont le pilote ne répond plus n'est ni relevé ni rendu à
     * la classe (`rendreOuEcarterLesNavigateurs()`).
     *
     * Une copie du `browse()` de Dusk : `UnParcoursBloqueTombeViteTest` en
     * garde l'empreinte, et une montée de Dusk qui le change la fait relire.
     */
    #[\Override]
    public function browse(Closure $callback): void
    {
        $browsers = $this->createBrowsersFor($callback);

        try {
            $callback(...$browsers->all());
        } catch (Throwable $echecDuParcours) {
            $this->releverApresUnEchec($browsers);
            $this->rendreOuEcarterLesNavigateurs($browsers);

            throw $echecDuParcours;
        }

        try {
            $this->storeConsoleLogsFor($browsers);
        } finally {
            $this->rendreOuEcarterLesNavigateurs($browsers);
        }
    }

    /**
     * Le navigateur des parcours, dont les attentes tombent sur un ordre
     * resté sans réponse plutôt que sur leur délai.
     *
     * @param  RemoteWebDriver  $driver
     */
    #[\Override]
    protected function newBrowser($driver): NavigateurDesParcours
    {
        return new NavigateurDesParcours($driver);
    }

    /**
     * Relève la capture d'écran, la source et la console d'un parcours tombé,
     * tant que le pilote répond.
     *
     * Derrière un ordre resté sans réponse, chaque relevé attendrait
     * SECONDES_POUR_UN_ORDRE en vain, que l'ordre soit celui du parcours, que
     * Dusk l'ait avalé ou que ce soit celui d'un relevé précédent : aucun
     * n'est tenté.
     *
     * @param  Collection<array-key, mixed>  $browsers
     */
    private function releverApresUnEchec(Collection $browsers): void
    {
        $releves = [
            'capture d’écran' => function () use ($browsers): void {
                $this->captureFailuresFor($browsers);
            },
            'source de la page' => function () use ($browsers): void {
                $this->storeSourceLogsFor($browsers);
            },
            'console du navigateur' => function () use ($browsers): void {
                $this->storeConsoleLogsFor($browsers);
            },
        ];

        foreach ($releves as $quoi => $releve) {
            if ($this->ordresSansReponse($browsers) !== []) {
                return;
            }

            $this->tenterSansMasquerLEchec($quoi, $releve);
        }
    }

    /**
     * Les ordres restés sans réponse des pilotes de ces navigateurs.
     *
     * @param  Collection<array-key, mixed>  $browsers
     * @return list<UnexpectedResponseException>
     */
    private function ordresSansReponse(Collection $browsers): array
    {
        $ordres = [];

        foreach ($browsers as $navigateur) {
            $ordre = $navigateur instanceof Browser && $navigateur->driver instanceof PiloteDesParcours
                ? $navigateur->driver->ordreSansReponse()
                : null;

            if ($ordre !== null) {
                $ordres[] = $ordre;
            }
        }

        return $ordres;
    }

    /**
     * Rend les navigateurs à la classe pour son parcours suivant, ou les écarte
     * si leur pilote ne répond plus.
     *
     * Le `createBrowsersFor()` de Dusk ne démarre un navigateur que si la
     * classe n'en garde aucun : rendu à la classe, un navigateur muet fait
     * tomber chacun des parcours suivants, puis attendre sa fermeture en fin
     * de classe. Écarté, il reçoit sa fermeture, bornée par
     * SECONDES_POUR_FERMER_UN_NAVIGATEUR_MUET, et le parcours suivant en
     * démarre un neuf (#2029). Le signalement nomme l'ordre resté sans
     * réponse, que Dusk a pu avaler. Mesuré avec un vrai navigateur au rendu
     * figé, SECONDES_POUR_UN_ORDRE ramené à dix secondes : le parcours figé
     * prend son ordre sans réponse et cinq secondes de fermeture, le suivant
     * passe en moins d'une seconde.
     *
     * @param  Collection<array-key, mixed>  $browsers
     */
    private function rendreOuEcarterLesNavigateurs(Collection $browsers): void
    {
        $ordresSansReponse = $this->ordresSansReponse($browsers);

        if ($ordresSansReponse === []) {
            static::$browsers = $this->closeAllButPrimary($browsers);

            return;
        }

        static::$browsers = new Collection();

        $this->signaler(sprintf(
            "%s : le pilote ne répond plus (%s) ; il ne reçoit plus que la fermeture de ses navigateurs, écartés, et le parcours suivant en démarre un neuf (#2029).\n",
            $this->getCallerName(),
            implode(' ; ', array_map(
                static fn (UnexpectedResponseException $ordre): string => trim($ordre->getMessage()),
                $ordresSansReponse,
            )),
        ));

        $millisecondesPourFermer = static::SECONDES_POUR_FERMER_UN_NAVIGATEUR_MUET * 1000;

        foreach ($browsers as $navigateur) {
            if (! $navigateur instanceof Browser) {
                continue;
            }

            $this->tenterSansMasquerLEchec('fermeture du navigateur écarté', static function () use ($navigateur, $millisecondesPourFermer): void {
                $navigateur->driver->getCommandExecutor()->setRequestTimeout($millisecondesPourFermer);
                $navigateur->quit();
            });
        }
    }

    /**
     * Tente un relevé ou une fermeture ; s'il échoue, le signale sans lever.
     *
     * @param  Closure(): void  $ordre
     */
    private function tenterSansMasquerLEchec(string $quoi, Closure $ordre): void
    {
        try {
            $ordre();
        } catch (Throwable $impossible) {
            $this->signaler(sprintf(
                "%s : %s impossible (%s : %s). L’échec rapporté pour ce parcours est le sien, pas celui-ci (#2029).\n",
                $this->getCallerName(),
                $quoi,
                $impossible::class,
                trim($impossible->getMessage()),
            ));
        }
    }

    /**
     * Écrit un signalement sur la sortie d'erreur, que le journal du job garde
     * à côté de l'échec.
     */
    protected function signaler(string $signalement): void
    {
        fwrite(STDERR, $signalement);
    }

    /**
     * Les capacités demandées au navigateur, bornes d'attente comprises.
     */
    protected function capacitesDuNavigateur(): DesiredCapabilities
    {
        $options = new ChromeOptions()->addArguments(collect([
            '--window-size=393,852',
            '--user-agent=Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
            '--disable-blink-features=AutomationControlled',
            '--disable-infobars',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
            // Entry animations start at opacity:0 with staggered delays; without this the
            // app's reduced-motion rules stay dormant and assertions race the fade-in.
            '--force-prefers-reduced-motion',
        ])->unless($this->hasHeadlessDisabled(), fn (Collection $items) => $items->merge([
            '--disable-gpu',
            '--headless=new',
            '--no-sandbox',
            '--disable-dev-shm-usage',
            '--ignore-certificate-errors',
            '--window-size=393,852',
        ]))->all());

        return DesiredCapabilities::chrome()
            ->setCapability('goog:loggingPrefs', ['browser' => 'ALL'])
            ->setCapability('timeouts', [
                'pageLoad' => static::SECONDES_POUR_CHARGER_UNE_PAGE * 1000,
                'script' => static::SECONDES_POUR_UN_SCRIPT * 1000,
            ])
            ->setCapability(ChromeOptions::CAPABILITY, $options);
    }

    /**
     * Create the RemoteWebDriver instance.
     *
     * Chaque attente du pilote est bornée : la page, le script, et la réponse
     * à chaque ordre. Un parcours bloqué tombe ainsi sur la ligne qui
     * attendait, au lieu de tenir l'éclat jusqu'aux délais par défaut.
     * `PiloteDesParcours` retient l'ordre resté sans réponse.
     */
    #[\Override]
    protected function driver(): RemoteWebDriver
    {
        return PiloteDesParcours::create(
            $this->urlDuPilote(),
            $this->capacitesDuNavigateur(),
            request_timeout_in_ms: static::SECONDES_POUR_UN_ORDRE * 1000,
        );
    }
}
