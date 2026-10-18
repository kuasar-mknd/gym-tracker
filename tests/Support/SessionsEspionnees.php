<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Session\ArraySessionHandler;

/**
 * Le magasin des sessions des tests (en mémoire), qui compte ses enregistrements.
 *
 * `PrechauffageSansSessionEcriteTest` le branche comme magasin de l'application
 * pour vérifier qu'un GET marqué du préchauffage des copies hors ligne lit la
 * session sans l'enregistrer (`OuvreLaSession`), et que la même requête sans la
 * marque l'enregistre.
 */
final class SessionsEspionnees extends ArraySessionHandler
{
    /**
     * Les identifiants des sessions enregistrées, dans l'ordre.
     *
     * @var list<string>
     */
    public array $ecritures = [];

    #[\Override]
    public function write($sessionId, $data): bool
    {
        $this->ecritures[] = $sessionId;

        return parent::write($sessionId, $data);
    }
}
