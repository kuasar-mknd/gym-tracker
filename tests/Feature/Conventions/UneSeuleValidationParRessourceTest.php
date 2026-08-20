<?php

declare(strict_types=1);

/*
 * Deux classes de validation qui portent le même nom doivent partager leurs
 * règles (#1378).
 *
 * Neuf classes existaient à la fois dans `Requests/` et dans `Requests/Api/`,
 * avec des règles qui avaient divergé. La même ressource était donc validée
 * différemment selon la porte d'entrée, et **toute contrainte présente d'un
 * côté et absente de l'autre était contournable en changeant de porte**.
 *
 * Ce n'était pas théorique : `StoreWilksScoreRequest` bornait le poids de corps
 * à 500 kg et la charge à 1000 côté web, et se contentait de `gt:0` côté API.
 * Pire, l'API exigeait `score` du client et l'enregistrait tel quel, alors que
 * le web le calcule.
 *
 * Le garde n'exige pas des règles identiques — une variante peut avoir besoin
 * d'une contrainte de plus, comme l'unicité par utilisateur qu'ajoute
 * `Api\DailyJournalStoreRequest`. Il exige que la variante **hérite** : une
 * différence devient alors une redéfinition explicite, visible dans un diff,
 * au lieu d'une copie qui dérive en silence.
 */
it('fait hériter toute requête API qui porte le nom d’une requête web', function (): void {
    $api = glob(app_path('Http/Requests/Api/*.php'));

    expect($api)->not->toBeFalse('la recherche des requêtes API a échoué');
    assert(is_array($api));
    expect($api)->not->toBeEmpty('aucune requête API trouvée : le test ne prouverait rien');

    $fautes = [];

    foreach ($api as $chemin) {
        $nom = basename($chemin, '.php');

        if (! file_exists(app_path("Http/Requests/{$nom}.php"))) {
            continue;
        }

        $classeApi = "App\\Http\\Requests\\Api\\{$nom}";
        $classeWeb = "App\\Http\\Requests\\{$nom}";

        if (! class_exists($classeApi) || ! class_exists($classeWeb)) {
            continue;
        }

        if (! is_subclass_of($classeApi, $classeWeb)) {
            $fautes[] = sprintf(
                '%s : la version API recopie les règles au lieu d’hériter de %s',
                $nom,
                $classeWeb
            );
        }
    }

    expect($fautes)->toBe([], sprintf(
        "une règle durcie d'un seul côté serait contournable en changeant de porte :\n  %s",
        implode("\n  ", $fautes)
    ));
});
