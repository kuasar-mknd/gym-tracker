<?php

declare(strict_types=1);

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Exception\ExceptionInterface;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\StringInput;

/*
 * Le planificateur ne lance pas une commande, il lance une ligne : celle qu'il
 * compile depuis le tableau d'options de `routes/console.php`
 * (`Schedule::compileParameters`). Une valeur `true` y devient `'1'`, et un
 * drapeau sans valeur écrit `['--only-db' => true]` part en `--only-db='1'`,
 * que la console refuse avant toute exécution : « The "--only-db" option does
 * not accept a value ». Les trois sauvegardes et la purge du journal
 * d'activité sortaient ainsi en erreur chaque nuit, et `backup:monitor`
 * recevait une option qu'il n'a pas, alors que les mêmes commandes, lancées du
 * panneau ou d'un test par `Artisan::call()` et un tableau, passaient (#2020).
 *
 * Vérifier que l'option existe ne suffit pas : `--only-db` existe. La garde lit
 * donc chaque ligne compilée comme la console la lira, contre la définition
 * complète de sa commande, et refuse ce que la console refuserait : commande
 * inconnue, option inconnue, valeur donnée à un drapeau, argument manquant ou
 * de trop.
 *
 * `StringInput` découpe la ligne comme le shell, sauf sur un point qu'elle ne
 * juge pas : une barre oblique inverse entre apostrophes y disparaît, quand le
 * shell la garde. La garde lit la forme de la ligne, jamais ses valeurs.
 */

/**
 * La définition que la console lie à la ligne : celle de la commande, plus
 * l'argument `command` et les options communes de la console (`--env`,
 * `--no-interaction`…), comme `Command::run()` les fusionne. Une commande déjà
 * lancée dans ce processus porte déjà les options communes : elles ne sont
 * pas reprises deux fois.
 */
function lignesPlanifieesDefinitionComplete(Command $commande): InputDefinition
{
    $console = $commande->getApplication()?->getDefinition() ?? new InputDefinition();
    $propre = $commande->getDefinition();

    return new InputDefinition([
        ...array_values($console->getArguments()),
        ...array_values(array_diff_key($propre->getArguments(), $console->getArguments())),
        ...array_values($propre->getOptions()),
        ...array_values(array_diff_key($console->getOptions(), $propre->getOptions())),
    ]);
}

it('fait accepter chaque ligne du planning par la commande qu’elle lance', function (): void {
    $prefixe = Application::formatCommandString('');
    $commandes = Artisan::all();
    $acceptees = [];
    $refusees = [];

    foreach (app(Schedule::class)->events() as $evenement) {
        $ligne = (string) $evenement->command;

        if ($evenement instanceof CallbackEvent || ! str_starts_with($ligne, $prefixe)) {
            continue;
        }

        $entree = new StringInput(substr($ligne, strlen($prefixe)));
        $nom = (string) $entree->getFirstArgument();
        $commande = $commandes[$nom] ?? null;

        if (! $commande instanceof Command) {
            $refusees[] = "{$ligne} : aucune commande « {$nom} »";

            continue;
        }

        try {
            $entree->bind(lignesPlanifieesDefinitionComplete($commande));
            $entree->validate();
            $acceptees[] = $nom;
        } catch (ExceptionInterface $refus) {
            $refusees[] = "{$ligne} : {$refus->getMessage()}";
        }
    }

    expect($refusees)->toBe([], "La console refuserait ces tâches planifiées avant de les lancer, chaque fois :\n- ".implode("\n- ", $refusees));

    // Sans elles, la garde passerait aussi le jour où plus rien ne serait reconnu.
    expect($acceptees)->toContain('backup:clean', 'backup:run', 'backup:monitor', 'activitylog:clean', 'model:prune');
});
