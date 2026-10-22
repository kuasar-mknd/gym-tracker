<?php

declare(strict_types=1);

namespace Tests\Support;

use ArrayObject;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\HeaderBag;

/**
 * Un sac des en-têtes qui retient le nom de chaque en-tête qu'on y cherche
 * (`get()`, `has()`, `contains()`, `all()` d'un nom ; en minuscules), présent
 * ou non : `UnEnTeteMalFormeNeRepondPas500Test` et
 * `UnePageDuPanneauNeRepondPas500Test` vérifient par lui que chaque essai
 * atteint la lecture qu'il éprouve (#2094). `all()` sans nom ne compte pas :
 * une requête de validation recopie ainsi tous les en-têtes de la requête.
 * Les noms vont dans un relevé partagé, puisque chaque requête de
 * validation reçoit un sac à elle.
 */
final class SacDEntetesEspionne extends HeaderBag
{
    /**
     * @param  array<string, list<string|null>|string|null>  $entetes
     * @param  ArrayObject<int, string>  $lus
     */
    public function __construct(array $entetes, private readonly ArrayObject $lus)
    {
        parent::__construct($entetes);
    }

    /**
     * Donne ce sac à la requête que le noyau reçoit, avant la pile globale
     * (le noyau la lie au conteneur, ce que `rebinding()` voit), et à chaque
     * requête de validation, une fois qu'elle a recopié la requête (ce
     * rappel passe après celui de Laravel, posé au démarrage).
     *
     * @param  ArrayObject<int, string>  $lus
     */
    public static function poser(Application $application, ArrayObject $lus): void
    {
        $espionner = static function (Request $requete) use ($lus): void {
            if (! $requete->headers instanceof self) {
                $requete->headers = new self($requete->headers->all(), $lus);
            }
        };

        $application->rebinding('request', static function (Application $application, mixed $requete) use ($espionner): void {
            if ($requete instanceof Request) {
                $espionner($requete);
            }
        });
        $application->resolving(FormRequest::class, static function (FormRequest $requete) use ($espionner): void {
            $espionner($requete);
        });
    }

    /**
     * @return ($key is null ? array<string, list<string|null>> : list<string|null>)
     */
    #[\Override]
    public function all(?string $key = null): array
    {
        if ($key !== null) {
            $this->lus->append(strtolower($key));
        }

        return parent::all($key);
    }

    #[\Override]
    public function has(string $key): bool
    {
        $this->lus->append(strtolower($key));

        return parent::has($key);
    }
}
