<?php

declare(strict_types=1);

namespace App\Support\Sessions;

use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Session\EncryptedStore;
use SessionHandlerInterface;

/**
 * Le magasin de sessions chiffré de Laravel (`session.encrypt`), qui ne
 * renaît pas de sa destruction (`NeRenaitPasDeSaDestruction`), comme
 * `SessionGardee`.
 */
final class SessionChiffreeGardee extends EncryptedStore implements SessionQuiNeRenaitPas
{
    use NeRenaitPasDeSaDestruction;

    public function __construct(
        private readonly PierresTombales $pierresTombales,
        string $nom,
        SessionHandlerInterface $gestionnaire,
        Encrypter $chiffrement,
        string $serialisation = 'php',
    ) {
        parent::__construct($nom, $gestionnaire, $chiffrement, null, $serialisation);
    }
}
