import { ATTRIBUT_DE_LA_COQUILLE, ATTRIBUT_D_ATTENTE, ATTRIBUT_DU_MOTIF, MOTIF_DU_DELAI } from '@/sw/pageHorsLigne'
import { magasinDesCopies } from '@/Utils/copiesHorsLigne'
import { RAISON_RESEAU_LENT, RAISON_SANS_RESEAU, pageServie } from '@/Utils/copiesServies'
import { attendreLaReponse } from '@/Utils/messagesDuWorker'
import { COMPOSANT_DE_LA_SEANCE, PAGES_GARDEES, cleDeLaSeanceEnCours } from '@/Utils/pagesGardees'
import { noterQueLeServeurNeRepondPas } from '@/composables/useEtatDuReseau'

/**
 * Le démarrage de l'application dans la coquille hors ligne (#2048, partie F).
 *
 * Sans réseau, ou sans réponse à temps pour une page de la salle, le worker
 * sert la page « hors ligne » précachée, marquée `data-coquille` et
 * `data-raison` sur `<html>` (`resources/js/sw/pageHorsLigne.js`,
 * `coquilleServie.js`). Elle charge le module de l'entrée, qui importe ce
 * module statiquement : un import à la demande ne se chargerait qu'en mode
 * coquille, donc sans réseau, et ne serait jamais au precache. `main.js` voit
 * la marque (`estLaCoquille`) et attend ce démarrage avant tout le reste,
 * sauf l'écoute des messages du worker, posée d'emblée.
 *
 * **Le démarrage** (`demarrerHorsLigne`). Le worker ne lit, ne déchiffre et ne
 * sert rien d'un compte : c'est ici, dans la page, que se lit la copie, et
 * seulement dans la coquille que le worker a servie (`servieParLeWorker`).
 * 1. Le magasin vérifie la clé du témoin (présente, non fermée, de
 *    l'empreinte de son état), puis le titulaire de l'appareil (le compte de
 *    l'état) et le contrat, et purge à la première qui échoue
 *    (`lireAuDemarrage`, `copiesHorsLigne.js`).
 * 2. Il déchiffre la copie de l'adresse (sa clé normalisée, `cleDeLaCopie`)
 *    et la plus récente de toutes. Sans copie de l'adresse, la page
 *    `HorsLigne` dit ce qui s'ouvre sans réseau. Sans aucune copie, rien ne
 *    démarre.
 * 3. La page à monter est celle que l'application ouverte pousserait
 *    (`pageServie`, `copiesServies.js`) : `encryptHistory: true` écrit en dur,
 *    le `auth` le plus récent du compte (celui de la copie la plus récente),
 *    la marque `horsLigne` (`copieDu`, et la raison : `lent` au délai,
 *    `reseau` sinon), aucun message, aucune prop différée à charger.
 * 4. Elle s'écrit dans `<script data-page="app" type="application/json">` par
 *    `textContent`, jamais par du HTML : un `</script>`, un `<!--` ou un
 *    U+2028 dans une note de séance restent des données.
 * 5. La table des routes de la copie devient `Ziggy`, et `<main>` cède la
 *    place à `#app`, où Inertia monte.
 * `main.js` continue alors comme pour un document du serveur : la garde de
 * l'historique lit la page initiale, `createInertiaApp` la monte et la
 * chiffre dans l'historique, et la file hors ligne lit le compte dans
 * `data-page`. La page montée est une copie : le serveur est noté
 * injoignable, la sonde le redemande, et au retour du réseau elle se recharge
 * en entier, comme dans l'application ouverte (`visitesHorsLigne.js`). Aucun
 * jeton ni nonce : la coquille n'en porte pas, et les écritures n'en ont
 * pas besoin (`Sec-Fetch-Site: same-origin`, `X-XSRF-TOKEN` du cookie).
 *
 * **Le contenu statique.** Sans page à démarrer, la coquille reste telle que
 * le worker l'a servie : « Pas de réseau », ce qu'il faut faire avant la
 * salle, « Réessayer ». Elle se recharge au retour du réseau (`online`), ce
 * que faisait son script en ligne, que la politique de la page bloquerait
 * désormais. Servie au délai (`data-raison="delai"`), elle dit que le réseau
 * ne répond pas, et montre « Attendre la réponse », qui demande au worker de
 * lever le délai de l'adresse, puis recharge (`attendreLaReponse`).
 */

