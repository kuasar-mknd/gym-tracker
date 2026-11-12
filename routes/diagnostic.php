<?php

declare(strict_types=1);

use App\Http\Controllers\Diagnostic\DetailDUneExceptionController;
use App\Http\Controllers\Diagnostic\DetailDUneTacheController;
use App\Http\Controllers\Diagnostic\DetailDUnGroupeDErreursNavigateurController;
use App\Http\Controllers\Diagnostic\EtatController;
use App\Http\Controllers\Diagnostic\ListeDesErreursNavigateurController;
use App\Http\Controllers\Diagnostic\ListeDesExceptionsController;
use App\Http\Controllers\Diagnostic\ListeDesFilesController;
use App\Http\Controllers\Diagnostic\ListeDesTachesController;
use App\Http\Controllers\Diagnostic\OccurrencesDUneExceptionController;
use App\Http\Controllers\Diagnostic\RefuseLesOptionsController;
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

        /*
         * Les tâches planifiées, sous la portée `sante` : même contrainte
         * d'identifiant, sans liaison de modèle.
         */
        Route::get('taches', ListeDesTachesController::class)->name('taches.index');
        Route::get('taches/{identifiant}', DetailDUneTacheController::class)
            ->where('identifiant', '[1-9][0-9]{0,17}')
            ->name('taches.show');

        /* Les files et les jobs échoués, sous la portée `Files` : aucun paramètre. */
        Route::get('files', ListeDesFilesController::class)->name('files.index');

        /*
         * Les erreurs du navigateur, sous la portée `ErreursNavigateur` : un
         * groupe se désigne par son empreinte, trente-deux chiffres
         * hexadécimaux en minuscules, sans liaison de modèle.
         */
        Route::get('erreurs-navigateur', ListeDesErreursNavigateurController::class)->name('erreurs-navigateur.index');
        Route::get('erreurs-navigateur/{empreinte}', DetailDUnGroupeDErreursNavigateurController::class)
            ->where('empreinte', '[0-9a-f]{32}')
            ->name('erreurs-navigateur.show');
    });

/*
 * Hors du groupe, donc sans nom ni garde : le routeur sert lui-même `OPTIONS`
 * sur une route réelle, avec `Allow`, avant la garde.
 */
Route::options('diagnostic/{chemin}', RefuseLesOptionsController::class)->where('chemin', '.+');
