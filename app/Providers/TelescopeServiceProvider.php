<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        if (! config('telescope.enabled')) {
            return;
        }

        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        $this->registerFilter();
    }

    /**
     * Register the Telescope filter.
     */
    protected function registerFilter(): void
    {
        Telescope::filter(fn (IncomingEntry $entry): bool => $this->shouldFilterEntry($entry));
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        if (env('APP_ENV') === 'local') {
            return;
        }

        Telescope::hideRequestParameters(['_token']);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', fn ($user): bool => in_array($user->email, []));
    }

    /**
     * Determine if the given entry should be filtered.
     */
    private function shouldFilterEntry(IncomingEntry $entry): bool
    {
        if (env('APP_ENV') === 'local') {
            return true;
        }

        return collect([
            $entry->isReportableException(),
            $entry->isFailedRequest(),
            $entry->isFailedJob(),
            $entry->isScheduledTask(),
            $entry->hasMonitoredTag(),
        ])->contains(true);
    }
}
