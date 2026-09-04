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
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized.
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException If the workout line is not found.
     */
    public function store(SetStoreRequest $request, StoreSetAction $action): SetResource
    {
        /** @var array{workout_line_id: int} $validated */
        $validated = $request->validated();

        // Carried in a header rather than the body: it names the attempt, not
        // the resource, and has no business in the validated payload.
        $validated['idempotency_key'] = $request->header('Idempotency-Key');

        // Un seul chemin : l'action cherche la ligne et vérifie le droit d'y
        // écrire ; le contrôleur le faisait une première fois, pour rien.
        $set = $action->execute($this->user(), $validated);

        return new SetResource($set->loadMissing('personalRecord'));
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to update the set.
     */
    public function update(SetUpdateRequest $request, Set $set): SetResource
    {
        $this->authorize('update', $set);

        $set->update($request->validated());

        // Bolt: Only clear volume-related stats for set updates
        $this->statsCache->clearVolumeStats($this->user());

        return new SetResource($set->loadMissing('personalRecord'));
    }

    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException If the user is not authorized to delete the set.
     */
    public function destroy(Set $set): \Illuminate\Http\Response
    {
        $this->authorize('delete', $set);

        $user = $this->user();
        $set->delete();

        // Bolt: Only clear volume-related stats for set deletions
        $this->statsCache->clearVolumeStats($user);

        return response()->noContent();
    }
}
