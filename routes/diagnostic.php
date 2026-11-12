<?php

declare(strict_types=1);

use App\Http\Controllers\Diagnostic\DetailDUneExceptionController;
use App\Http\Controllers\Diagnostic\EtatController;
use App\Http\Controllers\Diagnostic\ListeDesExceptionsController;
use App\Http\Controllers\Diagnostic\OccurrencesDUneExceptionController;
use App\Http\Controllers\Diagnostic\SanteDuDiagnosticController;
use App\Http\Middleware\ConditionalCspHeaders;
use App\Http\Middleware\ExigeUnJetonDeDiagnostic;
use Illuminate\Support\Facades\Route;

/*
 * L'accès de diagnostic en lecture seule (#2032), chargé par `withRouting(then:)`
 * hors des groupes `web` et `api` : ni session, ni cookie, ni jeton CSRF. La garde
 * passe la première ; chaque route y déclare sa portée par son nom
 * (`ExigeUnJetonDeDiagnostic::PORTEES`).
 */
Route::middleware([ExigeUnJetonDeDiagnostic::class, ConditionalCspHeaders::class])
    ->prefix('diagnostic/v1')
    ->name('diagnostic.')
    ->group(function (): void {
        Route::get('etat', EtatController::class)->name('etat');
        Route::get('sante', SanteDuDiagnosticController::class)->name('sante');

        /*
         * Pas de liaison de modèle : l'identifiant est contraint ici, à dix-huit
         * chiffres au plus, sous la capacité d'un entier de la base.
         */
        Route::get('exceptions', ListeDesExceptionsController::class)->name('exceptions.index');
        Route::get('exceptions/{identifiant}', DetailDUneExceptionController::class)
            ->where('identifiant', '[1-9][0-9]{0,17}')
            ->name('exceptions.show');
        Route::get('exceptions/{identifiant}/occurrences', OccurrencesDUneExceptionController::class)
            ->where('identifiant', '[1-9][0-9]{0,17}')
            ->name('exceptions.occurrences');
    });
