<?php

declare(strict_types=1);

namespace App\Providers\Filament;

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
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use ShuvroRoy\FilamentSpatieLaravelBackup\FilamentSpatieLaravelBackupPlugin;

class AdminPanelProvider extends PanelProvider
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
            FilamentSpatieLaravelBackupPlugin::make(),
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
        return [
            \Filament\Navigation\NavigationItem::make('Pulse Serveur')
                ->url('/backoffice/pulse', shouldOpenInNewTab: true)
                ->icon('heroicon-o-presentation-chart-line')
                ->group('Système')
                ->sort(100)
                ->visible(function (): bool {
                    /** @var \App\Models\Admin|null $user */
                    $user = auth('admin')->user();

                    return $user?->can('viewPulse') ?? false;
                }),
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
            VerifyCsrfToken::class,
            SubstituteBindings::class,
            DisableBladeIconComponents::class,
            DispatchServingFilamentEvent::class,
            \App\Http\Middleware\AdminRateLimiter::class,
            \App\Http\Middleware\IpWhitelist::class,
        ];
    }
}
