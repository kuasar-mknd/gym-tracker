<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;

abstract class Controller
{
    use AuthorizesRequests;

    /**
     * Get the authenticated user.
     *
     * @throws \RuntimeException
     */
    protected function user(): User
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user) {
            throw new \RuntimeException('User not authenticated');
        }

        return $user;
    }
}
