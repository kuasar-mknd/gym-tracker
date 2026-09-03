<?php

declare(strict_types=1);

use App\Models\Admin;
use Database\Seeders\AdminSeeder;
use Illuminate\Support\Facades\Hash;

/**
 * Le seeder livrait un super-administrateur au mot de passe par defaut
 * `CHANGE_THIS_PASSWORD`, sur un depot public, et le remettait a cette
 * valeur a chaque relance.
 */
it('refuse de creer l administrateur sans mot de passe choisi', function (): void {
    config(['app.admin_initial_password' => null]);

    expect(fn () => new AdminSeeder()->run())->toThrow(RuntimeException::class, 'ADMIN_INITIAL_PASSWORD');
    expect(Admin::query()->where('email', AdminSeeder::EMAIL)->exists())->toBeFalse();
});

it('refuse aussi un mot de passe vide', function (): void {
    config(['app.admin_initial_password' => '']);

    expect(fn () => new AdminSeeder()->run())->toThrow(RuntimeException::class);
});

it('cree l administrateur avec le mot de passe choisi et le role super_admin', function (): void {
    config(['app.admin_initial_password' => 'un-mot-de-passe-choisi']);

    $this->seed(AdminSeeder::class);

    $admin = Admin::query()->where('email', AdminSeeder::EMAIL)->firstOrFail();

    expect(Hash::check('un-mot-de-passe-choisi', $admin->password))->toBeTrue();
    expect($admin->hasRole('super_admin'))->toBeTrue();
});

it('ne reecrit jamais le mot de passe d un administrateur existant', function (): void {
    $admin = Admin::factory()->create(['email' => AdminSeeder::EMAIL, 'password' => Hash::make('ancien')]);
    config(['app.admin_initial_password' => 'nouveau']);

    $this->seed(AdminSeeder::class);

    $reactualise = Admin::query()->findOrFail($admin->id);

    expect(Hash::check('ancien', $reactualise->password))->toBeTrue();
    expect($reactualise->hasRole('super_admin'))->toBeTrue();
    expect(Admin::query()->where('email', AdminSeeder::EMAIL)->count())->toBe(1);
});
