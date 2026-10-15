import { describe, it, expect, vi, beforeAll, beforeEach, afterEach, afterAll } from 'vitest'
import { createApp, h } from 'vue'
import {
    CLEF_DE_L_HISTORIQUE,
    CLEF_DU_TITULAIRE_DE_L_APPAREIL,
    INVITE,
    installerLaGardeDeLHistorique,
} from '@/Utils/historiqueDuCompte'

/*
 * Plusieurs onglets, une seule session, avec le vrai client d'Inertia (#1965).
 *
 * Le serveur ne pose `clearHistory` que sur la première page qu'il rend après
 * un changement de titulaire, dans l'onglet qui la demande. Cet onglet-ci
 * reçoit donc les pages d'un autre titulaire SANS la consigne, comme le second
 * onglet d'un navigateur dont le compte est parti dans le premier, et doit
 * jeter sa clé lui-même : sans quoi le bouton Retour y relit, sans aucune
 * requête, les pages chiffrées du compte parti.
 *
 * jsdom ne joint pas de serveur : `XMLHttpRequest` est remplacé par un serveur
 * qui rend, pour chaque adresse, la page qu'on lui donne, et compte les
 * requêtes. L'entrée qu'Inertia chiffre dans l'historique se reconnaît comme
 * Inertia la reconnaît avant de la déchiffrer, par `instanceof ArrayBuffer` :
 * une entrée qu'il ne reconnaîtrait pas, il la redemanderait toujours au
 * serveur, avec ou sans clé. Un cas vérifie donc que le Retour relit bien
 * l'historique sans le serveur quand la clé est là : les autres ne passent pas
 * par hasard (`.ai/rules/tests-js.md`).
 *
 * Le routeur d'Inertia est unique par module, et les écouteurs qu'il pose sur
 * la fenêtre ne se retirent pas : une seconde application répondrait deux fois
 * au bouton Retour. L'onglet se monte donc une fois pour le fichier, comme
 * `main.js` le monte une fois pour le document. Chaque cas bâtit pourtant
 * lui-même l'historique qu'il remonte, par ses propres visites, face à un
 * serveur vidé avant lui (#2044) : aucun ne dépend de la page, de la clé ou du
 * titulaire que le cas d'avant a laissés, et l'ordre aléatoire
 * (`--sequence.shuffle`) n'y change rien.
 */

/** Les pages que le faux serveur rend, par chemin. */
const serveur = {}
/** Les chemins demandés au serveur. */
const requetes = []

class RequeteSimulee {
    upload = {}
    status = 0
    responseText = ''

    open(methode, url) {
        this.url = url
    }

    setRequestHeader() {}

    getAllResponseHeaders() {
        return 'x-inertia: true\r\ncontent-type: application/json'
    }

    abort() {
        this.onabort?.()
    }

    send() {
        const chemin = new URL(this.url, window.location.href).pathname
        requetes.push(chemin)

        queueMicrotask(() => {
            this.status = 200
            this.responseText = JSON.stringify(serveur[chemin])
            this.onload?.()
        })
    }
}

/** Une page de compte (`identifiant`), ou d'invité (`null`), telle que le serveur la rend. */
const page = (identifiant, url, contenu) => ({
    component: identifiant === null ? 'Auth/Login' : 'Compte/Page',
    url,
    version: 'v1',
    props: { errors: {}, auth: { user: identifiant === null ? null : { id: identifiant } }, contenu },
    encryptHistory: identifiant !== null,
})

const contenu = () => document.getElementById('contenu')?.textContent

/** Ce qui s'est affiché depuis le dernier `regarder()`, même fugacement. */
let vus = []
let observateur

const regarder = () => {
    vus = []
    observateur?.disconnect()
    observateur = new MutationObserver(() => vus.push(contenu()))
    observateur.observe(document.getElementById('app'), { childList: true, subtree: true, characterData: true })
}

