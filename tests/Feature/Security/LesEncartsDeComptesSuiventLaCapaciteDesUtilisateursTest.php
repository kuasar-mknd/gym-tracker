<?php

declare(strict_types=1);

use App\Filament\Widgets\RecentUsersTable;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\UserActivityChart;
use Tests\Support\FilamentAdminPanel;

it('cache les encarts de comptes à un administrateur sans la capacité de lister les utilisateurs', function (string $encart): void {
    /** @var class-string<Filament\Widgets\Widget> $encart */
    test()->actingAs(FilamentAdminPanel::admin(), 'admin');

    expect($encart::canView())->toBeFalse();
})->with([RecentUsersTable::class, StatsOverview::class, UserActivityChart::class]);

it('montre les encarts de comptes à qui peut lister les utilisateurs', function (string $encart): void {
    /** @var class-string<Filament\Widgets\Widget> $encart */
    test()->actingAs(FilamentAdminPanel::admin(['ViewAny:User']), 'admin');

    expect($encart::canView())->toBeTrue();
})->with([RecentUsersTable::class, StatsOverview::class, UserActivityChart::class]);
