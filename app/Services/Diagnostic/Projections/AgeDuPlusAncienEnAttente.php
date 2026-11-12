<?php

declare(strict_types=1);

namespace App\Services\Diagnostic\Projections;

use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Facades\Queue;

/**
 * L'âge, en secondes, du plus ancien job en attente d'une file d'Horizon
 * (#2032), lu dans la tête de sa liste Redis (`LINDEX`, sans rien retirer) :
 * seule la date de création de sa charge utile en sort, jamais la charge.
 * Horizon nomme la file sans sa connexion (`default`, ou `default,mails` pour
 * un superviseur de plusieurs files) : la connexion est celle de la file par
 * défaut de l'application, et c'est le plus ancien de ses files qui est rendu.
 * `null` pour des files vides, une connexion qui n'est pas Redis ou un magasin
 * qui ne répond pas. Les jobs différés n'y sont pas.
 */
final class AgeDuPlusAncienEnAttente
{
    public function __invoke(string $nom, int $maintenant): ?int
    {
        return rescue(fn (): ?int => $this->lire($nom, $maintenant), null, report: false);
    }

    private function lire(string $nom, int $maintenant): ?int
    {
        $queue = Queue::connection(config()->string('queue.default'));

        if (! $queue instanceof RedisQueue) {
            return null;
        }

        $creations = collect(explode(',', $nom))
            ->filter(static fn (string $file): bool => $file !== '')
            ->map(static fn (string $file): mixed => $queue->creationTimeOfOldestPendingJob($file))
            ->filter(static fn (mixed $creation): bool => is_numeric($creation));

        return $creations->isEmpty() ? null : max(0, $maintenant - (int) $creations->min());
    }
}
