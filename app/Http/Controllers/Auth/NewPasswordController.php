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
use SensitiveParameter;

class NewPasswordController extends Controller
{
    /**
     * La page qu'ouvre le lien du courriel. Elle reçoit le jeton et l'adresse
     * chiffrée tels que le lien les porte, et les renverra avec le nouveau mot
     * de passe ; l'adresse n'y est ni déchiffrée ni affichée. La page ne
     * vérifie pas le jeton : afficher l'adresse la donnerait en clair à
     * quiconque ouvre un lien, même périmé, retrouvé dans un historique ou un
     * journal, et la rangerait dans l'historique du navigateur, que les pages
     * d'invité ne chiffrent pas. Un lien d'avant le chiffrement (`?email=` en
     * clair) ouvre la même page, sans adresse : la réinitialisation le refuse
     * comme un jeton faux.
     */
    public function create(Request $request): Response
    {
        $adresse = $request->query('adresse');

        return Inertia::render('Auth/ResetPassword', [
            'token' => $request->route('token'),
            'adresse' => is_string($adresse) ? $adresse : null,
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
     * Un lien dont l'adresse chiffrée manque ou ne se déchiffre pas
     * (`NewPasswordRequest::identifiantsDuLien()`) reçoit le même refus, sous
     * le même plancher, sans que le courtier soit appelé. Le refus se range
     * sous `token`, que la page affiche avec le chemin d'une nouvelle demande :
     * elle n'a plus de champ d'adresse.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(NewPasswordRequest $request): RedirectResponse
    {
        $status = DureeDesReponsesDAuthentification::appeler(
            fn (Timebox $delai): string => $this->resetPassword(
                $request->identifiantsDuLien(),
                $delai,
            ),
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', __($status));
        }

        throw ValidationException::withMessages([
            'token' => [trans(Password::INVALID_TOKEN)],
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
     * Sans identifiants (adresse du lien illisible), le refus est celui d'un
     * jeton faux. L'adresse déchiffrée n'est recopiée ni dans un journal ni
     * dans un message d'exception. Dans la pile d'une exception, c'est le
     * réglage de l'image qui la tait, `zend.exception_ignore_args = On`
     * (`docker/php-prod.ini`, tenu par `LesPilesNeGardentPasLeursArgumentsTest`) :
     * `Password::reset()` passe ces identifiants par la façade puis par le
     * gestionnaire des courtiers, deux cadres que rien ne marque, avant le
     * courtier, qui les marque. `#[SensitiveParameter]` ne tait que le cadre de
     * cette méthode : une ceinture, pas la protection. Appeler un courtier
     * nommé ôterait ces deux cadres, mais la garde de
     * `MotDePasseOublieMemeReponseTest` n'accorde à ce contrôleur que `reset`.
     *
     * @param  array{token: string, email: string, password: string, password_confirmation: string}|null  $credentials
     */
    private function resetPassword(#[SensitiveParameter] ?array $credentials, Timebox $delai): string
    {
        if ($credentials === null) {
            return Password::INVALID_TOKEN;
        }

        /** @phpstan-ignore-next-line */
        return Password::reset(
            $credentials,
            function (User $user) use ($credentials, $delai): void {
                $user->forceFill([
                    'password' => $credentials['password'],
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
