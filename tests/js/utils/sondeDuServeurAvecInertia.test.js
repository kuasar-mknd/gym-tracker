import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, it, expect, vi, beforeAll, beforeEach, afterEach, afterAll } from 'vitest'
import { createApp, h, nextTick } from 'vue'
import ActionsDeLaSeance from '@/Components/Workout/ActionsDeLaSeance.vue'
import { installerLesVisitesHorsLigne } from '@/Utils/visitesHorsLigne'
import {
    ADRESSE_DE_LA_SONDE,
    DELAI_DE_LA_SONDE_MS,
    PREMIER_RECUL_MS,
    RECUL_MAXIMAL_MS,
    installerLaSondeDuServeur,
} from '@/Utils/sondeDuServeur'
import { noterQueLeServeurRepond, useEtatDuReseau } from '@/composables/useEtatDuReseau'
import { RequeteSimulee, appelsDeFetch, fetchSimule, relayerLesRejetsNonGeres, requetes, reseau } from './reseauSimule'
import { jsRoot } from '../conventions/sourceFiles'

/*
 * Le réseau revient, la page le sait (#2048), avec le vrai client d'Inertia.
 *
 * `joignable` ne redevenait vrai qu'à la prochaine réponse réussie : le réseau
 * revenu sans un geste, « Modèle », « Recharger » et les réglages de la
 * séance restaient désactivés. La sonde le demande au serveur, au retour de
 * l'interface réseau et de loin en loin ; seule une réponse 2xx rend
 * `joignable`, jamais l'évènement `online` seul.
 */

let routeur
let application
let relais
let debrancherLaSonde

const boutonModele = () => document.querySelector('[dusk="enregistrer-comme-modele"]')

/** Laisse passer les microtâches et les minuteries dues, sans avancer l'horloge. */
const laisserPasser = async () => {
    await vi.advanceTimersByTimeAsync(0)
    await nextTick()
}

/** Une visite au premier plan, réseau coupé : le serveur ne répond plus. */
const perdreLeServeur = async () => {
    reseau.mode = 'coupe'
    routeur.visit('/stats')
    await laisserPasser()

    expect(useEtatDuReseau().joignable.value).toBe(false)
}

/** La page cachée ou montrée, comme quand on change d'application. */
let visibilite = 'visible'
const montrerLaPage = (etat) => {
    visibilite = etat
    document.dispatchEvent(new Event('visibilitychange'))
}

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.stubGlobal('fetch', fetchSimule)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => visibilite })
    document.body.innerHTML = '<div id="app"></div>'
    window.history.replaceState(null, '', '/workouts/12')
    globalThis.route = (nom) => `/${nom}`

    const inertia = await import('@inertiajs/vue3')
    routeur = inertia.router

    installerLesVisitesHorsLigne({ routeur })

    const Page = { render: () => h(ActionsDeLaSeance) }

    await inertia.createInertiaApp({
        page: { component: 'Workouts/Show', url: '/workouts/12', version: 'v', props: { errors: {} } },
        resolve: () => Page,
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) })
            application.directive('press', {})
            application.use(plugin).mount(el)
        },
    })
})

beforeEach(() => {
    vi.useFakeTimers({ toFake: ['setTimeout', 'clearTimeout', 'Date'] })
    requetes.length = 0
    appelsDeFetch.length = 0
    reseau.mode = 'coupe'
    reseau.prochains.length = 0
    delete reseau.statutDeFetch
    visibilite = 'visible'
    noterQueLeServeurRepond()
    debrancherLaSonde = installerLaSondeDuServeur()
    relais = relayerLesRejetsNonGeres()
})

afterEach(() => {
    debrancherLaSonde()
    relais.retirer()
    vi.useRealTimers()
})