let routeur

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})

    window.sessionStorage.clear()
    window.localStorage.clear()
    window.history.replaceState(null, '', '/daily-journals')

    // Le document tel que le serveur l'écrit : la page initiale, lue par la garde puis par Inertia.
    const initiale = document.createElement('script')
    initiale.dataset.page = 'app'
    initiale.type = 'application/json'
    initiale.textContent = JSON.stringify(page(1, '/daily-journals', 'Note privée de A'))
    document.body.replaceChildren(initiale, Object.assign(document.createElement('div'), { id: 'app' }))

    const inertia = await import('@inertiajs/vue3')
    routeur = inertia.router

    // Comme main.js : avant le démarrage d'Inertia.
    installerLaGardeDeLHistorique({ routeur })

    const Page = {
        props: ['contenu'],
        render() {
            return h('p', { id: 'contenu' }, this.contenu)
        },
    }

    await inertia.createInertiaApp({
        resolve: () => Page,
        setup: ({ el, App, props, plugin }) =>
            createApp({ render: () => h(App, props) })
                .use(plugin)
                .mount(el),
    })

    await vi.waitUntil(() => window.history.state?.page instanceof ArrayBuffer)
})

beforeEach(() => {
    requetes.length = 0

    for (const chemin of Object.keys(serveur)) {
        delete serveur[chemin]
    }
})

afterEach(() => {
    observateur?.disconnect()
    observateur = undefined
    vus = []
})

afterAll(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

/** Une visite d'Inertia, jusqu'à la page affichée. */
const visiter = async (adresse, reponse) => {
    serveur[new URL(adresse, window.location.href).pathname] = reponse
    let finie = false

    routeur.visit(adresse, { onFinish: () => (finie = true) })

    await vi.waitUntil(() => finie)
    await vi.waitUntil(() => contenu() === reponse.props.contenu)
}

/** Le bouton Retour, jusqu'à ce que la page affichée soit `attendu`. */
const revenir = async (attendu) => {
    window.history.back()

    await vi.waitUntil(() => contenu() === attendu, { timeout: 3000 })
}

describe('un onglet qui reçoit les pages d’un seul compte', () => {
    it('garde sa clé, et le Retour relit l’historique sans rien demander au serveur', async () => {
        await visiter('/daily-journals', page(1, '/daily-journals', 'Note privée de A'))
        const cle = window.sessionStorage.getItem(CLEF_DE_L_HISTORIQUE)
        await visiter('/profile', page(1, '/profile', 'Profil de A'))

        expect(window.sessionStorage.getItem(CLEF_DE_L_HISTORIQUE)).toBe(cle)
        requetes.length = 0

        await revenir('Note privée de A')

        expect(requetes).toEqual([])
    })
})

describe('un onglet qui reçoit la page d’un autre titulaire, sans `clearHistory`', () => {
    it('après le départ du compte dans un autre onglet : le Retour redemande la page au serveur', async () => {
        await visiter('/measurements', page(1, '/measurements', 'Mesures de A'))
        expect(window.sessionStorage.getItem(CLEF_DE_L_HISTORIQUE)).not.toBeNull()

        // Le compte s'est déconnecté dans un autre onglet : le serveur renvoie vers la connexion.
        await visiter('/stats', page(null, '/login', 'Connexion'))

        expect(window.sessionStorage.getItem(CLEF_DE_L_HISTORIQUE)).toBeNull()

        serveur['/measurements'] = page(null, '/login', 'Connexion depuis les mesures')
        regarder()
        requetes.length = 0

        await revenir('Connexion depuis les mesures')

        expect(requetes).toEqual(['/measurements'])
        expect(vus.filter((texte) => texte?.includes('de A'))).toEqual([])
    })

    it('un autre compte ne reprend pas la clé du précédent : le Retour ne relit rien du premier', async () => {
        await visiter('/daily-journals', page(1, '/daily-journals', 'Note privée de A'))
        const cleDeA = window.sessionStorage.getItem(CLEF_DE_L_HISTORIQUE)

        // B s'est connecté dans un autre onglet ; celui-ci ouvre le profil, qui est celui de B.
        await visiter('/profile', page(2, '/profile', 'Profil de B'))

        expect(window.sessionStorage.getItem(CLEF_DE_L_HISTORIQUE)).not.toBeNull()
        expect(window.sessionStorage.getItem(CLEF_DE_L_HISTORIQUE)).not.toBe(cleDeA)

        serveur['/daily-journals'] = page(2, '/daily-journals', 'Note de B')
        regarder()
        requetes.length = 0

        await revenir('Note de B')

        expect(requetes).toEqual(['/daily-journals'])
        expect(vus.filter((texte) => texte?.includes('de A'))).toEqual([])
    })
})

describe('un onglet resté sur une page du compte parti, sans rien visiter', () => {
    it('jette sa clé dès qu’un autre onglet reçoit la page d’un autre titulaire', async () => {
        await visiter('/daily-journals', page(2, '/daily-journals', 'Note de B'))
        await visiter('/measurements', page(2, '/measurements', 'Mesures de B'))
        expect(window.sessionStorage.getItem(CLEF_DE_L_HISTORIQUE)).not.toBeNull()

        // L'autre onglet : B s'y est déconnecté, et sa page de connexion l'a écrit.
        window.localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, INVITE)
        window.dispatchEvent(new StorageEvent('storage', { key: CLEF_DU_TITULAIRE_DE_L_APPAREIL, newValue: INVITE }))

        expect(window.sessionStorage.getItem(CLEF_DE_L_HISTORIQUE)).toBeNull()

        serveur['/daily-journals'] = page(null, '/login', 'Connexion depuis le journal')
        regarder()
        requetes.length = 0

        await revenir('Connexion depuis le journal')

        expect(requetes).toEqual(['/daily-journals'])
        expect(vus.filter((texte) => texte?.includes('de B'))).toEqual([])
    })

    it('la jette au bouton Retour, quand l’évènement de l’autre onglet ne lui est pas parvenu', async () => {
        await visiter('/daily-journals', page(1, '/daily-journals', 'Note privée de A'))
        await visiter('/profile', page(1, '/profile', 'Profil de A'))

        // Un onglet suspendu ne reçoit pas l'évènement : seul le stockage commun a changé.
        window.localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, 'compte:2')

        serveur['/daily-journals'] = page(2, '/daily-journals', 'Note de B')
        regarder()
        requetes.length = 0

        await revenir('Note de B')

        expect(requetes).toEqual(['/daily-journals'])
        expect(vus.filter((texte) => texte?.includes('de A'))).toEqual([])
    })
})

