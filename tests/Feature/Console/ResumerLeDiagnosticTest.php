<?php

declare(strict_types=1);

use App\Enums\PorteeDeDiagnostic;
use App\Models\ExceptionEnregistree;
use App\Services\Diagnostic\EnveloppeDeDiagnostic;
use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\ResumeDuDiagnostic;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/*
 * `php artisan diagnostic:resume [--depuis=…] [--json]` (#2032) : le même
 * JSON que la page « Aperçu du diagnostic », toutes sources comprises, sans
 * aucun texte libre.
 */

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-12 10:00:00');
    ExceptionEnregistree::query()->create([
        'type' => RuntimeException::class, 'code' => '0', 'message' => "boum\u{200B} pour jean@example.org", 'file' => base_path('app/Models/User.php'),
        'line' => 12, 'trace' => [], 'method' => 'GET', 'path' => '/',
    ]);
});

it('rend avec --json, sur une ligne, le résumé de toutes les sources, tel que le service le rend', function (): void {
    $attendu = new ResumeDuDiagnostic()(PorteeDeDiagnostic::SOURCES, FenetreDeDiagnostic::depuis(null, now()))->json();

    expect(Artisan::call('diagnostic:resume', ['--json' => true]))->toBe(0)
        ->and(Artisan::output())->toBe($attendu."\n")
        ->and(json_decode($attendu, true))->toMatchArray(['suivant' => null, 'note' => EnveloppeDeDiagnostic::NOTE])
        ->and(data_get(json_decode($attendu, true), 'donnees.portees'))->toBe(['sante', 'exceptions', 'files', 'erreurs-navigateur'])
        ->and($attendu)->not->toContain('boum');
});

it('met le JSON en forme sans --json, et dit combien de caractères invisibles il a retirés', function (): void {
    $attendu = new ResumeDuDiagnostic()(PorteeDeDiagnostic::SOURCES, FenetreDeDiagnostic::depuis(null, now()))->json(lisible: true);

    expect(Artisan::call('diagnostic:resume'))->toBe(0)
        ->and(Artisan::output())->toBe($attendu."\n0 caractère(s) invisible(s) retiré(s).\n");
});

it('lit depuis l’instant donné, ramené au fuseau et borné à trente jours', function (string $depuis, string $lu): void {
    Artisan::call('diagnostic:resume', ['--json' => true, '--depuis' => $depuis]);

    expect(data_get(json_decode(Artisan::output(), true), 'donnees.fenetre.depuis'))->toBe($lu);
})->with([
    'en UTC' => ['2026-10-10T06:30:00Z', '2026-10-10T08:30:00+02:00'],
    'trop loin' => ['2025-01-01', '2026-09-12T10:00:00+02:00'],
]);

it('refuse un --depuis qui n’est pas un instant ISO 8601, ou une date qui n’existe pas, sans rien rendre d’autre', function (string $depuis): void {
    expect(Artisan::call('diagnostic:resume', ['--depuis' => $depuis]))->toBe(2)
        ->and(Artisan::output())->toBe("« depuis » attend un instant ISO 8601, par exemple 2026-10-11T08:00:00+02:00.\n");
})->with([
    'un mot' => 'hier',
    'un treizième mois' => '2026-13-45',
    'un saut de ligne en queue' => "2026-10-10\n",
    'un 31 septembre' => '2026-09-31',
    'un 29 février d’une année commune' => '2026-02-29',
    'un jour zéro' => '2026-10-00',
    'une heure 24' => '2026-10-11T24:30',
    'une seconde 60' => '2026-10-11T23:59:60',
]);
