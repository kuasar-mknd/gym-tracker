<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    #[\Override]
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
        if (config('app.env') === 'local') {
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
    #[\Override]
    protected function gate(): void
    {
        Gate::define('viewTelescope', fn (User $user): bool => in_array($user->email, [], true));
    }

    /**
     * Determine if the given entry should be filtered.
     */
    private function shouldFilterEntry(IncomingEntry $entry): bool
    {
        if (config('app.env') === 'local') {
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
