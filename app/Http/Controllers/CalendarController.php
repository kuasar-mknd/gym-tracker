<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Calendar\FetchCalendarEventsAction;
use Illuminate\Http\Request;
use Inertia\Inertia;

class CalendarController extends Controller
{
    public function index(Request $request, FetchCalendarEventsAction $fetchCalendarEvents): \Inertia\Response
    {
        $yearInput = $request->input('year');
        $monthInput = $request->input('month');

        $year = is_numeric($yearInput) ? (int) $yearInput : now()->year;
        $month = is_numeric($monthInput) ? (int) $monthInput : now()->month;

        $data = $fetchCalendarEvents->execute($this->user(), $year, $month);

        return Inertia::render('Calendar/Index', [
            'year' => (int) $year,
            'month' => (int) $month,
            'workouts' => $data['workouts'],
            'journals' => $data['journals'],
        ]);
    }
}
