<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\call;

/**
 * Un appareil qui garde ses propres cookies, pour jouer dans un même test
 * plusieurs sessions d'un même compte (#1940).
 *
 * Le client de test de Laravel n'a pas de bocal à cookies, et son application
 * garde d'une requête à l'autre la garde, la session en mémoire et les cookies
 * mis en file : deux appareils y partageraient la même connexion, et rien ne
 * distinguerait la session qui change le mot de passe de celle qu'il doit
 * fermer. Chaque requête part donc d'un état remis à zéro comme Octane le fait
 * entre deux requêtes, et ne porte que les cookies que les réponses précédentes
 * ont posés sur CET appareil, chiffrés comme le navigateur les renverrait.
 */
final class Appareil
{
    /**
     * Les cookies de l'appareil, déchiffrés, par nom.
     *
     * @var array<string, string>
     */
    private array $cookies = [];

    /**
     * La garde par défaut que la configuration donne quand l'appareil apparaît.
     *
     * Un `actingAs()` que le test pose ensuite (sur la garde du panneau, par
     * exemple) la change pour tout le conteneur ; sous Octane, chaque requête
     * repart de la configuration.
     */
    private readonly string $gardeParDefaut;

    public function __construct()
    {
        $this->gardeParDefaut = config()->string('auth.defaults.guard');
    }

    /**
     * Envoie une requête avec les cookies de l'appareil, et garde ceux que la
     * réponse pose ou efface.
     *
     * @param  array<string, mixed>  $donnees
     * @param  array<string, string>  $entetes
     * @return TestResponse<Response>
     */
    public function envoyer(string $methode, string $chemin, array $donnees = [], array $entetes = []): TestResponse
    {
        $this->oublierLaRequetePrecedente();

        /** @var TestResponse<Response> $reponse */
        $reponse = call($methode, $chemin, $donnees, $this->cookiesChiffres(), [], self::variablesDuServeur($entetes));

        $this->garderLesCookiesDe($reponse);

        return $reponse;
    }

    /**
     * La valeur déchiffrée d'un cookie de l'appareil.
     */
    public function cookie(string $nom): ?string
    {
        return $this->cookies[$nom] ?? null;
    }

    /**
     * Perd un cookie, comme le navigateur perd celui de session à son expiration.
     */
    public function oublierLeCookie(string $nom): void
    {
        unset($this->cookies[$nom]);
    }

    /**
     * Un autre appareil qui porte une copie des cookies de celui-ci : le cookie
     * recopié depuis un appareil partagé ou perdu.
     */
    public function copie(): self
    {
        return clone $this;
    }

    /**
     * Ce qu'Octane remet à zéro entre deux requêtes, et que le client de test
     * garde sinon : les gardes et la garde par défaut, la session en mémoire et
     * les cookies en file.
     */
    private function oublierLaRequetePrecedente(): void
    {
        app('auth')->forgetGuards();
        app('auth')->shouldUse($this->gardeParDefaut);
        app()->forgetInstance('auth.driver');
        app('cookie')->flushQueuedCookies();
        app('session.store')->flush();
    }

    /**
     * @return array<string, string>
     */
    private function cookiesChiffres(): array
    {
        $chiffres = [];

        foreach ($this->cookies as $nom => $valeur) {
            $chiffres[$nom] = Crypt::encryptString(CookieValuePrefix::create($nom, Crypt::getKey()).$valeur);
        }

        return $chiffres;
    }

    /**
     * @param  TestResponse<Response>  $reponse
     */
    private function garderLesCookiesDe(TestResponse $reponse): void
    {
        foreach ($reponse->baseResponse->headers->getCookies() as $cookie) {
            $nom = $cookie->getName();
            $valeur = (string) $cookie->getValue();

            if ($cookie->isCleared() || $valeur === '') {
                unset($this->cookies[$nom]);

                continue;
            }

            try {
                $this->cookies[$nom] = CookieValuePrefix::remove(Crypt::decryptString($valeur));
            } catch (DecryptException) {
                $this->cookies[$nom] = $valeur;
            }
        }
    }

    /**
     * Les en-têtes de la requête, sous la forme des variables du serveur.
     *
     * @param  array<string, string>  $entetes
     * @return array<string, string>
     */
    private static function variablesDuServeur(array $entetes): array
    {
        $variables = [];

        foreach ($entetes as $nom => $valeur) {
            $cle = strtoupper(str_replace('-', '_', $nom));
            $variables[$cle === 'CONTENT_TYPE' ? $cle : 'HTTP_'.$cle] = $valeur;
        }

        return $variables;
    }
}
