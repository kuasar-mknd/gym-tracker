<?php

declare(strict_types=1);

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Collection;
use Laravel\Dusk\Browser;
use Laravel\Dusk\TestCase as BaseTestCase;

abstract class DuskTestCase extends BaseTestCase
{
    protected function setUp(): void
    {
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

        Browser::macro('assertNoConsoleExceptions', function (): object {
            /** @var Browser $this */
            $logs = $this->driver->manage()->getLog('browser');
            $failures = collect($logs)->filter(
                fn ($log): bool => ($log['level'] ?? '') === 'SEVERE' &&
                    ! str_contains((string) ($log['message'] ?? ''), 'Failed to send logs') &&
                    ! str_contains((string) ($log['message'] ?? ''), 'navigator.vibrate')
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
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions())->addArguments(collect([
            '--window-size=393,852',
            '--user-agent=Mozilla/5.0 (iPhone; CPU iPhone OS 17_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.4 Mobile/15E148 Safari/604.1',
            '--disable-blink-features=AutomationControlled',
            '--disable-infobars',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
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
