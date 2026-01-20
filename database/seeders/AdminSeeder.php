<?php

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
        $admin = Admin::updateOrCreate(
            ['email' => 'admin@gymtracker.app'],
            [
                'name' => 'Admin',
                'password' => Hash::make(config('app.admin_initial_password', 'CHANGE_THIS_PASSWORD')),
            ]
        );

        $admin->assignRole(config('filament-shield.super_admin.name', 'super_admin'));
    }
}
