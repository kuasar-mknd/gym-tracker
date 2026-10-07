/**
 * Les navigations : le réseau d'abord, la page « hors ligne » sans lui (#1966).
 *
 * Le worker ne répondait qu'aux actifs construits. Une navigation, vers
 * l'accueil, une séance ou n'importe quelle page, passait au réseau, et sans
 * réseau le navigateur montrait sa propre page d'erreur : l'application
 * installée, que le système avait arrêtée pendant que le téléphone dormait, ne
 * se rouvrait pas dans une salle sans réseau.
 *
 * Chaque navigation passe désormais par le worker, qui la laisse au réseau tant
 * qu'il répond, et ne la sert lui-même que quand il ne répond pas : par la page
 * « hors ligne », précachée à l'installation. Aucune page de l'application
 * n'entre au cache, ni maintenant ni après : une page servie sans réseau ne
 * porte aucune donnée de compte, et ne peut donc pas montrer celles d'un compte
 * à un autre après une déconnexion. Une réponse du serveur, quel que soit son
 * statut, passe telle quelle : seule l'absence de réponse fait retomber.
 */

/** La page « hors ligne », telle que la construction l'écrit et que le precache la range. */
export const URL_DE_LA_PAGE_HORS_LIGNE = '/build/hors-ligne.html'

/** Une navigation que le worker prend en charge : un document demandé en GET. */
export const estUneNavigation = (requete) => requete.mode === 'navigate' && requete.method === 'GET'

/**
 * Le préchargement des navigations, là où le navigateur l'offre.
 *
 * Le navigateur lance alors la requête du document pendant que le worker
 * démarre, au lieu d'attendre qu'il la refasse : passer par le worker ne coûte
 * pas son démarrage à chaque ouverture, et la requête reste celle d'une
 * navigation, avec ses cookies et ses en-têtes.
 *
 * @param {ServiceWorkerRegistration} inscription
 */
export const activerLePrechargementDesNavigations = async (inscription) => {
    await inscription.navigationPreload?.enable()
}

/**
 * Le réseau, ou la page « hors ligne » quand il ne répond pas.
 *
 * @param {{request: Request, preloadResponse?: Promise<Response|undefined>}} event
 * @param {() => Promise<Response|undefined>} trouverLaPageHorsLigne
 * @param {(requete: Request) => Promise<Response>} allerChercher
 * @returns {Promise<Response>}
 */
export const naviguerOuRetomber = async (event, trouverLaPageHorsLigne, allerChercher) => {
    try {
        const prechargee = await event.preloadResponse

        if (prechargee) {
            return prechargee
        }

        return await allerChercher(event.request)
    } catch {
        return (await trouverLaPageHorsLigne()) ?? Response.error()
    }
}
