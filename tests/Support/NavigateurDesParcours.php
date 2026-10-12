<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Exception;
use Laravel\Dusk\Browser;

/**
 * Le navigateur des parcours Dusk, dont les attentes tombent sur l'ordre resté
 * sans réponse plutôt que sur leur délai (#2029).
 *
 * Le `waitUsing()` de Dusk, d'où passent `waitFor()`, `waitForText()`,
 * `waitUntil()`, `waitForLocation()` et `clickWhenSettled()`, prend toute
 * exception de sa condition pour une condition fausse. Un ordre resté sans
 * réponse y devenait « Waited 5 seconds for selector », après les
 * quatre-vingt-dix secondes de `DuskTestCase::SECONDES_POUR_UN_ORDRE` : un
 * message qui se trompait de durée et taisait le pilote. Quand le pilote ne
 * répond plus (`PiloteDesParcours::ordreSansReponse()`), l'attente s'arrête et
 * lève l'exception de sa condition ; toute autre exception reste une condition
 * fausse, comme chez Dusk.
 *
 * Les macros de `Browser` et ses réglages statiques (`$baseUrl`, dossiers des
 * relevés) sont ceux de la classe parente, que cette classe ne redéclare pas.
 */
class NavigateurDesParcours extends Browser
{
    /**
     * @param  int|null  $seconds
     * @param  int  $interval
     * @param  string|null  $message
     */
    #[\Override]
    public function waitUsing($seconds, $interval, Closure $callback, $message = null): static
    {
        $echecSansReponse = null;

        parent::waitUsing($seconds, $interval, function () use ($callback, &$echecSansReponse): mixed {
            try {
                return $callback();
            } catch (Exception $echec) {
                if (! $this->piloteNeRepondPlus()) {
                    throw $echec;
                }

                $echecSansReponse = $echec;

                return true;
            }
        }, $message);

        if ($echecSansReponse instanceof Exception) {
            throw $echecSansReponse;
        }

        return $this;
    }

    /**
     * Dit si un ordre du parcours est resté sans réponse.
     */
    private function piloteNeRepondPlus(): bool
    {
        return $this->driver instanceof PiloteDesParcours && $this->driver->ordreSansReponse() !== null;
    }
}
