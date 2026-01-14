<?php

namespace Database\Seeders;

use App\Models\Achievement;
use Illuminate\Database\Seeder;

class AchievementSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $achievements = [
            [
                'slug' => 'first-workout',
                'name' => 'Première Séance',
                'description' => 'Tu as complété ta toute première séance d\'entraînement !',
                'icon' => '🎉',
                'type' => 'count',
                'threshold' => 1,
                'category' => 'consistency',
            ],
            [
                'slug' => 'week-warrior',
                'name' => 'Guerrier de la Semaine',
                'description' => 'Tu as complété 3 séances d\'entraînement.',
                'icon' => '⚔️',
                'type' => 'count',
                'threshold' => 3,
                'category' => 'consistency',
            ],
            [
                'slug' => 'consistency-king',
                'name' => 'Roi de la Constance',
                'description' => 'Tu as complété 10 séances d\'entraînement.',
                'icon' => '👑',
                'type' => 'count',
                'threshold' => 10,
                'category' => 'consistency',
            ],
            [
                'slug' => 'heavy-lifter-100',
                'name' => 'Heavy Lifter (100kg)',
                'description' => 'Tu as soulevé 100kg ou plus sur un exercice.',
                'icon' => '🏋️',
                'type' => 'weight_record',
                'threshold' => 100,
                'category' => 'strength',
            ],
            [
                'slug' => 'heavy-lifter-140',
                'name' => 'Elite Lifter (140kg)',
                'description' => 'Tu as soulevé 140kg ou plus sur un exercice.',
                'icon' => '🔥',
                'type' => 'weight_record',
                'threshold' => 140,
                'category' => 'strength',
            ],
            [
                'slug' => 'volume-novice',
                'name' => 'Novice du Volume',
                'description' => 'Tu as soulevé un total de 5 000 kg au cumulé.',
                'icon' => '📦',
                'type' => 'volume_total',
                'threshold' => 5000,
                'category' => 'volume',
            ],
            [
                'slug' => 'volume-master',
                'name' => 'Maître du Volume',
                'description' => 'Tu as soulevé un total de 50 000 kg au cumulé.',
                'icon' => '🏢',
                'type' => 'volume_total',
                'threshold' => 50000,
                'category' => 'volume',
            ],
            [
                'slug' => 'streak-3',
                'name' => 'Série de 3',
                'description' => 'Tu as travaillé 3 jours consécutifs.',
                'icon' => '🔥',
                'type' => 'streak',
                'threshold' => 3,
                'category' => 'consistency',
            ],
        ];

        foreach ($achievements as $achievement) {
            Achievement::updateOrCreate(['slug' => $achievement['slug']], $achievement);
        }
    }
}
