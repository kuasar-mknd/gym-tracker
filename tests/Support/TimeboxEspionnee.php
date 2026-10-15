<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Timebox;

/**
 * Une Timebox qui retient la durée que chaque appel lui demande.
 *
 * `DureeDesReponsesDAuthentification` et la connexion du panneau prennent
 * leur Timebox dans le conteneur : un test y lie celle-ci
 * (`app()->bind(Timebox::class, …)`) pour vérifier qu'une réponse passe bien
 * par le plancher commun, et non par une durée à elle. Les durées vont dans
 * un relevé partagé, puisque le conteneur en fournit une neuve à chaque appel.
 */
final class TimeboxEspionnee extends Timebox
{
    /**
     * @param  \ArrayObject<int, int>  $durees
     */
    public function __construct(private readonly \ArrayObject $durees)
    {
    }

    #[\Override]
    public function call(callable $callback, int $microseconds)
    {
        $this->durees->append($microseconds);

        return parent::call($callback, $microseconds);
    }
}
