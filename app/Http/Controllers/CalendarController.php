<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Calendar\FetchCalendarEventsAction;
use App\Http\Requests\CalendarIndexRequest;
use Inertia\Inertia;

class CalendarController extends Controller
{
    /**
     * Le mois demandé, ou à défaut le mois courant.
     *
     * L'année et le mois par défaut se tirent d'une seule lecture de l'horloge :
     * avec une lecture chacun, le calendrier ouvert le soir du 31 décembre, quand
     * l'année changeait entre les deux, s'ouvrait sur janvier de l'année qui
     * s'achevait (#2033).
     */
    public function index(CalendarIndexRequest $request, FetchCalendarEventsAction $fetchCalendarEvents): \Inertia\Response
    {
        /** @var array{year?: int|string|null, month?: int|string|null} $donneesValidees */
        $donneesValidees = $request->validated();
        $maintenant = now();

        $year = isset($donneesValidees['year']) ? (int) $donneesValidees['year'] : $maintenant->year;
        $month = isset($donneesValidees['month']) ? (int) $donneesValidees['month'] : $maintenant->month;

        $data = $fetchCalendarEvents->execute($this->user(), $year, $month);

        return Inertia::render('Calendar/Index', [
            'year' => (int) $year,
            'month' => (int) $month,
            'workouts' => $data['workouts'],
            'journals' => $data['journals'],
        ]);
    }
}
