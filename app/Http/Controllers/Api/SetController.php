<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Workouts\StoreSetAction;
use App\Http\Requests\Api\SetStoreRequest;
use App\Http\Requests\Api\SetUpdateRequest;
use App\Http\Resources\SetResource;
use App\Models\Set;
use App\Services\Stats\StatsCacheManager;

/**
 * Les trois écritures de série que la page de séance fait en direct.
 */
class SetController extends Controller
{
    public function __construct(
        protected StatsCacheManager $statsCache
    ) {
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'utilisateur n'y est pas autorisé.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Si la ligne d'exercice n'existe pas.
     */
    public function store(SetStoreRequest $request, StoreSetAction $action): SetResource
    {
        /** @var array{workout_line_id: int} $validated */
        $validated = $request->validated();

        // Transmise dans un en-tête plutôt que dans le corps : elle nomme la
        // tentative, pas la ressource, et n'a rien à faire dans le payload validé.
        $validated['idempotency_key'] = $request->header('Idempotency-Key');

        // Un seul chemin : l'action cherche la ligne et vérifie le droit d'y
        // écrire ; le contrôleur le faisait une première fois, pour rien.
        $set = $action->execute($this->user(), $validated);

        return new SetResource($set->loadMissing('personalRecord'));
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'utilisateur n'a pas le droit de modifier la série.
     */
    public function update(SetUpdateRequest $request, Set $set): SetResource
    {
        $this->authorize('update', $set);

        $set->update($request->validated());

        // Modifier une série ne bouge que le volume : inutile de vider le reste du cache.
        $this->statsCache->clearVolumeStats($this->user());

        return new SetResource($set->loadMissing('personalRecord'));
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'utilisateur n'a pas le droit de supprimer la série.
     */
    public function destroy(Set $set): \Illuminate\Http\Response
    {
        $this->authorize('delete', $set);

        $user = $this->user();
        $set->delete();

        // Supprimer une série ne bouge que le volume : inutile de vider le reste du cache.
        $this->statsCache->clearVolumeStats($user);

        return response()->noContent();
    }
}
