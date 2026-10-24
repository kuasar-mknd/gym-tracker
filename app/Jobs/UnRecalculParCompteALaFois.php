<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Le recalcul en file de ce que l'activité d'un compte fait bouger (objectifs,
 * succès) : un envoi n'est jamais perdu, deux recalculs de la même classe pour
 * un même compte ne tournent jamais ensemble, et aucun verrou ne survit pour de
 * bon à un travail jamais traité (#2105). La classe qui s'en sert déclare
 * `ShouldBeUniqueUntilProcessing` et porte le compte dans `$user`. Sa clé
 * (`uniqueId()`), celle du verrou d'unicité comme du verrou d'exécution, est
 * le compte ; une classe qui la restreint la redéfinit : `SyncPersonalRecord`
 * la porte à la série du compte (#2130).
 *
 * Unique jusqu'au traitement : Laravel rend le verrou d'unicité au moment où
 * le travail commence. Un envoi qui arrive pendant le recalcul du compte met
 * donc un nouveau travail en file, qui relira l'état final. Tant qu'un travail
 * attend sans avoir commencé, les envois suivants restent écartés : il lira
 * l'écriture qu'ils annonçaient, pourvu que chaque envoi suive son écriture.
 * `Set::saved` envoie depuis `Set::booted()`, après le volume de la séance,
 * que lit le succès du volume cumulé, et après les records qu'écrit
 * l'écouteur d'`AppServiceProvider`. Seul le record d'une série s'écrit après
 * eux, dans un worker en production : `SyncPersonalRecord` renvoie donc les
 * deux recalculs une fois le record écrit. `ShouldBeUnique` gardait le verrou
 * jusqu'à la fin de `handle()`, et l'envoi était écarté alors que le travail
 * en cours avait déjà lu l'état d'avant.
 *
 * Le verrou d'unicité expire après `VERROU_D_UNICITE` secondes. Sans échéance,
 * un travail retiré de la file sans être traité (file vidée, clé perdue) le
 * laissait pris pour toujours, et plus aucun recalcul du compte n'était mis en
 * file. Il dure plus que le verrou d'exécution : un travail qui attend son tour
 * garde sa place. Un travail qui échoue le rend (`failed()`).
 *
 * Une seule exécution à la fois par compte et par classe (`WithoutOverlapping`
 * sur la clé du travail). Sans elle, le recalcul accepté pendant un autre
 * tournerait à côté de lui : le plus ancien écrirait en dernier l'état qu'il a
 * lu avant, et deux recalculs des succès inséreraient le même succès, que
 * l'index unique de `user_achievements` refuse. Un recalcul des objectifs et
 * un des succès du même compte tournent ensemble : ils écrivent des tables
 * différentes. Un travail qui trouve le compte occupé revient en file
 * `ATTENTE_AVANT_DE_RETENTER` secondes plus tard, sans tenir de worker pendant
 * ce temps. Le verrou d'exécution expire après `VERROU_D_EXECUTION` secondes,
 * plus que le délai que le worker laisse à un travail : le `timeout` de
 * `config/horizon.php`, que la classe ne remplace par aucun délai à elle
 * (`#[Timeout]` l'emporterait). Un recalcul garde son verrou jusqu'à ce que
 * Horizon l'arrête, et un worker tué ne bloque le compte que jusqu'à cette
 * échéance.
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
     * du délai que le worker laisse à un travail, celui du superviseur de
     * Horizon tant que la classe n'en déclare aucun.
     */
    public const int VERROU_D_EXECUTION = 120;

    /**
     * La durée de vie, en secondes, du verrou d'unicité d'un travail en file.
     */
    public const int VERROU_D_UNICITE = 300;

    /**
     * Les essais permis : le premier, autant de retours en file qu'il en faut
     * pour voir expirer le verrou d'exécution d'un worker tué, et un de plus.
     * La file rend un travail remis à plus tard à la seconde pleine : quand le
     * premier essai tombe dans la même seconde que la pose du verrou orphelin,
     * son retour dure moins de `ATTENTE_AVANT_DE_RETENTER` secondes, et le
     * dernier des retours comptés arrive jusqu'à une seconde avant l'échéance.
     */
    public const int ESSAIS_PERMIS = self::VERROU_D_EXECUTION / self::ATTENTE_AVANT_DE_RETENTER + 2;

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
     * Un seul recalcul de cette classe à la fois pour la clé du travail : le
     * compte, ou ce à quoi la classe la restreint.
     *
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping($this->uniqueId())
                ->releaseAfter(self::ATTENTE_AVANT_DE_RETENTER)
                ->expireAfter(self::VERROU_D_EXECUTION),
        ];
    }

    /**
     * Rend le verrou d'unicité du travail en échec. Laravel ne rend celui d'un
     * `ShouldBeUniqueUntilProcessing` qu'au moment où il commence : un travail
     * qui épuise ses essais à attendre le compte le garderait jusqu'à son
     * échéance, et les envois du compte resteraient écartés jusque-là. Le
     * verrou se rend par son propriétaire : celui d'un envoi plus récent reste
     * pris. L'exception que Laravel passe ne change rien au verrou à rendre.
     */
    public function failed(): void
    {
        new UniqueLock(app(Repository::class))->release($this);
    }
}
