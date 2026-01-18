<?php

namespace App\Http\Controllers;

use App\Actions\Calendar\FetchCalendarEventsAction;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CalendarController extends Controller
{
    public function index(Request $request, FetchCalendarEventsAction $fetchCalendarEvents)
    {
        $year = $request->input('year', now()->year);
        $month = $request->input('month', now()->month);

        $data = $fetchCalendarEvents->execute($request->user(), $year, $month);

        return Inertia::render('Calendar/Index', [
            'year' => (int) $year,
            'month' => (int) $month,
            'workouts' => $data['workouts'],
            'journals' => $data['journals'],
        ]);
    }
}
