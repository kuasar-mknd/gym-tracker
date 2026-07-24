<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Inertia\Inertia;

/**
 * Controller for rendering standalone fitness tools.
 *
 * Provides access to various utility calculators and tools
 * that don't fall under a specific resource's CRUD operations.
 */
class ToolsController extends Controller
{
    /**
     * Display the main tools index page.
     *
     * Serves as a directory or landing page linking to the various
     * fitness calculators and utilities available to the user.
     *
     * @return \Inertia\Response The Inertia response rendering the 'Tools/Index' page.
     */
    public function index(): \Inertia\Response
    {
        return Inertia::render('Tools/Index');
    }

    /**
     * Display the One Rep Max (1RM) calculator tool.
     *
     * Renders a dedicated page allowing users to estimate their 1RM
     * for a given lift based on weight and reps completed.
     *
     * @return \Inertia\Response The Inertia response rendering the 'Tools/OneRepMax' page.
     */
    public function oneRepMax(): \Inertia\Response
    {
        return Inertia::render('Tools/OneRepMax');
    }
}
