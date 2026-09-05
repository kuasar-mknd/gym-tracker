<?php

declare(strict_types=1);

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\WebDriverKeys;
use Illuminate\Support\Collection;
use Laravel\Dusk\Browser;
use Laravel\Dusk\TestCase as BaseTestCase;
use RuntimeException;

abstract class DuskTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // In setUp rather than prepare(): PHPUnit 12 dropped docblock annotations,
        // so the @beforeClass hook never fires.
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
     * Prepare for Dusk test execution.
     *
     * @beforeClass
     */
    public static function prepare(): void
    {
        if (! static::runningInSail()) {
            static::startChromeDriver(['--port=9515']);
        }

        putenv('APP_ENV=testing');
        $_ENV['APP_ENV'] = 'testing';
    }

    /**
     * `npm run dev` writes public/hot, which points @vite at http://localhost:5173.
     * The browser driving these tests runs in the Selenium container, where
     * localhost is itself — so no asset ever loads and every test dies on a
     * 30-second wait for #main-content. Fail loudly instead.
     */
    protected static function assertViteIsNotInDevMode(): void
    {
        // Resolved from __DIR__, not base_path(): this runs in @beforeClass, before
        // the application container is booted.
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
     * Create the RemoteWebDriver instance.
     */
    #[\Override]
    protected function driver(): RemoteWebDriver
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

        return RemoteWebDriver::create(
            $_ENV['DUSK_DRIVER_URL'] ?? env('DUSK_DRIVER_URL') ?? 'http://127.0.0.1:9515',
            DesiredCapabilities::chrome()
                ->setCapability('goog:loggingPrefs', ['browser' => 'ALL'])
                ->setCapability(
                    ChromeOptions::CAPABILITY,
                    $options
                )
        );
    }
}
