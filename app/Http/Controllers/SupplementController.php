<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Supplements\FetchSupplementsIndexAction;
use App\Http\Requests\SupplementStoreRequest;
use App\Http\Requests\SupplementUpdateRequest;
use App\Models\Supplement;
use Illuminate\Http\Request;
use Inertia\Inertia;

class SupplementController extends Controller
{
    public function index(FetchSupplementsIndexAction $fetchSupplementsIndexAction): \Inertia\Response
    {
        $this->authorize('viewAny', Supplement::class);

        $user = $this->user();

        return Inertia::render('Supplements/Index', $fetchSupplementsIndexAction->execute($user));
    }

    public function store(SupplementStoreRequest $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('create', Supplement::class);

        /** @var array{name: string, brand?: string|null, dosage?: string|null, servings_remaining: int, low_stock_threshold: int} $validated */
        $validated = $request->validated();

        Supplement::create(array_merge($validated, ['user_id' => $this->user()->id]));

        return redirect()->back()->with('success', 'Complément ajouté.');
    }

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException Si le complément n'est pas celui de l'utilisateur (403).
     */
    public function update(SupplementUpdateRequest $request, Supplement $supplement): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $supplement);

        /** @var array{name: string, brand?: string|null, dosage?: string|null, servings_remaining: int, low_stock_threshold: int} $validated */
        $validated = $request->validated();

        $supplement->update($validated);

        return redirect()->back()->with('success', 'Complément mis à jour.');
    }

    /**
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException Si le complément n'est pas celui de l'utilisateur (403).
     */
    public function destroy(Supplement $supplement): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('delete', $supplement);

        $supplement->delete();

        return redirect()->back()->with('success', 'Complément supprimé.');
    }

    /**
     * Enregistre une prise. Le relevé et le décompte du stock, qui ne descend
     * pas sous zéro, sont l'affaire de `ConsumeSupplementAction`.
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException Si le complément n'est pas celui de l'utilisateur (403).
     */
    public function consume(Request $request, Supplement $supplement, \App\Actions\Supplements\ConsumeSupplementAction $action): \Illuminate\Http\RedirectResponse
    {
        $this->authorize('update', $supplement);

        $action->execute($this->user(), $supplement);

        return redirect()->back()->with('success', 'Consommation enregistrée.');
    }
}
