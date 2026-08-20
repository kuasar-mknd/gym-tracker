<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;

/*
 * Un champ de formulaire Filament absent du `$fillable` de son modèle est une
 * perte de saisie silencieuse (#1352).
 *
 * `CreateRecord` et `EditRecord` appellent `Model::create($data)` et
 * `->update($data)` avec l'état déshydraté du formulaire. Pour un champ hors
 * `$fillable` :
 *
 *  - hors production, `Model::shouldBeStrict()` fait lever une
 *    `MassAssignmentException` et rien n'est enregistré ;
 *  - EN PRODUCTION, le mode strict est coupé : le champ est ignoré sans un
 *    mot. L'admin voit une notification de succès et la valeur n'est jamais
 *    écrite.
 *
 * C'est le second cas qui compte, et c'est celui qu'on ne voit jamais en
 * développement.
 *
 * Ce test croise donc les deux listes. Il ne récite pas les écarts connus : il
 * les recalcule, ce qui le rend valable pour les ressources qui n'existent pas
 * encore.
 *
 * Deux échappatoires, et deux seulement :
 *  - `->dehydrated(false)` : le champ ne part pas dans les données, il est là
 *    pour l'affichage ;
 *  - la constante `CHAMPS_ASSIGNES_EXPLICITEMENT` sur la ressource, pour un
 *    champ que la page écrit elle-même plutôt que par assignation en masse.
 *    Élargir `$fillable` n'en est pas une : il s'applique à TOUS les chemins,
 *    pas seulement au back-office.
 */

/**
 * Les champs qu'un formulaire déclare, et s'ils sont déshydratés.
 *
 * Lecture du source plutôt qu'instanciation du schéma : construire un
 * `Filament\Schemas\Schema` hors d'un panneau demande un conteneur complet, et
 * ce test doit rester lisible.
 *
 * @return array<string, bool> nom du champ => déshydraté
 */
function champsDuFormulaire(string $chemin): array
{
    $source = (string) file_get_contents($chemin);
    $champs = [];

    // Chaque composant commence par `Quelque::make('champ')` et court jusqu'au
    // prochain, ou jusqu'à la fin de la liste.
    $morceaux = preg_split('/(?=\b[A-Z]\w*::make\(\')/', $source);

    if ($morceaux === false) {
        return [];
    }

    foreach ($morceaux as $morceau) {
        if (preg_match("/^[A-Z]\\w*::make\\('([a-z0-9_]+)'\\)/", $morceau, $trouve) !== 1) {
            continue;
        }

        $champs[$trouve[1]] = ! str_contains($morceau, 'dehydrated(false)');
    }

    return $champs;
}

it('n’expose aucun champ que le modèle refuserait d’écrire', function (): void {
    $formulaires = glob(app_path('Filament/Resources/*/Schemas/*Form.php'));

    // `glob()` rend `false` sur erreur du système de fichiers. Le distinguer du
    // tableau vide : l'un veut dire « rien trouvé », l'autre « je n'ai pas pu
    // chercher », et seul le second doit arrêter le test net.
    expect($formulaires)->not->toBeFalse('la recherche des formulaires a échoué');
    assert(is_array($formulaires));

    expect($formulaires)->not->toBeEmpty('aucun formulaire trouvé : le test ne prouverait rien');

    $ecarts = [];

    foreach ($formulaires as $chemin) {
        $ressource = basename(dirname($chemin, 2));
        $classeRessource = "App\\Filament\\Resources\\{$ressource}\\".rtrim($ressource, 's').'Resource';

        if (! class_exists($classeRessource)) {
            continue;
        }

        /** @var class-string<Model>|null $modele */
        $modele = $classeRessource::getModel();

        if ($modele === null) {
            continue;
        }

        /*
         * Par une variable, et non `(new $modele())->getFillable()` : Rector
         * veut retirer les parentheses — syntaxe PHP 8.4 — et Pint veut les
         * remettre. Les deux tournent dans l'audit, la CI n'est donc jamais
         * verte tant que la construction existe.
         */
        $instance = new $modele();
        $fillable = $instance->getFillable();
        /** @var list<string> $explicites */
        $explicites = defined($classeRessource.'::CHAMPS_ASSIGNES_EXPLICITEMENT')
            ? constant($classeRessource.'::CHAMPS_ASSIGNES_EXPLICITEMENT')
            : [];

        foreach (champsDuFormulaire($chemin) as $champ => $deshydrate) {
            if (! $deshydrate) {
                continue;
            }

            if (in_array($champ, $fillable, true) || in_array($champ, $explicites, true)) {
                continue;
            }

            $ecarts[] = sprintf('%s → %s (absent du $fillable de %s)', $ressource, $champ, class_basename($modele));
        }
    }

    expect($ecarts)->toBe([], sprintf(
        "ces champs seraient perdus en silence en production :\n  %s",
        implode("\n  ", $ecarts)
    ));
});
