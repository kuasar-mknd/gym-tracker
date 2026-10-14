<?php

declare(strict_types=1);

namespace Illuminate\Support;

use Tests\Support\DureeSimulee;

/**
 * L'heure que lit la Timebox de Laravel : l'heure réelle, plus le sommeil
 * simulé de la mesure en cours (`DureeSimulee::heureDesTimebox()`).
 *
 * `Timebox::call()` lit l'heure par `microtime(true)`, sans barre oblique
 * initiale : PHP cherche d'abord une fonction `microtime` dans l'espace de
 * noms de la classe, puis la fonction globale, et retient la première trouvée
 * au premier appel. tests/Pest.php charge donc ce fichier avant tout test.
 *
 * Sans elle, une Timebox qui en englobe une autre ne voyait pas le sommeil
 * simulé de celle-ci et dormait à son tour tout le plancher : la mesure
 * comptait deux planchers là où le client n'en attend qu'un, et un travail
 * fait après la Timebox intérieure, que rien ne couvre en vrai, y paraissait
 * couvert par l'extérieure. `DureeDesReponsesDAuthentificationTest` vérifie
 * que cette horloge est bien celle des Timebox.
 */
function microtime(bool $enFlottant = false): string|float
{
    return DureeSimulee::heureDesTimebox($enFlottant);
}
