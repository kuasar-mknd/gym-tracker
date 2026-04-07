<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\UpdatePasswordRequest;
use Illuminate\Http\RedirectResponse;

class PasswordController extends Controller
{
    /**
     * Update the user's password.
     *
     * @param  \App\Http\Requests\Auth\UpdatePasswordRequest  $request  The validated password update request.
     * @return \Illuminate\Http\RedirectResponse A redirect back to the previous page.
     */
    public function update(UpdatePasswordRequest $request): RedirectResponse
    {
        /** @var array{password: string} $validated */
        $validated = $request->validated();

        $this->user()->update([
            'password' => $validated['password'],
        ]);

        $request->clearRateLimiter();

        return back();
    }
}
