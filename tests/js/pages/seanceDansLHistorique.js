import { vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { h } from 'vue'

import { chargerSyncService, poserLaPage, retirerLesEcouteursDuService } from '../utils/fileHorsLigne'
import { routeDeTest } from '../composables/seanceHorsLigne'

/*
 * La liste des séances et la séance 5, servies par le vrai client d'Inertia,
 * pour jouer les boutons Retour et Avance du navigateur sur la vraie page de
 * séance (#2048).
 *
 * jsdom ne joint pas de serveur : les visites d'Inertia passent par une
 * `XMLHttpRequest` simulée, qui rend chaque page d'après ce que le faux serveur
 * de `seanceHorsLigne.js` a en base, et les écritures de la séance par ce faux
 * serveur, qu'on coupe. jsdom recopie aussi `history.state` dans un autre
 * domaine, où le `instanceof ArrayBuffer` d'Inertia ne reconnaît plus l'entrée
 * chiffrée : elle est reconnue à sa marque, comme dans un navigateur
 * (`.ai/rules/tests-js.md`).
 *
 * Le client d'Inertia ne démarre qu'une fois par fichier : un fichier, un test.
 * Le fichier déclare ses `vi.mock` (`@/Utils/http` vers le faux serveur,
 * `useHaptics`, le glisser-déposer, la mise en page et les modales), passe à
 * `ouvrirLaListeDesSeances` l'import de la vraie page et de quoi retenir son
 * montage (`pages.retenir`), et ouvre son `afterEach` par
 * `await pages.demonterToutes()`, puis `fermerLaListeDesSeances()`. La page s'importe APRÈS le service de
 * synchronisation, qui oublie les modules chargés : importée avant, elle
 * garderait un autre service et un autre client d'Inertia que ceux du test.
 */

export const SQUAT = { id: 7, name: 'Squat', type: 'strength', category: 'Jambes', default_rest_time: 90 }
export const DEVELOPPE_COUCHE = {
    id: 8,
    name: 'Développé couché',
    type: 'strength',
    category: 'Pectoraux',
    default_rest_time: 90,
}

const EXERCICES = [SQUAT, DEVELOPPE_COUCHE]

/**
 * La séance telle que le serveur la rend maintenant : les lignes de la séance 5
 * qu'il a en base, et leurs séries.
 *
 * @param {ReturnType<import('../composables/seanceHorsLigne').creerUnFauxServeur>} serveur
 */
const seanceDuServeur = (serveur) => ({
    id: 5,
    name: 'Séance du soir',
    started_at: '2026-10-12T17:00:00.000000Z',
    ended_at: null,
    notes: null,
    workout_lines: [...serveur.lignes.values()]
        .filter((ligne) => ligne.workout_id === 5)
        .sort((a, b) => a.id - b.id)
        .map((ligne, rang) => ({
            id: ligne.id,
            exercise_id: ligne.exercise_id,
            exercise: EXERCICES.find((exercice) => exercice.id === ligne.exercise_id),
            order: rang,
            notes: null,
            recommended_values: null,
            sets: [...serveur.series.values()]
                .filter((serie) => serie.workout_line_id === ligne.id)
                .sort((a, b) => a.id - b.id)
                .map((serie) => ({ is_warmup: false, personal_record: null, ...serie })),
        })),
})

/** Les pages de chaque adresse, comme le serveur les rend. */
const pagesDuServeur = (serveur) => ({
    '/workouts': () => ({
        component: 'Workouts/Index',
        url: '/workouts',
        version: 'v1',
        props: { errors: {}, flash: {}, auth: { user: { id: 1 } } },
        encryptHistory: true,
    }),
    '/workouts/5': () => ({
        component: 'Workouts/Show',
        url: '/workouts/5',
        version: 'v1',
        props: {
            errors: {},
            flash: {},
            auth: { user: { id: 1 } },
            workout: seanceDuServeur(serveur),
            exercises: EXERCICES,
            bornesDUneSerie: null,
        },
        encryptHistory: true,
    }),
})

const ListeDesSeances = { name: 'ListeDesSeances', render: () => h('p', { id: 'liste-des-seances' }, 'Séances') }

const reconnaitreUnArrayBuffer = Object.getOwnPropertyDescriptor(ArrayBuffer, Symbol.hasInstance)

/** Le sélecteur trouve un élément dans la page. */
export const present = (selecteur) => document.querySelector(selecteur) !== null

/** Les séries de l'exercice affichées, cochées ou non. */
export const rangees = (exercice = 0) => document.querySelectorAll(`[dusk^="complete-set-${exercice}-"]`).length

export const cliquer = async (selecteur) => {
    document.querySelector(selecteur).click()
    await flushPromises()
}

/** Une saisie dans un champ de série, jusqu'à la sortie du champ. */
export const saisir = async (selecteur, valeur) => {
    const champ = document.querySelector(selecteur)
    champ.value = valeur
    champ.dispatchEvent(new Event('input'))
    champ.dispatchEvent(new Event('change'))
    await flushPromises()
}

/** Les opérations de chiffrement en cours, que compte `ouvrirLaListeDesSeances`. */
let chiffrementsEnCours = 0

/**
 * Attend qu'Inertia ait écrit dans l'historique ce qu'il chiffre encore.
 *
 * Inertia chiffre chaque entrée avant de l'écrire, l'une après l'autre, dans
 * l'entrée courante au moment de l'écriture : ce qu'il chiffre encore quand la
 * personne appuie sur Retour tombe dans l'entrée d'avant. Personne n'appuie
 * dans la milliseconde qui suit une écriture ; le test attend comme elle, que
 * plus rien ne se chiffre une fois les promesses vidées.
 */
export const historiqueEcrit = async () => {
    do {
        await flushPromises()
    } while (chiffrementsEnCours > 0)
}

/** Un bouton du navigateur, une fois l'historique écrit, jusqu'à la page affichée. */
export const naviguerDansLHistorique = async (sens, selecteur) => {
    await historiqueEcrit()
    window.history[sens]()
    await vi.waitUntil(() => present(selecteur), { timeout: 3000 })
    await flushPromises()
}

/**
 * La prochaine requête n'atteint le serveur qu'une fois libérée : elle vole
 * pendant que la personne fait autre chose.
 *
 * @param {ReturnType<import('../composables/seanceHorsLigne').creerUnFauxServeur>} serveur
 * @returns {() => void} libère la requête
 */
export const uneReponseRetenue = (serveur) => {
    let liberer
    const liberation = new Promise((resolve) => (liberer = resolve))
    const repondre = serveur.repondre

    serveur.repondre = async (config) => {
        serveur.repondre = repondre
        await liberation

        return repondre(config)
    }

    return () => liberer()
}

/**
 * Un chargement de `/workouts` : le service de synchronisation d'abord, puis
 * Inertia, qui partagent ce chargement.
 *
 * @param {{
 *   serveur: ReturnType<import('../composables/seanceHorsLigne').creerUnFauxServeur>,
 *   chargerLaSeance: () => Promise<{ default: object }>,
 *   retenir: (application: import('@vue/test-utils').VueWrapper) => void,
 * }} chargement le faux serveur, l'import de la vraie page de séance, et ce
 *   qui retient le montage pour le démonter après le test
 * @returns {Promise<{
 *   sync: object,
 *   visites: Array<string>,
 *   visiter: (adresse: string, selecteur: string) => Promise<void>,
 * }>} le service de synchronisation, les chemins que les visites d'Inertia ont
 *   demandés au serveur, et une visite jusqu'à la page affichée
 */
export const ouvrirLaListeDesSeances = async ({ serveur, chargerLaSeance, retenir }) => {
    const pagesDeLAdresse = pagesDuServeur(serveur)
    const visites = []

    class RequeteSimulee {
        upload = {}
        status = 0
        responseText = ''

        open(_methode, url) {
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
            visites.push(chemin)

            queueMicrotask(() => {
                this.status = 200
                this.responseText = JSON.stringify(pagesDeLAdresse[chemin]())
                this.onload?.()
            })
        }
    }

    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})

    for (const operation of ['generateKey', 'exportKey', 'importKey', 'encrypt', 'decrypt']) {
        const originale = window.crypto.subtle[operation].bind(window.crypto.subtle)

        vi.spyOn(window.crypto.subtle, operation).mockImplementation((...parametres) => {
            chiffrementsEnCours++

            return originale(...parametres).finally(() => chiffrementsEnCours--)
        })
    }
    Object.defineProperty(ArrayBuffer, Symbol.hasInstance, {
        value: (valeur) => Object.prototype.toString.call(valeur) === '[object ArrayBuffer]',
        configurable: true,
    })

    localStorage.clear()
    sessionStorage.clear()
    globalThis.route = routeDeTest

    const sync = await chargerSyncService({ compte: 1 })
    await sync.pending

    window.history.replaceState(null, '', '/workouts')
    const initiale = document.createElement('script')
    initiale.dataset.page = 'app'
    initiale.type = 'application/json'
    initiale.textContent = JSON.stringify(pagesDeLAdresse['/workouts']())
    document.body.replaceChildren(initiale, Object.assign(document.createElement('div'), { id: 'app' }))

    const inertia = await import('@inertiajs/vue3')
    const { default: Seance } = await chargerLaSeance()

    await inertia.createInertiaApp({
        resolve: (nom) => (nom === 'Workouts/Show' ? Seance : ListeDesSeances),
        setup: ({ el, App, props, plugin }) => {
            retenir(mount(App, { props, attachTo: el, global: { plugins: [plugin], directives: { press: {} } } }))
        },
    })

    await vi.waitUntil(() => present('#liste-des-seances'))

    const visiter = async (adresse, selecteur) => {
        inertia.router.visit(adresse)
        await vi.waitUntil(() => present(selecteur))
        await flushPromises()
    }

    return { sync, visites, visiter }
}

/** Ce qu'`ouvrirLaListeDesSeances` a posé, retiré après le démontage des pages. */
export const fermerLaListeDesSeances = () => {
    retirerLesEcouteursDuService()
    poserLaPage(null)

    if (reconnaitreUnArrayBuffer) {
        Object.defineProperty(ArrayBuffer, Symbol.hasInstance, reconnaitreUnArrayBuffer)
    } else {
        delete ArrayBuffer[Symbol.hasInstance]
    }

    vi.unstubAllGlobals()
    vi.restoreAllMocks()
    localStorage.clear()
    sessionStorage.clear()
}
