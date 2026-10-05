import { vi } from 'vitest'

/**
 * Ce qu'il faut pour rejouer un chargement de page autour du singleton de
 * `Utils/SyncService` : la page rendue par le serveur, d'où il lit le compte
 * connecté, et une instance neuve qui emporte les écouteurs de la précédente.
 */

/** Le compte des tests, tel que la file le note. */
export const COMPTE = '1'

/**
 * Pose la page rendue par le serveur dans l'élément où Inertia la lit au
 * démarrage.
 *
 * @param {number|string|null} compte l'utilisateur connecté, ou personne
 * @param {string|null} contenu un contenu brut à la place de la page, pour une page illisible
 */
export const poserLaPage = (compte, contenu = null) => {
    document.querySelectorAll('script[data-page]').forEach((element) => element.remove())

    if (compte === null && contenu === null) {
        return
    }

    const script = document.createElement('script')
    script.dataset.page = 'app'
    script.type = 'application/json'
    script.textContent =
        contenu ?? JSON.stringify({ component: 'Dashboard', props: { auth: { user: { id: compte } } } })
    document.body.appendChild(script)
}

/**
 * Les écouteurs que le singleton pose à sa construction. Un rechargement les
 * emporte avec la page : sans les retirer, chaque instance des tests
 * précédents entendrait encore `online` ou `inertia:navigate` et viderait sa
 * propre copie de la file.
 */
const ecouteursDuService = []

export const retirerLesEcouteursDuService = () =>
    ecouteursDuService.splice(0).forEach(([cible, type, ecouteur]) => cible.removeEventListener(type, ecouteur))

/**
 * Un chargement de page neuf : la page posée, les modules oubliés, et le
 * singleton reconstruit depuis le stockage. Les modules importés ensuite par
 * le test partagent cette instance.
 *
 * @param {{compte?: number|string|null, page?: string|null}} options
 */
export const chargerSyncService = async ({ compte = Number(COMPTE), page = null } = {}) => {
    retirerLesEcouteursDuService()
    poserLaPage(compte, page)
    vi.resetModules()

    const espions = [window, document].map((cible) => {
        const ajouter = cible.addEventListener

        return vi.spyOn(cible, 'addEventListener').mockImplementation((type, ecouteur, options) => {
            ecouteursDuService.push([cible, type, ecouteur])
            ajouter.call(cible, type, ecouteur, options)
        })
    })

    try {
        return (await import('@/Utils/SyncService')).default
    } finally {
        espions.forEach((espion) => espion.mockRestore())
    }
}

/** Annonce une navigation Inertia, comme le routeur le fait sur `document`. */
export const naviguer = (compte) =>
    document.dispatchEvent(
        new CustomEvent('inertia:navigate', {
            detail: { page: { props: { auth: { user: compte === null ? null : { id: compte } } } } },
        }),
    )
