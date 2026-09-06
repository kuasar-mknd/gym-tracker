<?php

declare(strict_types=1);

namespace App\Providers\Filament;

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
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use ShuvroRoy\FilamentSpatieLaravelBackup\FilamentSpatieLaravelBackupPlugin;
use ShuvroRoy\FilamentSpatieLaravelHealth\FilamentSpatieLaravelHealthPlugin;

final class AdminPanelProvider extends PanelProvider
{
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
            ->favicon(asset('favicon.ico'))
            ->authGuard('admin')
            ->colors($this->getPanelColors())
            ->multiFactorAuthentication([AppAuthentication::make()]);
    }

    /** @return array<int, \Filament\Contracts\Plugin> */
    private function getPlugins(): array
    {
        return [
            FilamentShieldPlugin::make(),
            // La page relit le partage des sauvegardes à chaque sondage : une fois par
            // minute suffit, une archive met plus longtemps à se faire.
            FilamentSpatieLaravelBackupPlugin::make()->usingPolingInterval('60s'),
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
     * @return array<int, class-string>
     */
    private function getMiddleware(): array
    {
        return [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
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