afterAll(() => {
    application.unmount()
    delete document.visibilityState
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

describe('au retour du réseau', () => {
    it('réactive « Modèle » sans un geste, sur la seule réponse de la sonde', async () => {
        await perdreLeServeur()
        await nextTick()

        expect(boutonModele().disabled).toBe(true)
        expect(appelsDeFetch).toEqual([])

        // L'interface revient, le serveur pas encore : la sonde part et échoue, rien ne change.
        window.dispatchEvent(new Event('online'))
        await laisserPasser()

        expect(appelsDeFetch).toHaveLength(1)
        expect(useEtatDuReseau().joignable.value).toBe(false)
        expect(boutonModele().disabled).toBe(true)

        // Le serveur répond : la sonde suivante, après le recul, le rend joignable.
        reseau.mode = 'repond'
        await vi.advanceTimersByTimeAsync(PREMIER_RECUL_MS * 2 - 1)

        expect(appelsDeFetch).toHaveLength(1)

        await vi.advanceTimersByTimeAsync(1)
        await nextTick()

        expect(appelsDeFetch).toHaveLength(2)
        expect(useEtatDuReseau().joignable.value).toBe(true)
        expect(boutonModele().disabled).toBe(false)
        // Aucune visite d'Inertia n'y est pour rien : seule la visite échouée est partie.
        expect(requetes).toHaveLength(1)
        expect(relais.rejets).toEqual([])
    })

    it('demande la sonde tout de suite, et non le serveur au hasard d’une page', async () => {
        await perdreLeServeur()
        reseau.mode = 'repond'

        window.dispatchEvent(new Event('online'))
        await laisserPasser()

        expect(appelsDeFetch).toHaveLength(1)
        expect(useEtatDuReseau().joignable.value).toBe(true)
    })

    it('part sur la même origine, sans cache, sans cookie, sans l’adresse de la page, sans corps, et bornée dans le temps', async () => {
        await perdreLeServeur()
        window.dispatchEvent(new Event('online'))
        await laisserPasser()

        const [{ adresse, options }] = appelsDeFetch

        expect(adresse).toBe(ADRESSE_DE_LA_SONDE)
        expect(adresse).toMatch(/^\/[^/]/)
        expect(options).toEqual({
            method: 'GET',
            cache: 'no-store',
            credentials: 'omit',
            referrerPolicy: 'no-referrer',
            redirect: 'error',
            signal: expect.any(AbortSignal),
        })
    })

    it('ne prend pas une réponse d’erreur pour le retour du serveur', async () => {
        await perdreLeServeur()
        reseau.mode = 'repond'
        reseau.statutDeFetch = 503

        window.dispatchEvent(new Event('online'))
        await laisserPasser()

        expect(appelsDeFetch).toHaveLength(1)
        expect(useEtatDuReseau().joignable.value).toBe(false)
    })

    it('abandonne une sonde sans réponse au bout du délai, et réessaie après le recul', async () => {
        await perdreLeServeur()
        reseau.mode = 'muet'

        window.dispatchEvent(new Event('online'))
        await laisserPasser()
        const [{ options }] = appelsDeFetch

        await vi.advanceTimersByTimeAsync(DELAI_DE_LA_SONDE_MS - 1)

        expect(options.signal.aborted).toBe(false)

        await vi.advanceTimersByTimeAsync(1)

        expect(options.signal.aborted).toBe(true)
        expect(useEtatDuReseau().joignable.value).toBe(false)

        reseau.mode = 'repond'
        await vi.advanceTimersByTimeAsync(PREMIER_RECUL_MS * 2)

        expect(appelsDeFetch).toHaveLength(2)
        expect(useEtatDuReseau().joignable.value).toBe(true)
    })

    /*
     * L'interface revenue remet le recul au premier délai : un serveur qui
     * redémarre encore quand le réseau revient se redemande dix secondes après,
     * pas au bout de la minute déjà atteinte.
     */
    it('remet le recul au premier délai, même monté à une minute', async () => {
        await perdreLeServeur()
        await vi.advanceTimersByTimeAsync(5000 + 10000 + 20000 + 40000 + 60000)

        expect(appelsDeFetch).toHaveLength(5)

        // Le réseau revient au milieu de la minute d'attente, le serveur pas encore.
        await vi.advanceTimersByTimeAsync(20000)
        window.dispatchEvent(new Event('online'))
        await laisserPasser()

        expect(appelsDeFetch).toHaveLength(6)

        await vi.advanceTimersByTimeAsync(PREMIER_RECUL_MS * 2 - 1)

        expect(appelsDeFetch).toHaveLength(6)

        await vi.advanceTimersByTimeAsync(1)

        expect(appelsDeFetch).toHaveLength(7)
        expect(useEtatDuReseau().joignable.value).toBe(false)
    })

    it('ne demande rien quand le serveur répond déjà', async () => {
        window.dispatchEvent(new Event('online'))
        await vi.advanceTimersByTimeAsync(RECUL_MAXIMAL_MS * 5)

        expect(appelsDeFetch).toEqual([])
    })
})

describe('tant que le serveur ne répond pas', () => {
    it('redemande de loin en loin, le recul doublant jusqu’à une minute', async () => {
        await perdreLeServeur()
        const ecarts = []
        let precedente = Date.now()

        // Borné : sans sonde, la boucle s'arrête au bout de cinq minutes simulées.
        for (let seconde = 0; seconde < 300 && appelsDeFetch.length < 7; seconde++) {
            const avant = appelsDeFetch.length
            await vi.advanceTimersByTimeAsync(1000)

            if (appelsDeFetch.length > avant) {
                ecarts.push(Date.now() - precedente)
                precedente = Date.now()
            }
        }

        expect(ecarts).toEqual([5000, 10000, 20000, 40000, 60000, 60000, 60000])
        expect(useEtatDuReseau().joignable.value).toBe(false)
    })

    /*
     * Le premier délai part de l'échec : une page ouverte depuis longtemps ne
     * sonde pas à l'instant même où le serveur cesse de répondre.
     */
    it('compte le premier délai depuis l’échec, pas depuis le chargement de la page', async () => {
        await vi.advanceTimersByTimeAsync(10 * 60 * 1000)
        await perdreLeServeur()

        await vi.advanceTimersByTimeAsync(PREMIER_RECUL_MS - 1)

        expect(appelsDeFetch).toHaveLength(0)

        await vi.advanceTimersByTimeAsync(1)

        expect(appelsDeFetch).toHaveLength(1)
    })

    /*
     * Une seconde panne recommence à cinq secondes : le recul d'une panne
     * passée ne la ferait pas attendre une minute.
     */
    it('repart du premier délai après un retour du serveur', async () => {
        await perdreLeServeur()
        await vi.advanceTimersByTimeAsync(5000 + 10000 + 20000 + 40000 + 60000)

        expect(appelsDeFetch).toHaveLength(5)

        reseau.mode = 'repond'
        await vi.advanceTimersByTimeAsync(RECUL_MAXIMAL_MS)

        expect(appelsDeFetch).toHaveLength(6)
        expect(useEtatDuReseau().joignable.value).toBe(true)

        appelsDeFetch.length = 0
        await perdreLeServeur()
        await vi.advanceTimersByTimeAsync(PREMIER_RECUL_MS - 1)

        expect(appelsDeFetch).toHaveLength(0)

        await vi.advanceTimersByTimeAsync(1)

        expect(appelsDeFetch).toHaveLength(1)
    })

    it('ne demande rien page cachée, et demande aussitôt au retour au premier plan', async () => {
        await perdreLeServeur()
        montrerLaPage('hidden')

        await vi.advanceTimersByTimeAsync(RECUL_MAXIMAL_MS * 10)
        window.dispatchEvent(new Event('online'))
        await vi.advanceTimersByTimeAsync(RECUL_MAXIMAL_MS)

        expect(appelsDeFetch).toEqual([])

        reseau.mode = 'repond'
        montrerLaPage('visible')
        await laisserPasser()

        expect(appelsDeFetch).toHaveLength(1)
        expect(useEtatDuReseau().joignable.value).toBe(true)
    })

    it('s’arrête dès qu’une réponse du serveur arrive par une autre voie', async () => {
        await perdreLeServeur()
        noterQueLeServeurRepond()

        await vi.advanceTimersByTimeAsync(RECUL_MAXIMAL_MS * 5)

        expect(appelsDeFetch).toEqual([])
    })
})

describe('le branchement', () => {
    it('est posé par main.js, avant le montage de l’application', () => {
        const main = readFileSync(resolve(jsRoot, 'main.js'), 'utf-8')
        const pose = main.indexOf('installerLaSondeDuServeur()')

        expect(pose).toBeGreaterThan(-1)
        expect(pose).toBeLessThan(main.indexOf('createInertiaApp({'))
    })
})
