import { AsyncLocalStorage } from 'node:async_hooks'
import { vi } from 'vitest'

/**
 * Ce qu'il faut pour rejouer un chargement de page autour du singleton de
 * `Utils/SyncService` : la page rendue par le serveur, d'où il lit le compte
 * connecté, et une instance neuve qui emporte les écouteurs de la précédente.
 */

/** Le compte des tests, tel que la file le note. */
export const COMPTE = '1'

/**
 * Le moment d'une écriture mise en file il y a une heure, pour une entrée posée
 * à la main dans le stockage.
 *
 * Une date écrite en dur expire : la file abandonne ce qui attend depuis plus
 * de trente jours (#2050), et une entrée datée du 29 juillet disparaissait au
 * chargement, un mois et demi après qu'on avait écrit le test. Un test qui
 * vérifie cette durée pose lui-même l'heure (`vi.setSystemTime`).
 *
 * @returns {string}
 */
export const horodatageRecent = () => new Date(Date.now() - 60 * 60 * 1000).toISOString()

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

/**
 * Les canaux que le singleton ouvre à sa construction (`BroadcastChannel`,
 * #2049). Node en a un, qui relie toutes les instances du fichier : l'instance
 * d'un test précédent, que plus aucun écouteur ne relie à la page, aurait
 * encore relayé à la fenêtre les annonces de la suivante.
 */
const canauxDuService = []

export const retirerLesEcouteursDuService = () => {
    ecouteursDuService.splice(0).forEach(([cible, type, ecouteur]) => cible.removeEventListener(type, ecouteur))
    canauxDuService.splice(0).forEach((canal) => canal.close())
}

/**
 * Importe le singleton dans le graphe de modules courant, en relevant ce qu'il
 * pose à sa construction : chaque écouteur de `window` et de `document`, et
 * chaque canal qu'il ouvre.
 *
 * @param {{
 *   surUnEcouteur: (cible: EventTarget, type: string, ecouteur: Function, options: unknown) => void,
 *   surUnCanal: (canal: BroadcastChannel) => void,
 *   Canal?: typeof BroadcastChannel|null,
 * }} options `surUnEcouteur` pose lui-même l'écouteur ; `Canal` remplace
 *   `BroadcastChannel` pendant l'import, null l'en retire
 * @returns {Promise<Object>}
 */
const importerLeService = async ({ surUnEcouteur, surUnCanal, Canal = globalThis.BroadcastChannel }) => {
    const espions = [window, document].map((cible) =>
        vi.spyOn(cible, 'addEventListener').mockImplementation((type, ecouteur, options) => {
            surUnEcouteur(cible, type, ecouteur, options)
        }),
    )
    const CanalDOrigine = globalThis.BroadcastChannel

    if (Canal === null) {
        globalThis.BroadcastChannel = undefined
    } else if (typeof Canal === 'function') {
        globalThis.BroadcastChannel = class extends Canal {
            constructor(...parametres) {
                super(...parametres)
                surUnCanal(this)
            }
        }
    }

    try {
        return (await import('@/Utils/SyncService')).default
    } finally {
        globalThis.BroadcastChannel = CanalDOrigine
        espions.forEach((espion) => espion.mockRestore())
    }
}

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

    const ajouter = { window: window.addEventListener, document: document.addEventListener }

    return importerLeService({
        surUnEcouteur: (cible, type, ecouteur, options) => {
            ecouteursDuService.push([cible, type, ecouteur])
            ajouter[cible === window ? 'window' : 'document'].call(cible, type, ecouteur, options)
        },
        surUnCanal: (canal) => canauxDuService.push(canal),
    })
}

