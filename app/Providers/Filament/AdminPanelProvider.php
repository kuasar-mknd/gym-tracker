<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Sauvegardes\PageDesSauvegardes;
use App\Http\Middleware\CleDesCopiesHorsLigne;
use App\Http\Middleware\ConditionalCspHeaders;
use App\Http\Middleware\OuvreLaSession;
use App\Support\AvatarDesInitiales;
use App\Support\ComptesVusParPulse;
use App\Support\Csp\Nonce\SigneLesScriptsEnLigneDesPaquets;
use App\Support\Csp\RetireLesRessourcesDistantesDesPaquets;
use BezhanSalleh\FilamentExceptions\FilamentExceptionsPlugin;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Laravel\Pulse\Contracts\ResolvesUsers;
use ShuvroRoy\FilamentSpatieLaravelBackup\FilamentSpatieLaravelBackupPlugin;
use ShuvroRoy\FilamentSpatieLaravelHealth\FilamentSpatieLaravelHealthPlugin;

final class AdminPanelProvider extends PanelProvider
{
    /**
     * Filament, filament-exceptions, et le lecteur de journaux et Pulse, qui
     * vivent sous le panneau, écrivent des scripts en ligne sans nonce, que la
     * CSP bloquerait : ils sont signés à la compilation de leurs gabarits, y
     * compris ceux qu'un gabarit recopie depuis du PHP (le JavaScript du détail
     * d'une exception, celui de Pulse) (#1920, #1922).
     *
     * Horizon, Pulse et Telescope chargent leur fonte, et Pulse des icônes,
     * depuis d'autres sites : ces balises sont retirées de leurs gabarits à la
     * compilation.
     */
    public function boot(): void
    {
        Blade::precompiler(new SigneLesScriptsEnLigneDesPaquets());
        Blade::precompiler(new RetireLesRessourcesDistantesDesPaquets());
    }

    /**
     * Les comptes de la carte « Usage » de Pulse portent l'avatar que
     * l'application dessine, comme l'administrateur du panneau, et non celui
     * qu'il prenait chez des tiers ; chacun, compte de l'application ou
     * administrateur, se cherche dans sa table. Pulse lie son résolveur par
     * `singletonIf` : celui-ci l'emporte, quel que soit l'ordre des
     * fournisseurs.
     */
    #[\Override]
    public function register(): void
    {
        parent::register();

        $this->app->singleton(ResolvesUsers::class, ComptesVusParPulse::class);
    }

    public function panel(Panel $panel): Panel
    {
        return $this->configurePanel($panel)
            ->discoverResources(
                in: app_path('Filament/Resources'),
                for: 'App\Filament\Resources'
            )
            ->discoverPages(
                in: app_path('Filament/Pages'),
                for: 'App\Filament\Pages'
            )
            ->pages([Dashboard::class])
            ->widgets($this->getWidgets())
            ->middleware($this->getMiddleware())
            ->authMiddleware([Authenticate::class])
            ->plugins($this->getPlugins())
            ->navigationItems($this->getNavigationItems());
    }

