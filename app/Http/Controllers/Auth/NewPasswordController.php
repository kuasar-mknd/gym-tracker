<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\NewPasswordRequest;
use App\Models\User;
use App\Support\DureeDesReponsesDAuthentification;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
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
     * Tout refus dure aussi au moins le plancher commun des réponses
     * d'authentification (`DureeDesReponsesDAuthentification`), compte ou non :
     * le courtier ne vérifie l'empreinte du jeton (bcrypt) que pour un compte
     * qui a une demande récente. Une réussite répond sans attendre.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(NewPasswordRequest $request): RedirectResponse
    {
        $status = DureeDesReponsesDAuthentification::appeler(
            fn (Timebox $delai): string => $this->resetPassword(
                $request->validated(),
                $delai,
            ),
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
     * elle qui libère la réponse du plancher des refus. Le jeton est arrivé à
     * l'adresse du compte : un compte en attente de cette preuve s'ouvre
     * (`reprendreLeCompteEnAttente()`), sans être connecté (la page mène à la
     * connexion), et le titulaire d'une adresse qu'un autre a inscrite
     * reprend ainsi le compte.
     *
     * @param  array<string, mixed>  $credentials
     */
    private function resetPassword(array $credentials, Timebox $delai): string
    {
        /** @phpstan-ignore-next-line */
        return Password::reset(
            $credentials,
            function (User $user) use ($credentials, $delai): void {
                /** @var string $password */
                $password = $credentials['password'];

                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                if ($user->estEnAttente()) {
                    self::reprendreLeCompteEnAttente($user);
                }

                event(new PasswordReset($user));

                $delai->returnEarly();
            }
        );
    }

    /**
     * Ouvre le compte en attente que la réinitialisation vient de prouver
     * (`AttendLaPreuveDeSonAdresse`), dans une seule écriture. Son nom, saisi
     * à l'inscription, a pu l'être par un autre que le titulaire de la boîte :
     * n'importe qui tape l'adresse d'un autre. Il cède la partie locale de
     * l'adresse, que le lien reçu vient de prouver, comme
     * `ReprendreUnCompteEnAttente` lui préfère le nom du fournisseur.
     */
    private static function reprendreLeCompteEnAttente(User $user): void
    {
        $user->forceFill([
            'name' => Str::before($user->email, '@'),
            'email_verified_at' => $user->freshTimestamp(),
        ])->save();

        event(new Verified($user));
    }
}
