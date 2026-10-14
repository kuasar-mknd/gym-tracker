<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
| `in()` n'atteint que les fichiers de tests Pest (`it()`, `test()`) : une classe
| PHPUnit garde sa classe parente et ses traits. Les parcours de tests/Browser
| sont des classes, et ceux qui vident leur base portent eux-mêmes
| `use DatabaseTruncation;` (#1927).
|
*/

/*
| L'heure que lit la Timebox de Laravel compte le sommeil simulé d'une mesure
| de durée (`Tests\Support\DureeSimulee`). PHP retient la fonction `microtime`
| trouvée au premier appel d'une Timebox : la déclarer avant tout test.
*/
require_once __DIR__.'/Support/horloge-des-timebox.php';

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