    private function configurePanel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->default()
            ->path('backoffice')
            ->login()
            ->profile()
            ->brandName('GymTracker')
            /*
             * Le logo de l'application et son nom, comme dans son en-tête :
             * sans `brandLogo`, le panneau n'écrivait que le nom.
             */
            ->brandLogo(fn (): Htmlable => new HtmlString(view('filament.marque')->render()))
            ->brandLogoHeight('2rem')
            ->favicon(asset('favicon.ico'))
            ->authGuard('admin')
            ->colors($this->getPanelColors())
            /*
             * Le panneau suivait `prefers-color-scheme` et offrait un sélecteur
             * clair/sombre/système, par simple défaut de Filament : personne ne
             * l'avait demandé, et c'est exactement le mécanisme qui a décidé du
             * retrait du mode sombre de l'application (#1580, #1806). Une seule
             * apparence, ici comme ailleurs.
             */
            ->darkMode(false)
            /*
             * Sans ce réglage, Filament prend l'avatar de l'administrateur chez
             * un service tiers, à qui chaque page du panneau envoyait les
             * initiales de son nom.
             */
            ->defaultAvatarProvider(AvatarDesInitiales::class)
            ->multiFactorAuthentication([AppAuthentication::make()]);
    }

    /** @return array<int, \Filament\Contracts\Plugin> */
    private function getPlugins(): array
    {
        return [
            FilamentShieldPlugin::make(),
            // La page relit le partage des sauvegardes à chaque sondage : une fois par
            // minute suffit, une archive met plus longtemps à se faire. Elle ne
            // s'ouvre qu'à qui peut faire quelque chose d'une sauvegarde : sans
            // porte, tout administrateur du panneau voyait la liste des archives,
            // leurs dates et leurs tailles. Sa page à nous sonde le dossier, dans
            // un délai borné, avant de le lister : un partage qui ne répond plus
            // aurait pris le travailleur d'Octane qui la sert (#1929).
            FilamentSpatieLaravelBackupPlugin::make()
                ->usingPage(PageDesSauvegardes::class)
                ->usingPollingInterval('60s')
                ->authorize(function (): bool {
                    /** @var \App\Models\Admin|null $user */
                    $user = auth('admin')->user();

                    return $user?->canAny(['create-backup', 'download-backup', 'delete-backup']) ?? false;
                }),
            // Trente jours d'exceptions suffisent à comprendre une panne ; au-delà,
            // `model:prune` les efface. L'intervalle est pris au démarrage, ce qui
            // convient au planificateur, lancé à neuf.
            FilamentExceptionsPlugin::make()
                ->navigationGroup('Système')
                ->navigationLabel('Exceptions')
                ->navigationSort(91)
                ->navigationBadge()
                ->modelPruneInterval(now()->subDays(30)),
            FilamentSpatieLaravelHealthPlugin::make()
                ->navigationGroup('Système')
                ->navigationLabel('Santé')
                ->navigationSort(90)
                ->authorize(function (): bool {
                    /** @var \App\Models\Admin|null $user */
                    $user = auth('admin')->user();

                    return $user?->can('view-health') ?? false;
                }),
        ];
    }

    /**
     * @return array<string, array<int, string>|string>
     */
    private function getPanelColors(): array
    {
        return [
            'primary' => Color::Amber,
            'gray' => Color::Slate,
            'danger' => Color::Rose,
            'success' => Color::Emerald,
            'warning' => Color::Orange,
            'info' => Color::Blue,
        ];
    }

    /**
     * @return array<class-string<\Filament\Widgets\Widget>>
     */
    private function getWidgets(): array
    {
        return [
            \App\Filament\Widgets\StatsOverview::class,
            \App\Filament\Widgets\UserActivityChart::class,
            \App\Filament\Widgets\RecentUsersTable::class,
        ];
    }

    /**
     * @return array<int, \Filament\Navigation\NavigationItem>
     */
    private function getNavigationItems(): array
    {
        $peut = static function (string $capacite): bool {
            /** @var \App\Models\Admin|null $user */
            $user = auth('admin')->user();

            return $user?->can($capacite) ?? false;
        };

        return [
            \Filament\Navigation\NavigationItem::make('Pulse Serveur')
                ->url('/backoffice/pulse', shouldOpenInNewTab: true)
                ->icon('heroicon-o-presentation-chart-line')
                ->group('Système')
                ->sort(100)
                ->visible(fn (): bool => $peut('viewPulse')),
            \Filament\Navigation\NavigationItem::make('Journaux')
                ->url('/backoffice/journaux', shouldOpenInNewTab: true)
                ->icon('heroicon-o-document-text')
                ->group('Système')
                ->sort(93)
                ->visible(fn (): bool => $peut('view-logs')),
            \Filament\Navigation\NavigationItem::make('Horizon')
                ->url('/horizon', shouldOpenInNewTab: true)
                ->icon('heroicon-o-queue-list')
                ->group('Système')
                ->sort(101)
                ->visible(fn (): bool => $peut('view-outils')),
            // Telescope ferme sa porte hors du poste de développement (viewTelescope
            // rend faux) : le lien n'a de sens qu'en local.
            \Filament\Navigation\NavigationItem::make('Telescope')
                ->url('/telescope', shouldOpenInNewTab: true)
                ->icon('heroicon-o-magnifying-glass-circle')
                ->group('Système')
                ->sort(102)
                ->visible(fn (): bool => app()->environment('local') && $peut('view-outils')),
        ];
    }

    /**
     * La pile du panneau ne passe pas par le groupe `web` : la CSP qu'il pose,
     * `ConditionalCspHeaders`, y est donc reprise, sans quoi le panneau, la
     * partie la plus sensible de l'application, répondait sans aucune CSP et le
     * nonce de ses scripts ne protégeait rien (#1920). Le nonce est tiré plus
     * haut, par `NonceCspParRequete`, en tête de la pile globale.
     *
     * Le témoin des copies hors ligne y est repris aussi (#2048) : la session
     * du panneau est celle de l'application, et la déconnexion du panneau la
     * vide, compte de l'application et clé des copies compris. Sa réponse doit
     * donc effacer le témoin, comme celle de l'application. Ailleurs dans le
     * panneau, la session tient un compte que personne n'y lit, et le témoin
     * reste tel quel. Juste après l'ouverture de la session, il passe devant
     * la vérification CSRF et voit son 419 ; l'ordre de priorité le garde
     * devant le `Authenticate` du panneau.
     *
     * La session s'y ouvre par `OuvreLaSession`, comme dans le groupe `web`
     * (#PR) : une requête du panneau qui a lu la session avant qu'une autre la
     * détruise (une déconnexion, de l'application ou du panneau) ne la fait
     * pas renaître, et ne rend pas au navigateur l'ancien cookie.
     *
     * @return array<int, class-string>
     */
    private function getMiddleware(): array
    {
        return [
            ConditionalCspHeaders::class,
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            OuvreLaSession::class,
            CleDesCopiesHorsLigne::class,
            AuthenticateSession::class,
            ShareErrorsFromSession::class,
            PreventRequestForgery::class,
            SubstituteBindings::class,
            DisableBladeIconComponents::class,
            DispatchServingFilamentEvent::class,
            \App\Http\Middleware\AdminRateLimiter::class,
            \App\Http\Middleware\IpWhitelist::class,
        ];
    }
}
