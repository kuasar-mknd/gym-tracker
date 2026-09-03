<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * Meme piege que pour la seance en cours : `Cache::remember()` ne sert jamais
 * un `null`, donc l'etat courant de tout compte a jour — aucun trophee non lu —
 * relançait la requete a chaque requete web.
 */
it('met en cache l’absence de trophee non lu', function (): void {
    $user = User::factory()->create();
    $service = app(NotificationService::class);

    expect($service->getLatestAchievement($user))->toBeNull();

    DB::flushQueryLog();
    DB::enableQueryLog();
    expect($service->getLatestAchievement($user))->toBeNull();
    $requetes = DB::getQueryLog();
    DB::disableQueryLog();

    expect($requetes)->toBeEmpty();
});
