<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;

/**
 * Les calculateurs qui ne s'appuient sur aucune donnée enregistrée : rien à
 * lire, rien à autoriser, juste une page à rendre.
 */
class ToolsController extends Controller
{
    public function index(): \Inertia\Response
    {
        return Inertia::render('Tools/Index');
    }

    public function oneRepMax(): \Inertia\Response
    {
        return Inertia::render('Tools/OneRepMax');
    }
}
