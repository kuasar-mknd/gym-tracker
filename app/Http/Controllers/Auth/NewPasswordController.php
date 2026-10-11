<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\NewPasswordRequest;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class NewPasswordController extends Controller
{
    /**
     * La durée de tout refus, en microsecondes (#1951).
     *
     * Elle doit dépasser le travail du refus le plus long : le courtier ne
     * vérifie l'empreinte du jeton (bcrypt, au coût de `BCRYPT_ROUNDS`) que
     * pour un compte qui a une demande récente, et cette vérification prend
     * à elle seule plus que la Timebox du courtier (200 ms,
     * `auth.timebox_duration`). Environ 0,25 s au coût 12 de la production,
     * le double à chaque unité de plus : passer `BCRYPT_ROUNDS` au-delà de 13
     * demande de relever ce plancher.
     */
    public const int DUREE_D_UN_REFUS_MICROSECONDES = 1_000_000;

    /**
     * Display the password reset view.
     */
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
        ]);
    }

    /**
     * Tout refus parle du jeton, même quand l'adresse n'a pas de compte : le
     * courtier rend alors `passwords.user`, dont le message dirait que
     * l'adresse est inconnue à quiconque essaie un jeton quelconque (#1951).
     * Tout refus dure aussi `DUREE_D_UN_REFUS_MICROSECONDES`, compte ou non ;
     * une réussite répond sans attendre.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(NewPasswordRequest $request): RedirectResponse
    {
        $status = new Timebox()->call(
            fn (Timebox $delai): string => $this->resetPassword(
                $request->validated(),
                $delai,
            ),
            self::DUREE_D_UN_REFUS_MICROSECONDES,
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', __($status));
        }

        throw ValidationException::withMessages([
            'email' => [trans(Password::INVALID_TOKEN)],
        ]);
    }

    /**
     * Le courtier n'appelle la fonction qu'une fois le jeton vérifié : c'est
     * elle qui libère la réponse du plancher des refus.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function resetPassword(array $credentials, Timebox $delai): string
    {
        /** @phpstan-ignore-next-line */
        return Password::reset(
            $credentials,
            function (\App\Models\User $user) use ($credentials, $delai): void {
                /** @var string $password */
                $password = $credentials['password'];

                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));

                $delai->returnEarly();
            }
        );
    }
}
