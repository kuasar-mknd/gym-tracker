<?php

namespace Database\Seeders;

use App\Models\Exercise;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // 1. Exercices de Force (Machines / Poids)
        Exercise::create(['name' => 'Développé Couché', 'type' => 'strength', 'category' => 'Pectoraux']);
        Exercise::create(['name' => 'Presse à Cuisses', 'type' => 'strength', 'category' => 'Jambes']);
        Exercise::create(['name' => 'Tirage Vertical', 'type' => 'strength', 'category' => 'Dos']);

        // 2. Exercices Cardio
        Exercise::create(['name' => 'Tapis de Course', 'type' => 'cardio', 'category' => 'Cardio']);
        Exercise::create(['name' => 'Vélo Elliptique', 'type' => 'cardio', 'category' => 'Cardio']);

        // 3. Exercices au Temps (Gainage)
        Exercise::create(['name' => 'Planche Abdominale', 'type' => 'timed', 'category' => 'Abdos']);

        // 4. Badges / Achievements
        $this->call(\Database\Seeders\AchievementSeeder::class);
    }
}
