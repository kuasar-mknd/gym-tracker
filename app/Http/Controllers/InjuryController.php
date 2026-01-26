<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Injuries\CreateInjuryAction;
use App\Actions\Injuries\DeleteInjuryAction;
use App\Actions\Injuries\FetchInjuriesIndexAction;
use App\Actions\Injuries\UpdateInjuryAction;
use App\Http\Requests\StoreInjuryRequest;
use App\Http\Requests\UpdateInjuryRequest;
use App\Models\Injury;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class InjuryController extends Controller
{
    public function index(FetchInjuriesIndexAction $action): Response
    {
        return Inertia::render('Injuries/Index', [
            'injuries' => $action->execute($this->user()),
        ]);
    }

    public function store(StoreInjuryRequest $request, CreateInjuryAction $action): RedirectResponse
    {
        $action->execute($this->user(), $request->validated());

        return to_route('injuries.index');
    }

    public function update(UpdateInjuryRequest $request, Injury $injury, UpdateInjuryAction $action): RedirectResponse
    {
        abort_if($injury->user_id !== $this->user()->id, 403);

        $action->execute($injury, $request->validated());

        return to_route('injuries.index');
    }

    public function destroy(Injury $injury, DeleteInjuryAction $action): RedirectResponse
    {
        abort_if($injury->user_id !== $this->user()->id, 403);

        $action->execute($injury);

        return to_route('injuries.index');
    }
}
