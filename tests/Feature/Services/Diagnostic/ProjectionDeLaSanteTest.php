<?php

declare(strict_types=1);

use App\Services\Diagnostic\FenetreDeDiagnostic;
use App\Services\Diagnostic\Projections\ProjectionDeLaSante;
use App\Services\Diagnostic\Projections\ProjectionDesVersions;
use App\Services\Diagnostic\TexteNonFiable;
use App\Support\Sante\AnnonceDeVersion;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResult;
use Spatie\Health\ResultStores\StoredCheckResults\StoredCheckResults;

/*
 * La santé et les versions vues par le diagnostic (#2032) : le dernier
 * passage des contrôles, lu dans le magasin et jamais relancé, sans `meta` ;
 * le texte des contrôles au détail, sous `TexteBrut` seulement ; ce que
 * chaque conteneur annonce de son image.
 */

/**
 * Range dans le magasin un passage des contrôles, fini à l'instant donné.
 */
function santeDiagnosticPassage(string $fini, StoredCheckResult ...$controles): void
{
    Cache::forever('health:storeResults', new StoredCheckResults(Carbon::parse($fini)->toDateTime(), collect(array_values($controles)))->toJson());
}

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-12 10:00:00');
});

it('rend l’âge du dernier passage et chaque contrôle, sans meta ni texte', function (): void {
    santeDiagnosticPassage(
        '2026-10-12 09:55:00',
        new StoredCheckResult('TachesPlanifiees', 'Tâches planifiées', 'Échouée : backup:run', '1 échouée', 'failed', ['chemin' => '/srv/x']),
        new StoredCheckResult('Database', 'Base de données', '', 'ok', 'ok'),
    );

    expect(new ProjectionDeLaSante()->liste(FenetreDeDiagnostic::depuis(null, now())))->toBe([
        'dernier_passage_le' => '2026-10-12T09:55:00+02:00',
        'age_en_secondes' => 300,
        'controles' => [
            ['nom' => 'Database', 'libelle' => 'Base de données', 'statut' => 'ok'],
            ['nom' => 'TachesPlanifiees', 'libelle' => 'Tâches planifiées', 'statut' => 'failed'],
        ],
    ]);
});

it('rend un âge nul plutôt que négatif, et rien sans passage', function (): void {
    santeDiagnosticPassage('2026-10-12 10:00:30', new StoredCheckResult('Database', 'Base', '', 'ok', 'ok'));

    expect(new ProjectionDeLaSante()->liste(FenetreDeDiagnostic::depuis(null, now()))['age_en_secondes'])->toBe(0);

    Cache::forget('health:storeResults');

    expect(new ProjectionDeLaSante()->liste(FenetreDeDiagnostic::depuis(null, now())))
        ->toBe(['dernier_passage_le' => null, 'age_en_secondes' => null, 'controles' => []]);
});

it('ajoute au détail, sous TexteBrut seulement, le résumé et le message de chaque contrôle', function (): void {
    santeDiagnosticPassage('2026-10-12 09:55:00', new StoredCheckResult('TachesPlanifiees', 'Tâches', 'Échouée : backup:run', '1 échouée', 'failed', ['x' => 'y']));
    $fenetre = FenetreDeDiagnostic::depuis(null, now());

    expect(new ProjectionDeLaSante()->detail($fenetre, false)['controles'])->toBe([['nom' => 'TachesPlanifiees', 'libelle' => 'Tâches', 'statut' => 'failed']])
        ->and(new ProjectionDeLaSante()->detail($fenetre, true)['controles'])->toEqual([[
            'nom' => 'TachesPlanifiees',
            'libelle' => 'Tâches',
            'statut' => 'failed',
            'non_fiable' => [
                'resume' => new TexteNonFiable('1 échouée', ProjectionDeLaSante::BUDGET_DU_RESUME),
                'message' => new TexteNonFiable('Échouée : backup:run', ProjectionDeLaSante::BUDGET_DU_MESSAGE),
            ],
        ]]);
});

it('rend ce que chaque conteneur annonce, et des valeurs nulles pour qui ne s’est pas annoncé', function (): void {
    Config::set('app.version', '1.6.1');
    Config::set('app.revision', 'e76e9b68');
    AnnonceDeVersion::annoncer('worker');

    expect(new ProjectionDesVersions()->liste())->toBe([
        ['conteneur' => 'app', 'version' => null, 'revision' => null, 'depuis' => null, 'annonce_le' => null],
        ['conteneur' => 'worker', 'version' => '1.6.1', 'revision' => 'e76e9b68', 'depuis' => '2026-10-12T10:00:00+02:00', 'annonce_le' => '2026-10-12T10:00:00+02:00'],
        ['conteneur' => 'scheduler', 'version' => null, 'revision' => null, 'depuis' => null, 'annonce_le' => null],
    ])->and(new ProjectionDesVersions()->application())->toBe(['version' => '1.6.1', 'revision' => 'e76e9b68', 'environnement' => 'testing']);
});
