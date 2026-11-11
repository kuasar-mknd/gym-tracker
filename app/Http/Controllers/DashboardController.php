<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Dashboard\FetchDashboardDataAction;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * La première page après la connexion, donc celle dont le temps d'affichage se
 * remarque le plus : les compteurs et les listes récentes partent avec la
 * réponse, les graphiques suivent en prop différée.
 *
 * Les chiffres immédiats arrivent en fermetures : la requête de la prop
 * différée rejoue ce contrôleur, et ne les calcule plus (#2148, étape 3a).
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, FetchDashboardDataAction $fetchDashboardData): \Inertia\Response
    {
        $user = $this->user();

        return Inertia::render('Dashboard', [
            ...$fetchDashboardData->chiffresImmediats($user),
            // ⚡ Bolt : les graphiques apparentés tiennent en une seule prop
            // différée — moins de requêtes asynchrones, moins de requêtes SQL,
            // et un seul état de chargement à l'écran.
            'analyticalStats' => Inertia::defer(fn (): array => $fetchDashboardData->getAnalyticalStats($user)),
        ]);
    }
}