/**
 * Un appareil où plusieurs onglets restent ouverts sur le même `localStorage`,
 * chacun avec sa file vivante (#2049) : `chargerSyncService` n'en garde qu'un,
 * puisqu'il retire les écouteurs de l'instance précédente.
 *
 * Chaque onglet est un graphe de modules à lui (`vi.resetModules`), comme une
 * page : son `SyncService`, son `useEtatDuReseau`, son canal. Ce que l'un écrit
 * dans le stockage est relayé aux AUTRES en `StorageEvent`, de même clé et de
 * même valeur, comme le navigateur le fait, et jamais à celui qui l'écrit : un
 * onglet qui oublierait de tenir sa propre copie ne serait pas rattrapé par sa
 * propre écriture. L'écrivain se reconnaît au contexte asynchrone
 * (`AsyncLocalStorage`) dans lequel l'écriture a lieu : celui de l'onglet dont
 * le test appelle une méthode (l'onglet rendu enveloppe chacune), ou dont un
 * écouteur, un canal ou une minuterie a été déclenché ; il suit les `await`.
 * Une écriture faite hors de tout onglet, par le test lui-même, va à tous.
 *
 * Les canaux sont les vrais `BroadcastChannel` de Node : ils relient les
 * onglets de l'appareil, de façon asynchrone comme dans un navigateur, et se
 * ferment avec lui (`fermer`).
 *
 * @returns {{
 *   ouvrir: (options?: {compte?: number|string|null, sansCanal?: boolean}) => Promise<Object>,
 *   refuserLesEcritures: (cle: string|null) => void,
 *   fermer: () => void,
 * }}
 */
