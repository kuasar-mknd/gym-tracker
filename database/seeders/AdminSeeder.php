<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class AdminSeeder extends Seeder
{
    public const string EMAIL = 'admin@gymtracker.app';

    public function run(): void
    {
        $password = config('app.admin_initial_password');

        if (! is_string($password) || $password === '') {
            throw new \RuntimeException(
                'ADMIN_INITIAL_PASSWORD est vide : le seeder ne cree pas d\'administrateur sans mot de passe choisi.'
            );
        }

        $role = config('filament-shield.super_admin.name', 'super_admin');

        if (! is_string($role)) {
            throw new \RuntimeException('Super admin role name must be a string');
        }

        // Un compte existant garde son mot de passe : relancer le seeder ne
        // doit jamais le remettre a la valeur de la variable.
        $admin = Admin::query()->where('email', self::EMAIL)->firstOr(fn (): Admin => Admin::query()->create([
            'name' => 'Admin',
            'email' => self::EMAIL,
            'password' => Hash::make($password),
        ]));

        Role::findOrCreate($role, 'admin');
        $admin->assignRole($role);
    }
}
