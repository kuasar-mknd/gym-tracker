<?php

declare(strict_types=1);

namespace App\Support\Sessions;

/**
 * Une session qui ne renaît pas de sa destruction : celle que
 * `GestionnaireDesSessions` bâtit, en clair (`SessionGardee`) ou chiffrée
 * (`SessionChiffreeGardee`).
 *
 * `OuvreLaSession` l'enregistre par `enregistrerSiElleTient()`, qui lui dit
 * si la réponse peut en poser le cookie.
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
}
