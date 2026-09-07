<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    #[\Override]
    public function register(): void
    {
        if (config('telescope.enabled') !== true) {
            return;
        }

        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        $this->registerFilter();
    }

    protected function registerFilter(): void
    {
        Telescope::filter(fn (IncomingEntry $entry): bool => $this->shouldFilterEntry($entry));
    }

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
     * La porte de Telescope : qui y accède hors du poste de développement.
     */
    #[\Override]
    protected function gate(): void
    {
        /*
         * Personne, et c'est dit.
         *
         * La porte etait ecrite `in_array($user->email, [], true)`, sur une
         * liste vide : elle rendait toujours faux, mais avait l'air de chercher
         * quelque chose. Ce depot est public et n'a pas a porter d'adresse
         * personnelle — la reponse est donc « non », qu'on l'ecrive ainsi.
         *
         * En local, Telescope ne consulte pas cette porte ; en production, elle
         * ferme. Un auto-hebergeur qui veut y acceder change cette ligne, ce
         * qui est plus honnete qu'une liste vide qu'il croirait pouvoir remplir
         * sans rien changer d'autre.
         */
        Gate::define('viewTelescope', fn (): bool => false);
    }

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
