<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Calendar\FetchCalendarEventsAction;
use App\Http\Requests\CalendarIndexRequest;
use Inertia\Inertia;

class CalendarController extends Controller
{
    public function index(CalendarIndexRequest $request, FetchCalendarEventsAction $fetchCalendarEvents): \Inertia\Response
    {
        /** @var array{year?: int|string|null, month?: int|string|null} $donneesValidees */
        $donneesValidees = $request->validated();

        $year = isset($donneesValidees['year']) ? (int) $donneesValidees['year'] : now()->year;
        $month = isset($donneesValidees['month']) ? (int) $donneesValidees['month'] : now()->month;

        $data = $fetchCalendarEvents->execute($this->user(), $year, $month);

        return Inertia::render('Calendar/Index', [
            'year' => (int) $year,
            'month' => (int) $month,
            'workouts' => $data['workouts'],
            'journals' => $data['journals'],
        ]);
    }
}
