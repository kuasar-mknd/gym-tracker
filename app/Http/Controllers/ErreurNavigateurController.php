<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\ErreurNavigateurStoreRequest;
use App\Models\ErreurNavigateur;
use Illuminate\Http\Response;

/**
 * Le point de collecte des erreurs du navigateur : la page rapporte, ici on
 * garde, le panneau lit. Rien ne repart chez un tiers.
 */
class ErreurNavigateurController extends Controller
{
    public function store(ErreurNavigateurStoreRequest $request): Response
    {
        /** @var array{type: string, message: string, source?: string|null, ligne?: int|null, colonne?: int|null, pile?: string|null, url: string, agent?: string|null} $donnees */
        $donnees = $request->validated();

        ErreurNavigateur::query()->create([
            ...$donnees,
            'user_id' => $this->user()->id,
            'empreinte' => ErreurNavigateur::empreinteDe(
                $donnees['type'],
                $donnees['message'],
                $donnees['source'] ?? null,
                $donnees['ligne'] ?? null,
            ),
        ]);

        return response()->noContent();
    }
}
