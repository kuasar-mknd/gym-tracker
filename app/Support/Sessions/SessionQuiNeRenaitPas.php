<?php

declare(strict_types=1);

namespace App\Support\Sessions;

/**
 * Une session qui ne renaît pas de sa destruction : celle que
 * `GestionnaireDesSessions` bâtit, en clair (`SessionGardee`) ou chiffrée
 * (`SessionChiffreeGardee`).
 *
 * `OuvreLaSession` l'enregistre par `enregistrerSiElleTient()`, qui lui dit
 * si la réponse peut en poser le cookie, et demande à `aEteLueAuMagasin()`
 * si un préchargement l'a trouvée, ou l'ouvre neuve (#2148).
 */
interface SessionQuiNeRenaitPas
{
    /**
     * Enregistre la session, puis la retire du magasin si celle que la
     * requête a lue en entrant a été détruite ailleurs pendant qu'elle
     * tournait. Rend faux dans ce cas : rien d'elle ne doit revenir au
     * navigateur.
     */
    public function enregistrerSiElleTient(): bool;

    /**
     * La requête a lu en entrant une session que le magasin tenait. Faux pour
     * une session neuve : un invité sans cookie, un cookie illisible, une
     * session expirée ou détruite, que le magasin ne tient plus.
     */
    public function aEteLueAuMagasin(): bool;
}
