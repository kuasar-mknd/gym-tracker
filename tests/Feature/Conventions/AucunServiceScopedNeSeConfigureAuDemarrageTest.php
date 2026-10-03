<?php

declare(strict_types=1);

use Filament\Support\Components\Contracts\ScopedComponentManager;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\CurrentApplication;

/**
 * Un service lié en `scoped` est oublié par Octane après chaque requête
 * (`FlushTemporaryContainerInstances`). Ce qu'un fournisseur lui dit au
 * démarrage ne sert donc que la première requête d'un worker : c'est ainsi que
 * la porte du lecteur de journaux, posée par `LogViewer::auth()`, disparaissait,
 * et que le lecteur répondait 403 en production.
 *
 * Résoudre un tel service au démarrage en est le symptôme : seuls restent
 * permis ceux que Filament résout lui-même et reconstruit à chaque requête — le
 * gestionnaire des panneaux, que la liste des panneaux (un singleton) remplit,
 * et celui des composants, cloné d'un singleton à chaque requête. Un nouveau
 * venu dans la liste se juge avant d'y être ajouté : sa configuration du
 * démarrage survit-elle à la deuxième requête ?
 */
it('ne résout au démarrage aucun service scoped hors de ceux que Filament reconstruit à chaque requête', function (): void {
    $applicationDuTest = app();

    try {
        $base = new ApplicationFactory(base_path())->createApplication();
        /** @var list<string> $scoped */
        $scoped = new ReflectionProperty(Container::class, 'scopedInstances')->getValue($base);
        $resolusAuDemarrage = array_values(array_filter($scoped, $base->resolved(...)));
    } finally {
        CurrentApplication::set($applicationDuTest);
        Model::setConnectionResolver($applicationDuTest->make('db'));
        Model::setEventDispatcher($applicationDuTest->make('events'));
    }

    expect($resolusAuDemarrage)->toEqualCanonicalizing(['filament', ScopedComponentManager::class]);
});
