<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Un job qui échoue sur une écriture que la base refuse (#2032).
 *
 * Il ne porte que le nom du refus : sa charge utile, gardée dans
 * `failed_jobs`, ne contient donc pas le canari. Il appelle les fonctions de
 * `tests/Feature/Security/LesErreursSqlNeRecopientPasLesValeursTest.php`,
 * chargé avant que le job tourne, puisque le test le traite dans son propre
 * processus (`queue:work --once`). La classe vit ici et non dans ce fichier :
 * une classe d'un fichier de test échappe à l'autochargement PSR-4, ce que
 * `php artisan doctor` refuse en CI.
 */
final class JobQuiEcritUneLigneRefusee implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $refus)
    {
    }

    public function handle(): void
    {
        \erreursSqlEcrire($this->refus, \erreursSqlCanari());
    }
}
