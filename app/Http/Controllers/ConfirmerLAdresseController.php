<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\LiensDesCourriels;
use Illuminate\Auth\Events\Verified;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redirect;

/**
 * Le lien de confirmation d'une nouvelle adresse, suivi depuis une session du
 * compte (`LienDeChangementDAdresse`) : c'est ici, et seulement ici, que
 * l'adresse demandée depuis le profil devient celle du compte.
 *
 * La route est signée et réservée aux sessions ouvertes ; le lien nomme le
 * compte, qui doit être celui de la session (sinon 404, comme toute ressource
 * d'autrui), la nouvelle adresse, chiffrée (`LiensDesCourriels`), et
 * l'empreinte de l'adresse actuelle : un lien émis avant un autre changement
 * ne vaut plus. Une adresse qui ne se déchiffre pas refuse le lien comme une
 * signature fausse (`LiensDesCourriels::adresseDuLienDeConfirmation()`). Le
 * changement passe par l'enregistrement du compte, donc par
 * `SurveilleSonAdresse` (avis à la dernière adresse vérifiée) et
 * `OublieLaPreuveDeSaLiaison`, puis l'adresse,
 * que le lien vient de prouver, est vérifiée dans une écriture à part. Un
 * compte que l'inscription a ouvert sur cette adresse, jamais ouvert depuis,
 * s'efface d'abord, dans la même transaction, par `delete()` (ses traces avec
 * lui) : le lien suivi prouve la boîte, que son inscrit n'a jamais prouvée.
 * Une adresse prise entre-temps par un autre compte (l'index unique refuse)
 * laisse le compte tel qu'il était : seul le titulaire de la boîte, qui a reçu
 * le lien, l'apprend.
 */
final class ConfirmerLAdresseController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $compte = $this->user();

        if ($request->integer('compte') !== $compte->id) {
            abort(404);
        }

        $adresse = LiensDesCourriels::adresseDuLienDeConfirmation($request);

        if ($compte->email === $adresse) {
            return Redirect::route('profile.edit')->with('status', 'adresse-confirmee');
        }

        if (! hash_equals(sha1($compte->email), $request->string('actuelle')->toString())) {
            return Redirect::route('profile.edit')->with('status', 'lien-de-changement-d-adresse-perime');
        }

        try {
            DB::transaction(static function () use ($compte, $adresse): void {
                self::effacerLInscriptionJamaisOuverte($compte, $adresse);

                $compte->update(['email' => $adresse]);

                // La mise à jour vient de retirer la vérification : celle-ci écrit toujours.
                $compte->markEmailAsVerified();
                event(new Verified($compte));
            });
        } catch (UniqueConstraintViolationException) {
            return Redirect::route('profile.edit')->with('status', 'adresse-deja-prise');
        }

        return Redirect::route('profile.edit')->with('status', 'adresse-confirmee');
    }

    /**
     * Efface le compte que l'inscription a ouvert sur exactement cette
     * adresse et que rien n'a ouvert depuis : la base le trouve selon la
     * collation de l'index, l'égalité se décide octet par octet
     * (`.ai/rules/actions.md`). Un compte ouvert, lui, reste, et l'index
     * unique refuse alors le changement.
     */
    private static function effacerLInscriptionJamaisOuverte(User $compte, string $adresse): void
    {
        User::query()
            ->where('email', $adresse)
            ->whereKeyNot($compte->getKey())
            ->whereNotNull('en_attente_depuis')
            ->whereNull('email_verified_at')
            ->get()
            ->filter(static fn (User $inscription): bool => $inscription->email === $adresse)
            ->each(static function (User $inscription): void {
                $inscription->delete();
            });
    }
}
