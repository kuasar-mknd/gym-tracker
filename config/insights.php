<?php

declare(strict_types=1);

/*
 * Une seule configuration PHP Insights, lue par les deux portes d'entree.
 *
 * Il y en avait deux, et elles se contredisaient.
 *
 * `vendor/bin/phpinsights` — ce que lance la CI — cherche `phpinsights.php` a
 * la racine (`ConfigResolver::CONFIG_FILENAME`). Ce fichier-ci n'est le defaut
 * que de la commande Artisan (`Adapters\Laravel\Commands\InsightsCommand`).
 * Il declarait 154 lignes de reglages que la CI n'a jamais lus, dont une liste
 * `remove` couvrant LineLength, ParameterTypeHint, PropertyTypeHint,
 * ReturnTypeHint, DisallowMixedTypeHint, UnusedParameter et
 * InlineDocCommentDeclaration.
 *
 * Ce sont exactement les sniffs qui font tomber le job `audit`. Autrement dit,
 * le README envoie les contributeurs sur `artisan insights`, qui lit ce
 * fichier, qui desactive precisement ce que la CI va leur reprocher. La porte
 * documentee etait la plus indulgente des deux.
 *
 * D'ou cette delegation plutot qu'une suppression : la commande Artisan reste
 * utilisable, et les deux portes ne peuvent plus diverger puisqu'il n'y a plus
 * qu'un seul jeu de reglages. `tests/Feature/Conventions/UneSeuleConfigInsightsTest.php`
 * le verifie.
 */

return require __DIR__.'/../phpinsights.php';
