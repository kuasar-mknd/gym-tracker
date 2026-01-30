<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Injury;
use Illuminate\Http\Request;
use Inertia\Inertia;

class InjuryController extends Controller
{
    public function index(): \Inertia\Response
    {
        $injuries = $this->user()->injuries()
            ->orderBy('occurred_at', 'desc')
            ->get()
            ->groupBy('status');

        return Inertia::render('Injuries/Index', [
            'injuries' => $injuries,
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'body_part' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'severity' => ['required', 'integer', 'min:1', 'max:10'],
            'status' => ['required', 'in:active,recovering,healed'],
            'occurred_at' => ['required', 'date'],
            'recovered_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->user()->injuries()->create($data);

        return redirect()->back();
    }

    public function update(Request $request, Injury $injury): \Illuminate\Http\RedirectResponse
    {
        if ($injury->user_id !== $this->user()->id) {
            abort(403);
        }

        $data = $request->validate([
            'body_part' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'severity' => ['required', 'integer', 'min:1', 'max:10'],
            'status' => ['required', 'in:active,recovering,healed'],
            'occurred_at' => ['required', 'date'],
            'recovered_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        $injury->update($data);

        return redirect()->back();
    }

    public function destroy(Injury $injury): \Illuminate\Http\RedirectResponse
    {
        if ($injury->user_id !== $this->user()->id) {
            abort(403);
        }

        $injury->delete();

        return redirect()->back();
    }
}
