import enTetes from './enTetesDeLaCoquille.json'

/**
 * La page « hors ligne » telle que le worker la sert : marquée de sa raison,
 * enveloppée des en-têtes de sécurité de l'application (#2048, partie F).
 *
 * Le precache garde la page telle que le serveur l'a servie à l'installation :
 * un fichier statique de public/build, qui ne passe par aucun middleware, et
 * n'a donc ni la politique de sécurité du contenu ni les autres en-têtes que
 * Laravel pose sur une page. Servie telle quelle à une navigation, elle
 * s'ouvrirait sans eux, sous l'adresse d'une page de l'application. Le worker
 * en fait donc une réponse neuve, avec ces en-têtes et seulement eux : rien
 * de ce que le precache a gardé de la réponse d'origine ne passe.
 *
 * Les en-têtes vivent dans `enTetesDeLaCoquille.json`, de pures données :
 * chaque en-tête de `SecurityHeaders`, et la politique de `CustomPolicy` en
 * production, sans nonce (la page n'a aucun script en ligne à signer, et un
 * nonce écrit une fois pour toutes ne serait plus un nonce), plus
 * `frame-ancestors 'self'`, que `X-Frame-Options` dit déjà pour les
 * navigateurs plus anciens. Et `Cache-Control: no-store, private`, celui de
 * toute page de compte (`HandleInertiaRequests`) : la coquille y monte une
 * copie, et le cache de retour arrière, qui garde un document `no-store`
 * tant qu'aucun cookie ne change, le refuse quand la session a pris fin
 * entre-temps. Ni le worker ni la construction ne lisent le PHP :
 * `ServiceWorkerAssetsTest` refait ces en-têtes depuis le middleware et la
 * politique, et exige qu'ils soient exactement ceux du fichier, et ceux du
 * worker construit. Un en-tête ajouté côté serveur fait donc échouer la
 * suite tant que la coquille ne le porte pas.
 */

/** Les en-têtes de sécurité de la coquille, figés. */
export const EN_TETES_DE_LA_COQUILLE = Object.freeze({ ...enTetes })

/**
 * Pourquoi le worker sert la coquille : le réseau n'a pas répondu à temps
 * (`delai`), ou pas du tout (`reseau`). La page la lit dans `data-raison`,
 * sur `<html>`.
 */
export const RAISONS_DE_LA_COQUILLE = Object.freeze(['delai', 'reseau'])

/**
 * La coquille d'après la page précachée, ou `undefined` sans elle.
 *
 * Le document est relu tel quel, et seule sa balise `<html>` reçoit
 * `data-raison` : une raison inconnue devient `reseau`, et rien d'autre
 * qu'une valeur de la liste n'entre dans la page. Une page précachée qui ne
 * se lit pas donne `undefined`, comme une page absente : le navigateur montre
 * alors sa propre erreur, comme avant.
 *
 * @param {Response | undefined} precachee la réponse du precache
 * @param {string} raison une valeur de `RAISONS_DE_LA_COQUILLE`
 * @returns {Promise<Response | undefined>}
 */
export const envelopperLaCoquille = async (precachee, raison) => {
    if (precachee === undefined || precachee === null) {
        return undefined
    }

    const marque = RAISONS_DE_LA_COQUILLE.includes(raison) ? raison : 'reseau'
    let texte

    try {
        texte = await precachee.text()
    } catch {
        return undefined
    }

    const document = texte.replace(/<html\b/i, `<html data-raison="${marque}"`)

    return new Response(document, {
        status: 200,
        statusText: 'OK',
        headers: { 'Content-Type': 'text/html; charset=utf-8', ...EN_TETES_DE_LA_COQUILLE },
    })
}
