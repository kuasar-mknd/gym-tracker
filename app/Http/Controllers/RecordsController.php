<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Records\FetchRecordsAction;
use App\Models\PersonalRecord;
use Inertia\Inertia;

/**
 * Les records personnels du compte, exercice par exercice. La page ne fait que
 * les lire : ils s'écrivent à la validation d'une série (`PersonalRecordService`).
 */
class RecordsController extends Controller
{
    /**
     * @throws \Illuminate\Auth\Access\AuthorizationException Si l'utilisateur n'a pas le droit de consulter ses records.
     */
    public function index(FetchRecordsAction $records): \Inertia\Response
    {
        $this->authorize('viewAny', PersonalRecord::class);

        return Inertia::render('Records/Index', $records->execute($this->user()));
    }
}
