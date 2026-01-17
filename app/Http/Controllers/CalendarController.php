<?php

namespace App\Http\Controllers;

use App\Actions\Calendar\FetchCalendarEventsAction;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CalendarController extends Controller
{
    public function index(Request $request, FetchCalendarEventsAction $fetchEvents)
    {
        $year = (int) $request->input('year', now()->year);
        $month = (int) $request->input('month', now()->month);

        $events = $fetchEvents->execute($request->user(), $year, $month);

        return Inertia::render('Calendar/Index', [
            'year' => $year,
            'month' => $month,
            'workouts' => $events['workouts'],
            'journals' => $events['journals'],
        ]);
    }
}