/**
 * Ce que dit `data-raison` d'une coquille servie au délai : le réseau n'a pas
 * répondu à temps. Toute autre valeur vaut `reseau`, comme pour le worker
 * (`RAISONS_DE_LA_COQUILLE`, `coquilleServie.js`).
 */
const RAISON_DU_DELAI = 'delai'

/** La page que la coquille monte à une adresse sans copie : ce qui s'ouvre sans réseau. */
export const COMPOSANT_HORS_LIGNE = 'HorsLigne'

/** La raison de la marque d'une copie montée par la coquille, d'après celle du worker. */
const RAISON_DE_LA_MARQUE = Object.freeze({ delai: RAISON_RESEAU_LENT, reseau: RAISON_SANS_RESEAU })

/**
 * Si le document est la coquille hors ligne servie par le worker.
 *
 * @param {Document | undefined} document
 * @returns {boolean}
 */
export const estLaCoquille = (document = globalThis.document) => {
    try {
        return document?.documentElement?.hasAttribute(ATTRIBUT_DE_LA_COQUILLE) === true
    } catch {
        return false
    }
}

/**
 * Si le worker a servi la coquille : il pose `data-raison` sur `<html>` en
 * l'enveloppant des en-têtes de l'application (`envelopperLaCoquille`). Le
 * fichier précaché, ouvert à sa propre adresse (`/build/hors-ligne.html`),
 * vient du serveur de fichiers sans eux, ni politique de contenu ni refus
 * d'être encadré : rien d'un compte ne s'y monte.
 *
 * @param {Document} document
 * @returns {boolean}
 */
export const servieParLeWorker = (document) => document?.documentElement?.hasAttribute('data-raison') === true

/**
 * Pourquoi le worker a servi la coquille : `delai` ou `reseau`.
 *
 * @param {Document} document
 * @returns {'delai' | 'reseau'}
 */
export const raisonDeLaCoquille = (document) =>
    document?.documentElement?.getAttribute('data-raison') === RAISON_DU_DELAI ? RAISON_DU_DELAI : 'reseau'

/**
 * Laisse le contenu statique de la coquille tel quel, et lui donne ce que son
 * script en ligne lui donnait : le rechargement au retour du réseau. Servie au
 * délai, elle dit que le réseau ne répond pas et propose d'attendre la
 * réponse.
 *
 * @param {{
 *   fenetre?: Window,
 *   attendre?: () => Promise<void>,
 *   recharger?: () => void,
 * }} [options]
 */
export const laisserLaCoquille = ({
    fenetre = window,
    attendre = () => attendreLaReponse(),
    recharger = () => fenetre.location.reload(),
} = {}) => {
    const document = fenetre.document

    fenetre.addEventListener('online', () => recharger(), { once: true })

    if (raisonDeLaCoquille(document) !== RAISON_DU_DELAI) {
        return
    }

    const motif = document.querySelector(`[${ATTRIBUT_DU_MOTIF}]`)
    const bouton = document.querySelector(`[${ATTRIBUT_D_ATTENTE}]`)

    if (motif !== null) {
        motif.textContent = MOTIF_DU_DELAI
    }

    if (bouton !== null) {
        bouton.hidden = false
        bouton.addEventListener('click', () => {
            bouton.disabled = true
            attendre()
        })
    }
}

/**
 * Ce qui s'ouvre sans réseau, dans l'ordre de la liste blanche : la séance en
 * cours avant les séances closes, puis les plus récentes d'abord.
 *
 * @param {Array<{ chemin: string, composant: string, copieDu: number }>} copies
 * @param {string | null} seanceEnCours la clé de la séance en cours
 * @returns {Array<{ chemin: string, composant: string, copieDu: number, enCours: boolean }>}
 */
export const pagesSansReseau = (copies, seanceEnCours) => {
    const rangs = new Map(PAGES_GARDEES.map((page, rang) => [page.composant, rang]))
    const rang = (copie) => rangs.get(copie.composant) ?? PAGES_GARDEES.length

    return copies
        .map(({ chemin, composant, copieDu }) => ({
            chemin,
            composant,
            copieDu,
            enCours: composant === COMPOSANT_DE_LA_SEANCE && chemin === seanceEnCours,
        }))
        .sort(
            (premiere, seconde) =>
                rang(premiere) - rang(seconde) ||
                Number(seconde.enCours) - Number(premiere.enCours) ||
                seconde.copieDu - premiere.copieDu,
        )
}

