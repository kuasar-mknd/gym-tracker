<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\CreateUserAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(RegisterRequest $request, CreateUserAction $createUser): RedirectResponse
    {
        /*
         * Le tableau est construit champ par champ plutot que passe tel quel.
         *
         * `CreateUserAction::execute()` attend un `array<string, string>`, et
         * `validated()` rend un `array<string, mixed>`. Elargir la signature de
         * l'action pour que les deux s'accordent la ferait taire sans rien
         * garantir ; la remplir explicitement lui donne bien trois chaines.
         */
        $user = $createUser->execute([
            'name' => $request->string('name')->toString(),
            'email' => $request->string('email')->toString(),
            'password' => $request->string('password')->toString(),
        ]);

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
