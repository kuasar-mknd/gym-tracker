<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $password = config('app.admin_initial_password', 'CHANGE_THIS_PASSWORD');
        if (! is_string($password)) {
            throw new \RuntimeException('Admin password must be a string');
        }

        $admin = Admin::updateOrCreate(
            ['email' => 'admin@gymtracker.app'],
            [
                'name' => 'Admin',
                'password' => Hash::make($password),
            ]
        );

        $role = config('filament-shield.super_admin.name', 'super_admin');
        if (! is_string($role)) {
            throw new \RuntimeException('Super admin role name must be a string');
        }

        $admin->assignRole($role);
    }
}
