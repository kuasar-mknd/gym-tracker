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
        Admin::updateOrCreate(
            ['email' => 'admin@gymtracker.app'],
            [
                'name' => 'Admin',
                'password' => Hash::make('CHANGE_THIS_PASSWORD'),
            ]
        );
    }
}
