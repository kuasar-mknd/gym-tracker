<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Admin;
use App\Models\User;
use App\Support\AvatarDesInitiales;
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

        $this->dessinerLesAvatars();
    }

    /**
     * L'avatar du compte d'une entrée, dans son détail : les initiales
     * dessinées par l'application (`AvatarDesInitiales`), comme au panneau et
     * dans Pulse.
     *
     * Sans ce rappel, Telescope prend cet avatar chez un service d'avatars
     * tiers, d'après l'empreinte de l'adresse du compte. La CSP le bloque,
     * une violation à chaque fois, sauf quand elle est coupée : en mode
     * débogage, tant que Vite sert à chaud. Telescope ne passe au rappel que le
     * numéro et l'adresse : le numéro seul désignerait aussi bien un compte de
     * l'application qu'un administrateur, et le nom se cherche donc sous le
     * numéro et l'adresse, dans l'une puis l'autre table. Un compte qu'aucune
     * ne retrouve reçoit une silhouette.
     */
    private function dessinerLesAvatars(): void
    {
        Telescope::avatar(static function (mixed $numero, mixed $adresse): string {
            $nom = User::query()->whereKey($numero)->where('email', $adresse)->value('name')
                ?? Admin::query()->whereKey($numero)->where('email', $adresse)->value('name');

            return app(AvatarDesInitiales::class)->pourLeNom(is_string($nom) ? $nom : '');
        });
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
