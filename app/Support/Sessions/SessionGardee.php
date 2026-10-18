<?php

declare(strict_types=1);

namespace App\Support\Sessions;

use Illuminate\Session\Store;
use SessionHandlerInterface;

/**
 * Le magasin de sessions de Laravel, qui ne renaît pas de sa destruction
 * (`NeRenaitPasDeSaDestruction`). `GestionnaireDesSessions` le bâtit pour
 * chaque pilote ; `SessionChiffreeGardee` est sa forme chiffrée
 * (`session.encrypt`).
 */
final class SessionGardee extends Store implements SessionQuiNeRenaitPas
{
    use NeRenaitPasDeSaDestruction;

    public function __construct(
        private readonly PierresTombales $pierresTombales,
        string $nom,
        SessionHandlerInterface $gestionnaire,
        string $serialisation = 'php',
    ) {
        parent::__construct($nom, $gestionnaire, null, $serialisation);
    }
}
