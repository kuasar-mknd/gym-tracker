<?php

declare(strict_types=1);

namespace App\Http\Controllers;

abstract class Controller
{
    use \Illuminate\Foundation\Auth\Access\AuthorizesRequests;

    /**
     * Get the authenticated user.
     *
     * @throws \RuntimeException
     */
    protected function user(): \App\Models\User
    {
        /** @var \App\Models\User|null $user */
        $user = \Illuminate\Support\Facades\Auth::user();

        if (! $user) {
            throw new \RuntimeException('User not authenticated');
        }

        return $user;
    }
}
