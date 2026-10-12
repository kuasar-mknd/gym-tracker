<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Jobs\TraiterUneInscription;
use App\Support\DureeDesReponsesDAuthentification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Hash;
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
     * Répond la même chose, dans le même temps, à toute inscription bien
     * formée, que l'adresse ait déjà un compte ou non : la page de connexion,
     * avec un message écrit pour les deux cas, et personne de connecté.
     *
     * La requête ne cherche pas le compte. Elle hache le mot de passe, ce que
     * les deux cas paient, et met `TraiterUneInscription` en file, dans le
     * plancher commun des réponses d'authentification
     * (`DureeDesReponsesDAuthentification`). Le job crée le compte en attente
     * et lui envoie le lien qui l'ouvre, ou prévient le titulaire de l'adresse
     * prise. Le compte ne se connecte qu'une fois son adresse prouvée.
     */
    public function store(RegisterRequest $request): RedirectResponse
    {
        $adresse = $request->string('email')->toString();
        $nom = $request->string('name')->toString();
        $motDePasse = $request->string('password')->toString();
        $langue = app()->getLocale();

        DureeDesReponsesDAuthentification::appeler(static function () use ($adresse, $nom, $motDePasse, $langue): void {
            TraiterUneInscription::dispatch($adresse, $nom, Hash::make($motDePasse), $langue);
        });

        return redirect()->route('login')->with('status', __('auth.inscription_recue'));
    }
}
