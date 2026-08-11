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
