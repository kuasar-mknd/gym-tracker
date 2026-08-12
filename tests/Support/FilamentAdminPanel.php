<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Admin;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Scaffolding shared by every Filament panel test.
 *
 * Three things are needed before a Filament page can be mounted through
 * Livewire::test() in this application:
 *
 * 1. an Admin authenticated on the 'admin' guard (the panel's authGuard),
 * 2. the Shield permissions the resource policies check — the super_admin role
 *    is NOT a gate bypass here (config/filament-shield.php has
 *    super_admin.define_via_gate = false), so the permissions must really exist
 *    on the 'admin' guard and be granted,
 * 3. the 'admin' panel set as the current panel, because no panel middleware
 *    runs when a page component is mounted directly.
 */
final class FilamentAdminPanel
{
    /**
     * Create an Admin holding the given Shield permissions on the 'admin' guard
     * and make the 'admin' panel current.
     *
     * @param  array<int, string>  $permissions  e.g. ['ViewAny:User', 'Create:User']
     */
    public static function admin(array $permissions = []): Admin
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = Admin::factory()->create();

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'admin');
        }

        if ($permissions !== []) {
            $admin->givePermissionTo($permissions);
        }

        Filament::setCurrentPanel('admin');

        return $admin;
    }

    /**
     * The full CRUD permission set Shield generates for a resource model.
     *
     * @return array<int, string>
     */
    public static function crudPermissions(string $model): array
    {
        return [
            "ViewAny:{$model}",
            "View:{$model}",
            "Create:{$model}",
            "Update:{$model}",
            "Delete:{$model}",
            "DeleteAny:{$model}",
        ];
    }
}
