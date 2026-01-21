<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

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
