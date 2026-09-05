<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

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
             * Unique against the database, not merely against this Faker
             * instance.
             *
             * `fake()->unique()` only remembers what it has drawn within one
             * Faker instance, and that instance is rebuilt per test — while the
             * unique constraint it is meant to satisfy lives in the database,
             * across the whole run. That mismatch is the defect, whatever the
             * rows happen to accumulate to.
             *
             * `safeEmail()` draws a name and a couple of digits at example.org,
             * which is a wide enough pool to look safe and narrow enough to
             * collide. Measured over 20 campaigns per size: 1 in 20 collide at
             * 200 draws, 6 at 500, 13 at 1000, 20 out of 20 at 2000. CI landed
             * on `Duplicate entry 'isaac23@example.org'` and failed a browser
             * suite that was otherwise green.
             *
             * The random suffix removes the pool. The address still reads like
             * one, which is what the fixture is for.
             */
            'email' => fake()->userName().'.'.Str::lower(Str::random(12)).'@example.org',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'avatar' => null,
            'current_streak' => 0,
            'longest_streak' => 0,
            'last_workout_at' => null,
            'default_rest_time' => 60,
            'provider' => null,
            'provider_id' => null,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
