<?php

declare(strict_types=1);

/*
 * PHP Insights doit rendre le meme verdict par les deux portes d'entree.
 *
 * `vendor/bin/phpinsights` — ce que lance le job `audit` — lit
 * `phpinsights.php` a la racine. `artisan insights`, la commande que le README
 * donne aux contributeurs, lit `config/insights.php`.
 *
 * Les deux fichiers ont vecu leur vie chacun de leur cote. Celui de `config/`
 * declarait une liste `remove` de sept sniffs — LineLength, ParameterTypeHint,
 * PropertyTypeHint, ReturnTypeHint, DisallowMixedTypeHint, UnusedParameter,
 * InlineDocCommentDeclaration — c'est-a-dire exactement ceux qui font echouer
 * la CI. La porte documentee etait donc la plus indulgente : on pouvait suivre
 * le README, voir vert, pousser, et decouvrir le rouge ensuite.
 *
 * Ce test ne recite pas les reglages : il compare les deux sources. Il tient
 * donc encore le jour ou quelqu'un ajoute une regle, du moment qu'il l'ajoute
 * a un seul endroit.
 */

it('ne laisse pas les deux portes d’entrée diverger', function (): void {
    $racine = base_path('phpinsights.php');
    $artisan = base_path('config/insights.php');

    expect($racine)->toBeReadableFile()
        ->and($artisan)->toBeReadableFile();

    expect(require $artisan)->toBe(require $racine);
});

/*
 * Et la delegation doit rester une delegation.
 *
 * Comparer les valeurs ne suffirait pas seul : deux copies identiques
 * passeraient le test du jour, puis divergeraient au premier changement fait
 * d'un seul cote — c'est precisement l'histoire qu'on vient de corriger.
 */
it('fait de la config Artisan un simple renvoi vers celle de la racine', function (): void {
    $source = (string) file_get_contents(base_path('config/insights.php'));

    expect($source)->toContain("require __DIR__.'/../phpinsights.php'");
});
