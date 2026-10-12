<?php

declare(strict_types=1);

namespace Tests\Support;

use Facebook\WebDriver\Exception\Internal\UnexpectedResponseException;
use Facebook\WebDriver\Exception\Internal\WebDriverCurlException;
use Facebook\WebDriver\Remote\DriverCommand;
use Facebook\WebDriver\Remote\RemoteWebDriver;

/**
 * Le pilote des parcours Dusk, qui retient le premier ordre resté sans réponse
 * et refuse ensuite d'en envoyer d'autres (#2029).
 *
 * Un ordre dont la réponse ne vient pas dans `DuskTestCase::SECONDES_POUR_UN_ORDRE`
 * lève une `WebDriverCurlException`. Dusk en avale une partie : ses attentes
 * (`waitUsing()`, d'où passent `waitFor()`, `waitForText()`, `waitUntil()`…)
 * la prennent pour une condition fausse, et la recherche d'éléments de
 * `click()`, `assertCount()` et `assertPresent()` pour un élément absent. Le
 * parcours tombait alors sur un message qui ne parlait pas du pilote, et
 * `browse()`, qui ne voyait pas l'exception, relevait la capture d'écran, dont
 * l'ordre attendait à son tour derrière celui qui restait sans réponse.
 *
 * Chaque ordre passe par `execute()` : il y est noté, où que Dusk le rattrape,
 * et `browse()` le lit. Les ordres suivants ne partent plus, sauf la fermeture.
 * Le pilote traite les ordres d'une session l'un après l'autre : chacun
 * attendrait son délai derrière le premier, et une saisie de Dusk (`type()`),
 * qui essaie trois sélecteurs l'un après l'autre, le paierait trois fois. Et
 * l'ordre resté sans réponse peut encore s'exécuter si le navigateur reprend :
 * la suite du parcours ne serait plus ce qu'elle dit.
 */
class PiloteDesParcours extends RemoteWebDriver
{
    /**
     * Le premier ordre resté sans réponse, ou null tant que le pilote répond.
     */
    private ?UnexpectedResponseException $premierOrdreSansReponse = null;

    /**
     * L'exception de l'ordre resté sans réponse, ou null si chaque ordre a eu
     * la sienne.
     */
    public function ordreSansReponse(): ?UnexpectedResponseException
    {
        return $this->premierOrdreSansReponse;
    }

    /**
     * @param  string  $command_name
     * @param  array<mixed>  $params
     */
    #[\Override]
    public function execute($command_name, $params = []): mixed
    {
        if ($this->premierOrdreSansReponse !== null && $command_name !== DriverCommand::QUIT) {
            throw new UnexpectedResponseException(sprintf(
                'L’ordre « %s » n’est pas envoyé : le pilote n’a pas répondu à un ordre précédent du parcours (#2029).',
                $command_name,
            ), 0, $this->premierOrdreSansReponse);
        }

        try {
            return parent::execute($command_name, $params);
        } catch (UnexpectedResponseException $echec) {
            if ($echec instanceof WebDriverCurlException) {
                $this->premierOrdreSansReponse ??= $echec;
            }

            throw $echec;
        }
    }
}
