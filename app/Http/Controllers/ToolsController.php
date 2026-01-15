<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class ToolsController extends Controller
{
    public function index()
    {
        return Inertia::render('Tools/Index');
    }

    public function oneRepMax()
    {
        return Inertia::render('Tools/OneRepMax');
    }
}
