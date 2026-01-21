<?php

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
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->default()
            ->path('backoffice')
            ->login()
            ->profile()
            ->authGuard('admin')
            ->colors(['primary' => Color::Violet])
            ->multiFactorAuthentication([AppAuthentication::make()])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets($this->getWidgets())
            ->middleware($this->getMiddleware())
            ->authMiddleware([Authenticate::class])
            ->plugins([FilamentShieldPlugin::make()])
            ->navigationItems([
                \Filament\Navigation\NavigationItem::make('Server Pulse')
                    ->url('/pulse', shouldOpenInNewTab: true)
                    ->icon('heroicon-o-presentation-chart-line')
                    ->group('System')
                    ->sort(100)
                    ->visible(fn (): bool => auth()->user()?->can('viewPulse')),
            ]);
    }

    /**
     * @return array<class-string<\Filament\Widgets\Widget>>
     */
    private function getWidgets(): array
    {
        return [
            AccountWidget::class,
            FilamentInfoWidget::class,
            \App\Filament\Widgets\StatsOverview::class,
            \App\Filament\Widgets\UserActivityChart::class,
            \App\Filament\Widgets\RecentUsersTable::class,
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