export const ouvrirUnAppareil = () => {
    retirerLesEcouteursDuService()

    const contexte = new AsyncLocalStorage()

    /** @type {Array<{ecouteursDuStockage: Function[], ecouteurs: Array<[EventTarget, string, Function]>, canaux: BroadcastChannel[]}>} */
    const onglets = []
    const ecrire = Storage.prototype.setItem
    const effacer = Storage.prototype.removeItem

    /** La clé dont le stockage refuse l'écriture, comme un quota atteint. */
    let cleRefusee = null

    /** Vrai pendant `sansRelais` : les autres onglets n'ont pas encore reçu l'évènement. */
    let relaisRetenu = false

    const relayer = (stockage, cle, ancienne, nouvelle) => {
        if (stockage !== localStorage || ancienne === nouvelle || relaisRetenu) {
            return
        }

        const ecrivain = contexte.getStore()

        onglets
            .filter((onglet) => onglet !== ecrivain)
            .forEach((onglet) => {
                const evenement = new StorageEvent('storage', {
                    key: cle,
                    oldValue: ancienne,
                    newValue: nouvelle,
                    url: window.location.href,
                })

                contexte.run(onglet, () => onglet.ecouteursDuStockage.forEach((ecouteur) => ecouteur(evenement)))
            })
    }

    const espionDesEcritures = vi.spyOn(Storage.prototype, 'setItem').mockImplementation(function (cle, valeur) {
        if (this === localStorage && cle === cleRefusee) {
            throw new DOMException('plein', 'QuotaExceededError')
        }

        const ancienne = this.getItem(cle)

        ecrire.call(this, cle, valeur)
        relayer(this, cle, ancienne, String(valeur))
    })
    const espionDesRetraits = vi.spyOn(Storage.prototype, 'removeItem').mockImplementation(function (cle) {
        const ancienne = this.getItem(cle)

        effacer.call(this, cle)
        relayer(this, cle, ancienne, null)
    })

    const CanalDOrigine = globalThis.BroadcastChannel

    /** L'onglet de chaque service rendu par `ouvrir`. */
    const ongletsDesServices = new WeakMap()

    return {
        /**
         * Ouvre un onglet de plus : la page posée sous ce compte, un graphe de
         * modules neuf, et son `SyncService`, construit dans le contexte de
         * l'onglet. Rend le service, dont chaque méthode s'exécute dans ce
         * contexte. `sansCanal` l'ouvre sans `BroadcastChannel`, comme un
         * navigateur qui ne l'a pas : rien de ce que les autres annoncent ne
         * l'atteint.
         */
        ouvrir: async ({ compte = Number(COMPTE), sansCanal = false } = {}) => {
            const onglet = { ecouteursDuStockage: [], ecouteurs: [], canaux: [] }
            const dansLOnglet = (rappel) =>
                function (...parametres) {
                    return contexte.run(onglet, () => rappel.apply(this, parametres))
                }
            const ajouter = { window: window.addEventListener, document: document.addEventListener }

            onglets.push(onglet)
            poserLaPage(compte)
            vi.resetModules()

            const service = await contexte.run(onglet, () =>
                importerLeService({
                    surUnEcouteur: (cible, type, ecouteur, options) => {
                        const enveloppe = dansLOnglet(ecouteur)

                        onglet.ecouteurs.push([cible, type, enveloppe])

                        if (cible === window && type === 'storage') {
                            onglet.ecouteursDuStockage.push(ecouteur)
                        }

                        ajouter[cible === window ? 'window' : 'document'].call(cible, type, enveloppe, options)
                    },
                    surUnCanal: (canal) => onglet.canaux.push(canal),
                    Canal: sansCanal
                        ? null
                        : typeof CanalDOrigine === 'function'
                          ? class extends CanalDOrigine {
                                addEventListener(type, ecouteur, options) {
                                    super.addEventListener(type, dansLOnglet(ecouteur), options)
                                }
                            }
                          : undefined,
                }),
            )

            const rendu = new Proxy(service, {
                get(cible, nom) {
                    const valeur = Reflect.get(cible, nom)

                    return typeof valeur === 'function'
                        ? (...parametres) => contexte.run(onglet, () => valeur.apply(cible, parametres))
                        : valeur
                },
            })

            ongletsDesServices.set(rendu, onglet)

            return rendu
        },

        /**
         * Joue `rappel` dans l'onglet de ce service, comme ce que sa page y
         * ferait : la réponse d'une requête que son `useEtatDuReseau` voit,
         * par exemple.
         *
         * @template T
         * @param {Object} service un service rendu par `ouvrir`
         * @param {() => T} rappel
         * @returns {T}
         */
        dans: (service, rappel) => contexte.run(ongletsDesServices.get(service), rappel),

        /**
         * Joue `rappel` sans relayer ses écritures : le navigateur livre
         * l'évènement `storage` en tâche, et un autre onglet peut agir avant
         * de l'avoir reçu, sur sa copie d'avant.
         *
         * @template T
         * @param {() => Promise<T>|T} rappel
         * @returns {Promise<T>}
         */
        sansRelais: async (rappel) => {
            relaisRetenu = true

            try {
                return await rappel()
            } finally {
                relaisRetenu = false
            }
        },

        /** Fait refuser au stockage toute écriture de cette clé (null : plus aucune), comme un quota atteint. */
        refuserLesEcritures: (cle) => {
            cleRefusee = cle
        },

        /** Ferme chaque onglet : ses écouteurs retirés, ses canaux fermés, le relais du stockage rétabli. */
        fermer: () => {
            onglets.splice(0).forEach((onglet) => {
                onglet.ecouteurs.forEach(([cible, type, ecouteur]) => cible.removeEventListener(type, ecouteur))
                onglet.canaux.forEach((canal) => canal.close())
            })
            espionDesEcritures.mockRestore()
            espionDesRetraits.mockRestore()
        },
    }
}

/** La page qu'Inertia annonce, avec le compte qu'elle porte. */
const pageDuCompte = (compte) => ({ page: { props: { auth: { user: compte === null ? null : { id: compte } } } } })

/**
 * Une visite Inertia à laquelle le serveur répond, comme le routeur l'annonce
 * sur `document` : la page qu'elle porte dit qui la session connecte.
 */
export const naviguer = (compte) => {
    document.dispatchEvent(new CustomEvent('inertia:navigate', { detail: pageDuCompte(compte) }))
    document.dispatchEvent(new CustomEvent('inertia:success', { detail: pageDuCompte(compte) }))
}

/**
 * Le bouton Retour : Inertia restaure une page depuis l'historique, avec les
 * props qu'elle avait à son affichage, sans rien demander au serveur. Elle
 * annonce la navigation, pas de réponse.
 */
export const restaurerDepuisLHistorique = (compte) =>
    document.dispatchEvent(new CustomEvent('inertia:navigate', { detail: pageDuCompte(compte) }))
