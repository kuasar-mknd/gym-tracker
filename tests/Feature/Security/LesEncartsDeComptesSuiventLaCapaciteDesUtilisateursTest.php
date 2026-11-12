<?php

declare(strict_types=1);

use App\Filament\Widgets\RecentUsersTable;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\UserActivityChart;
use App\Models\Admin;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Tests\Support\FilamentAdminPanel;

it('cache les encarts de comptes à un administrateur sans la capacité de lister les utilisateurs', function (string $encart): void {
    /** @var class-string<Filament\Widgets\Widget> $encart */
    test()->actingAs(FilamentAdminPanel::admin(), 'admin');

    expect($encart::canView())->toBeFalse();
})->with([RecentUsersTable::class, UserActivityChart::class]);

it('montre les encarts de comptes à qui peut lister les utilisateurs', function (string $encart): void {
    /** @var class-string<Filament\Widgets\Widget> $encart */
    test()->actingAs(FilamentAdminPanel::admin(['ViewAny:User']), 'admin');

    expect($encart::canView())->toBeTrue();
})->with([RecentUsersTable::class, UserActivityChart::class]);

/**
 * Les libellés des tuiles que le panneau rend à cet administrateur.
 *
 * @return list<string>
 */
function tuilesDeStatsVuesPar(Admin $admin): array
{
    test()->actingAs($admin, 'admin');

    $encart = new class() extends StatsOverview
    {
        /**
         * @return list<string>
         */
        public function libelles(): array
        {
            return array_values(array_map(static function (Stat $tuile): string {
                $libelle = $tuile->getLabel();
                assert(is_string($libelle));

                return $libelle;
            }, $this->getStats()));
        }
    };

    return $encart->libelles();
}

it('laisse les deux tuiles sans compte à un administrateur sans la capacité de lister les utilisateurs', function (): void {
    test()->actingAs(FilamentAdminPanel::admin(), 'admin');

    expect(StatsOverview::canView())->toBeTrue()
        ->and(tuilesDeStatsVuesPar(FilamentAdminPanel::admin()))->toBe(['Séances du jour', 'Exercices communs']);
});

it('ajoute les tuiles de comptes à qui peut lister les utilisateurs', function (): void {
    expect(tuilesDeStatsVuesPar(FilamentAdminPanel::admin(['ViewAny:User'])))
        ->toBe(['Comptes', 'Nouveaux comptes (7 j)', 'Séances du jour', 'Exercices communs']);
});
