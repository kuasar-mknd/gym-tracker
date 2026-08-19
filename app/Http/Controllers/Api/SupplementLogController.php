<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Supplements\FetchSupplementLogsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSupplementLogRequest;
use App\Http\Requests\UpdateSupplementLogRequest;
use App\Http\Resources\SupplementLogResource;
use App\Models\SupplementLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SupplementLogController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request, FetchSupplementLogsAction $fetchSupplementLogs): AnonymousResourceCollection
    {
        $this->authorize('viewAny', SupplementLog::class);

        /**
         * La forme est declaree, pas supposee : `validate()` rend un `array`
         * non type, donc lire une cle dessus etait un acces sur du `mixed`. La
         * regle juste en dessous garantit exactement cette forme.
         *
         * `int|numeric-string` et non `int` : la regle `integer` de Laravel
         * VALIDE sans convertir, donc une valeur passee en parametre d'URL
         * arrive en chaine. Declarer `int` seul etait un docblock qui ment, et
         * retirer le cast qu'il rendait « inutile » faisait repondre 500.
         *
         * @var array{per_page?: int|numeric-string} $validated
         */
        $validated = $request->validate([
            'per_page' => 'sometimes|integer|min:1|max:100',
        ]);

        $perPage = (int) ($validated['per_page'] ?? 15);

        $logs = $fetchSupplementLogs->execute($this->user(), $perPage);

        return SupplementLogResource::collection($logs);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreSupplementLogRequest $request): SupplementLogResource
    {
        $this->authorize('create', SupplementLog::class);

        $log = $this->user()->supplementLogs()->create($request->validated());

        return new SupplementLogResource($log);
    }

    /**
     * Display the specified resource.
     */
    public function show(SupplementLog $supplementLog): SupplementLogResource
    {
        $this->authorize('view', $supplementLog);

        return new SupplementLogResource($supplementLog);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdateSupplementLogRequest $request, SupplementLog $supplementLog): SupplementLogResource
    {
        $this->authorize('update', $supplementLog);

        $supplementLog->update($request->validated());

        return new SupplementLogResource($supplementLog);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(SupplementLog $supplementLog): JsonResponse
    {
        $this->authorize('delete', $supplementLog);

        $supplementLog->delete();

        return response()->json(null, 204);
    }
}