/**
 * Les huit premiers octets de l'entrée chiffrée de `chemin`, une fois écrite dans l'historique.
 *
 * @param {string} chemin
 * @returns {Promise<number[]>}
 */
const debutDeLEntree = async (chemin) => {
    await vi.waitUntil(() => window.location.pathname === chemin && window.history.state?.page instanceof ArrayBuffer)

    return Array.from(new Uint8Array(window.history.state.page, 0, 8))
}

/*
 * Deux pages du même compte commencent par les mêmes octets, ceux du début de
 * leur JSON. Chiffrées sous la même clé avec un vecteur d'initialisation propre
 * à chacune, leurs entrées ne commencent pas pareil ; Inertia, jusqu'à la
 * 3.9.0, gardait un seul vecteur pour tout l'onglet (@inertiajs/vue3 3.9.1).
 */
describe('deux pages chiffrées sous la même clé', () => {
    it('ne donnent pas deux entrées qui commencent par les mêmes octets', async () => {
        await visiter('/stats', page(2, '/stats', 'Statistiques de B'))
        const cle = window.sessionStorage.getItem(CLEF_DE_L_HISTORIQUE)
        const premiere = await debutDeLEntree('/stats')

        await visiter('/measurements', page(2, '/measurements', 'Mesures de B'))
        const seconde = await debutDeLEntree('/measurements')

        expect(cle).not.toBeNull()
        expect(window.sessionStorage.getItem(CLEF_DE_L_HISTORIQUE)).toBe(cle)
        expect(seconde).not.toEqual(premiere)
    })
})
