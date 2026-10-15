<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ConnecterLeCompte;
use App\Http\Controllers\Controller;
use App\Support\LiensDesCourriels;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * Le lien d'ouverture d'un compte en attente (`LienDOuvertureDuCompte`),
 * suivi : il ne fait que marquer la session, puis mène à la connexion.
 *
 * La route est signée (`signed`) et réservée aux invités. Le contrôleur ne lit
 * pas la base : il range dans la session le compte et l'empreinte de
 * l'adresse que le lien nomme, avec une échéance, et c'est la connexion qui
 * suit, avec le bon mot de passe, qui ouvre le compte
 * (`ConnecterLeCompte`). La page répond la même chose quel que soit le
 * compte, ouvert, en attente ou effacé depuis.
 */
final class OuvrirLeCompteController extends Controller
{
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $request->session()->put(ConnecterLeCompte::MARQUE_DU_LIEN_SUIVI, [
            'compte' => (int) $id,
            'empreinte' => $hash,
            'expire' => Date::now()->addMinutes(LiensDesCourriels::minutesDeValiditeDUnLien())->getTimestamp(),
        ]);

        return redirect()->route('login')->with('status', __('auth.lien_d_ouverture_suivi'));
    }
}
