<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ErreurNavigateurStoreRequest;
use App\Models\ErreurNavigateur;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Le point de collecte des erreurs du navigateur : la page rapporte, ici on
 * garde, le panneau lit. Rien ne repart chez un tiers.
 *
 * Une même erreur (même empreinte) d'un même compte ne s'écrit qu'une fois
 * par fenêtre (`ErreurNavigateur::SECONDES_DU_REGROUPEMENT`) : une clé de
 * cache posée de façon atomique (`Cache::add()`) la retient, et le rapport
 * suivant répond 204 sans rien écrire ; une écriture que la base refuse
 * rend la clé, et le rapport suivant l'écrira. Le plafond par compte est
 * celui du limiteur de la route (`ErreurNavigateur::LIMITEUR`).
 */
class ErreurNavigateurController extends Controller
{
    public function store(ErreurNavigateurStoreRequest $request): Response
    {
        /** @var array{type: string, message: string, source?: string|null, ligne?: int|null, colonne?: int|null, pile?: string|null, url: string, agent?: string|null} $donnees */
        $donnees = $request->validated();
        $compte = $this->user()->id;
        $empreinte = ErreurNavigateur::empreinteDe(
            $donnees['type'],
            $donnees['message'],
            $donnees['source'] ?? null,
            $donnees['ligne'] ?? null,
        );
        $cle = ErreurNavigateur::cleDuRegroupement($compte, $empreinte);

        if (! Cache::add($cle, true, ErreurNavigateur::SECONDES_DU_REGROUPEMENT)) {
            return response()->noContent();
        }

        try {
            ErreurNavigateur::query()->create([
                ...$donnees,
                'user_id' => $compte,
                'empreinte' => $empreinte,
            ]);
        } catch (Throwable $erreur) {
            Cache::forget($cle);

            throw $erreur;
        }

        return response()->noContent();
    }
}
