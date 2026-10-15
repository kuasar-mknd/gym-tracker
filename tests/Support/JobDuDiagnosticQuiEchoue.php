<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Un job qui échoue sur une écriture refusée (#2032), avec les canaris sans
 * forme dans sa charge utile : `failed_jobs` les garde, le diagnostic ne
 * doit jamais les rendre. La classe vit ici et non dans un fichier de test,
 * pour l'autochargement PSR-4 que `php artisan doctor` exige en CI.
 */
final class JobDuDiagnosticQuiEchoue implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $premier, public string $second)
    {
    }

    public function handle(): void
    {
        CanarisDuDiagnostic::ecrireUnCompteEnDouble($this->premier, $this->second);
    }
}
