<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\HandleSocialCallbackAction;
use App\Exceptions\SocialAuthException;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class SocialAuthController extends Controller
{
    /**
     * Redirect the user to the provider authentication page.
     */
    public function redirect(string $provider): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        return Socialite::driver($provider)->redirect();
    }

    /**
     * Obtain the user information from the provider.
     */
    public function callback(HandleSocialCallbackAction $action, string $provider): \Symfony\Component\HttpFoundation\RedirectResponse
    {
        try {
            $user = $action->execute($provider);
        } catch (SocialAuthException $e) {
            return redirect()->route('login')->with('status', $e->getMessage());
        }

        Auth::login($user);

        return redirect()->intended(route('dashboard'));
    }
}
