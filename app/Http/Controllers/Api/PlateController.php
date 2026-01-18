<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\StorePlateRequest;
use App\Http\Requests\UpdatePlateRequest;
use App\Http\Resources\PlateResource;
use App\Models\Plate;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Spatie\QueryBuilder\QueryBuilder;

class PlateController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $this->authorize('viewAny', Plate::class);

        $plates = QueryBuilder::for(Plate::class)
            ->allowedSorts(['weight', 'quantity', 'created_at'])
            ->defaultSort('weight')
            ->where('user_id', Auth::id())
            ->paginate();

        return PlateResource::collection($plates);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePlateRequest $request)
    {
        $validated = $request->validated();

        $plate = new Plate($validated);
        $plate->user_id = Auth::id();
        $plate->save();

        return new PlateResource($plate);
    }

    /**
     * Display the specified resource.
     */
    public function show(Plate $plate)
    {
        $this->authorize('view', $plate);

        return new PlateResource($plate);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePlateRequest $request, Plate $plate)
    {
        $this->authorize('update', $plate);

        $plate->update($request->validated());

        return new PlateResource($plate);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Plate $plate)
    {
        $this->authorize('delete', $plate);

        $plate->delete();

        return response()->noContent();
    }
}
