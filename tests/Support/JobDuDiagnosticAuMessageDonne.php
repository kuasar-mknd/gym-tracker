<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

/**
 * Un job qui échoue sur une exception au message donné (#2032) : un vrai
 * échec, que `queue:work` écrit dans `failed_jobs` comme en production,
 * pour un message qu'un tiers a pu écrire, comme le corps d'une réponse
 * qu'un message d'erreur recopie.
 */
final class JobDuDiagnosticAuMessageDonne implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $message)
    {
    }

    public function handle(): never
    {
        throw new RuntimeException($this->message);
    }
}
