<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use Illuminate\Auth\Events\Registered;

final readonly class CreateUserAction
{
    public function __construct(private CreerLesPlaquesParDefautAction $plaquesParDefaut)
    {
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @param  array<string, string>  $input
     */
    public function execute(array $input): User
    {
        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        $this->plaquesParDefaut->execute($user);

        event(new Registered($user));

        return $user;
    }
}
