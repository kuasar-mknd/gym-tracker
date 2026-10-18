<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Illuminate\Session\ArraySessionHandler;

/**
 * Le magasin des sessions des tests (en mémoire), qui compte ses enregistrements.
 *
 * `PrechauffageSansSessionEcriteTest` le branche comme magasin de l'application
 * pour vérifier qu'un GET marqué du préchauffage des copies hors ligne lit la
 * session sans l'enregistrer (`OuvreLaSession`), et que la même requête sans la
 * marque l'enregistre. `SessionDetruiteNeRenaitPasTest` y fait détruire la
 * session par une autre requête juste avant une écriture (#PR).
 */
final class SessionsEspionnees extends ArraySessionHandler
{
    /**
     * Les identifiants des sessions enregistrées, dans l'ordre.
     *
     * @var list<string>
     */
    public array $ecritures = [];

    /**
     * Ce qui se passe juste avant chaque écriture, avec l'identifiant à
     * écrire : une destruction qui tombe après la dernière vérification
     * qu'une requête pourrait faire avant d'écrire, la course la plus serrée
     * entre deux requêtes.
     *
     * @var (Closure(string): void)|null
     */
    public ?Closure $avantChaqueEcriture = null;

    #[\Override]
    public function write($sessionId, $data): bool
    {
        if ($this->avantChaqueEcriture instanceof Closure) {
            ($this->avantChaqueEcriture)($sessionId);
        }

        $this->ecritures[] = $sessionId;

        return parent::write($sessionId, $data);
    }
}
