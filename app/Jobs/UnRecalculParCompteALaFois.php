<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Le recalcul en file de ce que l'activité d'un compte fait bouger (objectifs,
 * succès) : un envoi n'est jamais perdu, deux recalculs du même compte ne
 * tournent jamais ensemble, et aucun verrou ne survit pour de bon à un travail
 * jamais traité (#2105). La classe qui s'en sert déclare
 * `ShouldBeUniqueUntilProcessing` et porte le compte dans `$user`.
 *
 * Unique jusqu'au traitement : Laravel rend le verrou d'unicité au moment où
 * le travail commence. Un envoi qui arrive pendant le recalcul du compte met
 * donc un nouveau travail en file, qui relira l'état final. Tant qu'un travail
 * attend sans avoir commencé, les envois suivants restent écartés : il lira
 * tout ce qu'ils lui demandaient. `ShouldBeUnique` gardait le verrou jusqu'à la
 * fin de `handle()`, et l'envoi était écarté alors que le travail en cours
 * avait déjà lu l'état d'avant.
 *
 * Le verrou d'unicité expire après `VERROU_D_UNICITE` secondes. Sans échéance,
 * un travail retiré de la file sans être traité (file vidée, clé perdue) le
 * laissait pris pour toujours, et plus aucun recalcul du compte n'était mis en
 * file. Il dure plus que le verrou d'exécution : un travail qui attend son tour
 * garde sa place.
 *
 * Une seule exécution à la fois par compte (`WithoutOverlapping` sur son
 * identifiant, propre à chaque classe). Sans elle, le recalcul accepté pendant
 * un autre tournerait à côté de lui : le plus ancien écrirait en dernier l'état
 * qu'il a lu avant, et deux recalculs des succès inséreraient le même succès,
 * que l'index unique de `user_achievements` refuse. Un travail qui trouve le
 * compte occupé revient en file `ATTENTE_AVANT_DE_RETENTER` secondes plus tard,
 * sans tenir de worker pendant ce temps. Le verrou d'exécution expire après
 * `VERROU_D_EXECUTION` secondes, plus que le délai que Horizon laisse à un
 * travail (`timeout` de `config/horizon.php`) : un recalcul garde son verrou
 * jusqu'à ce que Horizon l'arrête, et un worker tué ne bloque le compte que
 * jusqu'à cette échéance.
 *
 * Chaque retour en file compte comme un essai : la classe en permet
 * `ESSAIS_PERMIS` (`#[Tries]`), assez pour attendre la fin d'un verrou
 * d'exécution orphelin. Une exception, un délai dépassé ou un plantage font au
 * contraire échouer le travail du premier coup (`#[MaxExceptions(1)]`,
 * `#[CountCrashesAsExceptions]`), comme tout travail que Horizon n'essaie
 * qu'une fois (`tries` à 1) : il va dans `failed_jobs`, sans être rejoué.
 */
trait UnRecalculParCompteALaFois
{
    /**
     * L'attente, en secondes, d'un recalcul qui trouve le même compte en cours
     * de recalcul, avant d'essayer de nouveau.
     */
    public const int ATTENTE_AVANT_DE_RETENTER = 5;

    /**
     * La durée de vie, en secondes, du verrou d'exécution d'un compte : au-delà
     * du délai que Horizon laisse à un travail.
     */
    public const int VERROU_D_EXECUTION = 120;

    /**
     * La durée de vie, en secondes, du verrou d'unicité d'un travail en file.
     */
    public const int VERROU_D_UNICITE = 300;

    /**
     * Les essais permis : autant de retours en file qu'il en faut pour voir
     * expirer le verrou d'exécution d'un worker tué, plus le premier essai.
     */
    public const int ESSAIS_PERMIS = self::VERROU_D_EXECUTION / self::ATTENTE_AVANT_DE_RETENTER + 1;

    /**
     * Le compte : un seul travail de cette classe l'attend en file.
     */
    public function uniqueId(): string
    {
        return (string) $this->user->id;
    }

    /**
     * L'échéance, en secondes, du verrou d'unicité pris à l'envoi.
     */
    public function uniqueFor(): int
    {
        return self::VERROU_D_UNICITE;
    }

    /**
     * Un seul recalcul de cette classe à la fois pour le compte.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping((string) $this->user->id)
                ->releaseAfter(self::ATTENTE_AVANT_DE_RETENTER)
                ->expireAfter(self::VERROU_D_EXECUTION),
        ];
    }
}
