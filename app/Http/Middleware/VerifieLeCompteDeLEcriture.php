<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuse, sans l'exécuter, une écriture rejouée par la file hors ligne pour un
 * autre compte que celui de la session (#1964).
 *
 * La file hors ligne note sur chaque écriture le compte qui l'a faite, et ne
 * vide que celles du compte que l'onglet croit connecté. Cette croyance vient
 * de la dernière page que l'onglet a reçue : elle peut être périmée, quand un
 * autre onglet ou la PWA, qui partagent les cookies, ont ouvert entre-temps la
 * session d'un autre compte. Le client ne peut donc pas tenir seul la promesse
 * qu'une écriture ne part jamais sous la session d'un autre : le vidage envoie
 * le compte de chaque écriture dans `X-Compte-De-L-Ecriture`, et c'est ici
 * qu'il est comparé à celui de la session.
 *
 * Un compte qui diffère répond 409, marqué `raison: compte-different`, avant
 * le contrôleur : rien n'est écrit, et la file garde l'écriture pour son
 * compte. Sans session, la requête suit son cours, et l'authentification
 * répond comme avant (401), ce qui invite à se reconnecter. Une requête sans
 * l'en-tête, c'est-à-dire tout ce qui ne vient pas du vidage, n'est pas
 * touchée.
 *
 * Posé en fin des groupes `web` et `api`, donc après l'ouverture de la session,
 * la vérification CSRF et l'authentification, que l'ordre de priorité de
 * Laravel place devant lui. Rien d'une requête à l'autre sous Octane.
 */
final class VerifieLeCompteDeLEcriture
{
    /**
     * L'en-tête qui porte le compte pour lequel l'écriture a été faite.
     */
    public const string ENTETE = 'X-Compte-De-L-Ecriture';

    /**
     * Ce que la réponse 409 porte pour que la file la distingue d'un autre
     * conflit.
     */
    public const string RAISON = 'compte-different';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $compteDeLEcriture = (string) $request->headers->get(self::ENTETE);
        $compteDeLaSession = $request->user()?->id;

        if ($compteDeLEcriture === '' || $compteDeLaSession === null || $compteDeLEcriture === (string) $compteDeLaSession) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Cette modification a été faite par un autre compte que celui connecté : elle attend que ce compte se reconnecte.',
            'raison' => self::RAISON,
        ], Response::HTTP_CONFLICT);
    }
}
