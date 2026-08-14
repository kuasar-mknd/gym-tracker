<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Admin>
 */
class AdminFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            /*
             * Unique contre la base, pas seulement contre cette instance Faker.
             *
             * Meme defaut que celui corrige sur UserFactory en #1425, et non
             * corrige ici : `fake()->unique()` ne memorise que ce qu'il a tire
             * dans une instance, reconstruite a chaque test, alors que la
             * contrainte d'unicite vit dans la base, sur toute l'execution.
             *
             * `safeEmail()` tire un prenom et deux chiffres a example.org : un
             * vivier assez large pour paraitre sur et assez etroit pour entrer
             * en collision. Mesure sur ce tirage-ci, instance reconstruite a
             * chaque fois comme entre deux tests, 20 campagnes par taille :
             * 1 campagne sur 20 collisionne a 200 tirages, 2 a 500, 9 a 1000,
             * et 19 sur 20 a 2000.
             *
             * C'est ce qui rendait `pest --mutate` inutilisable (#1366) : la
             * mutation rejoue la suite en boucle dans un meme processus, donc
             * les lignes s'accumulent et le vivier finit toujours par se
             * refermer. La pile remontait bien a Admin::factory()->create().
             */
            'email' => fake()->userName().'.'.Str::lower(Str::random(12)).'@example.org',
            'email_verified_at' => now(),
            'password' => 'password',
            'remember_token' => str()->random(10),
        ];
    }
}