/**
 * Les props partagées d'une page, celles que le serveur pose sur chaque page
 * (`sharedProps`) : ce que le layout lit d'une page qui n'en a pas d'autres.
 *
 * @param {{ props?: Record<string, unknown>, sharedProps?: unknown }} page
 * @returns {Record<string, unknown>}
 */
const propsPartagees = (page) => {
    const noms = Array.isArray(page.sharedProps) ? page.sharedProps : ['auth']

    return Object.fromEntries(
        noms
            .filter((nom) => typeof nom === 'string' && Object.hasOwn(page.props ?? {}, nom))
            .map((nom) => [nom, page.props[nom]]),
    )
}

/**
 * La page à monter, ou `null` : la copie de l'adresse, sinon la page
 * `HorsLigne`, à l'adresse du document, avec les props partagées de la copie
 * la plus récente.
 *
 * @param {{
 *   lecture: { copie: object | null, plusRecente: object, copies: Array<{ chemin: string, composant: string, copieDu: number }> },
 *   adresse: string,
 *   raison: string,
 * }} options
 */
const pageAMonter = ({ lecture, adresse, raison }) => {
    const { copie, plusRecente } = lecture
    const auth = plusRecente.page.props?.auth

    if (copie !== null) {
        return pageServie({ copie, pageCourante: null, auth, raison })
    }

    return pageServie({
        copie: {
            page: {
                component: COMPOSANT_HORS_LIGNE,
                url: adresse,
                version: plusRecente.page.version ?? null,
                sharedProps: plusRecente.page.sharedProps,
                props: {
                    ...propsPartagees(plusRecente.page),
                    pagesSansReseau: pagesSansReseau(lecture.copies, cleDeLaSeanceEnCours(plusRecente.page)),
                },
            },
            copieDu: plusRecente.copieDu,
            datesDesProps: {},
        },
        pageCourante: null,
        auth,
        raison,
    })
}

/**
 * Écrit la page dans le document comme le serveur l'écrirait, par
 * `textContent`, pose la table des routes, et remplace `<main>` par `#app`.
 *
 * @param {Document} document
 * @param {object} page
 */
const ecrireLaPage = (document, page) => {
    const donnees = document.createElement('script')

    donnees.setAttribute('data-page', 'app')
    donnees.setAttribute('type', 'application/json')
    donnees.textContent = JSON.stringify(page)

    const application = document.createElement('div')

    application.id = 'app'
    document.querySelector('main').replaceWith(donnees, application)
}

/**
 * Démarre l'application dans la coquille, sur la copie de l'adresse ou la page
 * `HorsLigne`, ou laisse son contenu statique. Ne rejette jamais : une erreur
 * laisse la coquille telle quelle.
 *
 * @param {{
 *   fenetre?: Window,
 *   magasin?: () => { lireAuDemarrage: (adresse: string) => Promise<object> },
 *   attendre?: () => Promise<void>,
 *   recharger?: () => void,
 * }} [options]
 * @returns {Promise<{ montee: boolean, raison: string, page?: object }>}
 */
export const demarrerHorsLigne = async (options = {}) => {
    const { fenetre = window, magasin = magasinDesCopies } = options
    const document = fenetre.document
    const adresse = `${fenetre.location.pathname}${fenetre.location.search}`
    const laisser = (raison) => {
        laisserLaCoquille(options)

        return { montee: false, raison }
    }

    // Ouverte à son adresse de fichier, hors du worker, elle n'a ni sa raison ni les en-têtes de l'application.
    if (!servieParLeWorker(document)) {
        return laisser('hors-du-worker')
    }

    let lecture

    try {
        lecture = await magasin().lireAuDemarrage(adresse)
    } catch {
        return laisser('indisponible')
    }

    if (lecture?.refus !== undefined) {
        return laisser(lecture.refus)
    }

    if (lecture?.plusRecente === null || lecture?.plusRecente === undefined) {
        return laisser('sans-copie')
    }

    const ziggy = lecture.copie?.ziggy ?? lecture.plusRecente.ziggy

    if (ziggy === null || typeof ziggy !== 'object') {
        return laisser('sans-routes')
    }

    let page

    try {
        page = pageAMonter({ lecture, adresse, raison: RAISON_DE_LA_MARQUE[raisonDeLaCoquille(document)] })
        ecrireLaPage(document, page)
    } catch {
        return laisser('illisible')
    }

    fenetre.Ziggy = ziggy
    noterQueLeServeurNeRepondPas()

    return { montee: true, raison: lecture.copie === null ? COMPOSANT_HORS_LIGNE : 'copie', page }
}
