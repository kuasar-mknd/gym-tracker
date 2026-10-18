<?php

declare(strict_types=1);

namespace App\Support\Sessions;

/**
 * Ce que `SessionGardee` et `SessionChiffreeGardee` ajoutent au magasin de
 * sessions de Laravel : une session détruite ne renaît pas de
 * l'enregistrement de fin d'une requête qui l'avait lue avant (#PR).
 *
 * - Détruire un identifiant (`invalidate()`, `regenerate(true)`,
 *   `migrate(true)`, ce que font la déconnexion, la connexion, la suppression
 *   du compte, le changement du mot de passe sur cet appareil et la
 *   déconnexion du panneau) pose d'abord sa pierre tombale, puis le détruit.
 * - Ouvrir une session sous un identifiant détruit en tire un neuf : le
 *   cookie d'une session finie ouvre une session d'invité neuve, jamais une
 *   session vide sous l'ancien identifiant, que la règle suivante refuserait
 *   d'enregistrer à chaque requête.
 * - Enregistrer la session écrit, puis lit la pierre tombale de l'identifiant
 *   lu en entrant : posée entre-temps par une autre requête, elle fait
 *   retirer ce qui vient d'être écrit, sous l'ancien identifiant comme sous
 *   un nouveau que la requête aurait tiré sans détruire l'ancien
 *   (`regenerate()`). L'ordre ferme la course sans verrou : la destruction
 *   pose la pierre puis efface, l'enregistrement écrit puis lit ; ou bien la
 *   lecture voit la pierre et retire l'écriture, ou bien elle la précède, et
 *   l'effacement qui suit la pierre emporte l'écriture.
 *
 * Une requête qui détruit elle-même l'identifiant qu'elle a lu (une
 * déconnexion, une connexion) enregistre sa nouvelle session comme Laravel.
 * Une requête qui n'a croisé aucune destruction aussi, et prolonge sa session
 * comme avant : une lecture du cache en entrant, une après l'écriture. Une
 * requête sans identifiant reçu (pas de cookie, ou un cookie illisible), dont
 * le magasin tire l'identifiant, n'en lit aucune : personne d'autre ne connaît
 * cet identifiant, que seule sa réponse donnera au navigateur.
 *
 * L'état de la requête (l'identifiant lu, et s'il a été détruit par elle) se
 * remet à zéro à chaque ouverture : sous Octane, la session sert le worker
 * d'une requête à l'autre. `StartSession` donne à la session l'identifiant du
 * cookie (`setId()`) avant de l'ouvrir, à chaque requête.
 *
 * @phpstan-require-extends \Illuminate\Session\Store
 */
trait NeRenaitPasDeSaDestruction
{
    /**
     * L'identifiant reçu sous lequel la requête a lu la session en entrant ;
     * nul quand le magasin l'a tiré.
     */
    private ?string $identifiantLu = null;

    /**
     * La requête a détruit elle-même l'identifiant qu'elle a lu.
     */
    private bool $detruiteParLaRequete = false;

    /**
     * Le dernier identifiant donné à `setId()` était valide : à l'ouverture,
     * celui du cookie de la requête, que `StartSession` donne avant d'ouvrir.
     * Faux quand le magasin l'a tiré, faute d'identifiant valide : personne
     * d'autre ne le connaît.
     */
    private bool $identifiantRecu = false;

    /**
     * Pose l'identifiant de la session, ou en tire un s'il n'est pas valide,
     * et retient lequel des deux.
     *
     * @param  string|null  $id
     */
    #[\Override]
    public function setId($id): void
    {
        $this->identifiantRecu = $this->isValidId($id);

        parent::setId($id);
    }

    /**
     * Ouvre la session ; sous un identifiant reçu et détruit, en tire un
     * neuf. Un identifiant tiré par le magasin n'a pas de pierre tombale à
     * lire, ni à l'ouverture ni à l'enregistrement.
     */
    #[\Override]
    public function start(): bool
    {
        if ($this->identifiantRecu && $this->pierresTombales->estPoseeSur($this->getId())) {
            $this->setId(null);
        }

        $this->identifiantLu = $this->identifiantRecu ? $this->getId() : null;
        $this->detruiteParLaRequete = false;

        return parent::start();
    }

    /**
     * Change d'identifiant ; s'il détruit l'ancien, pose d'abord sa pierre
     * tombale.
     *
     * @param  bool  $destroy
     */
    #[\Override]
    public function migrate($destroy = false): bool
    {
        if ($destroy) {
            $this->pierresTombales->poserSur($this->getId());
            $this->detruiteParLaRequete = $this->detruiteParLaRequete || $this->getId() === $this->identifiantLu;
        }

        return parent::migrate($destroy);
    }

    /**
     * Enregistre la session comme Laravel, sauf si elle a été détruite
     * ailleurs pendant la requête.
     */
    #[\Override]
    public function save(): void
    {
        $this->enregistrerSiElleTient();
    }

    #[\Override]
    public function enregistrerSiElleTient(): bool
    {
        parent::save();

        if ($this->identifiantLu === null
            || $this->detruiteParLaRequete
            || ! $this->pierresTombales->estPoseeSur($this->identifiantLu)) {
            return true;
        }

        $this->getHandler()->destroy($this->getId());

        return false;
    }
}
