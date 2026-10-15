import { describe, it, expect, vi, beforeAll, beforeEach, afterEach, afterAll } from 'vitest'
import { createApp, h } from 'vue'
import { installerLesVisitesHorsLigne, PAGE_INDISPONIBLE } from '@/Utils/visitesHorsLigne'
import { RequeteSimulee, relayerLesRejetsNonGeres, requetes, reseau, uneTache } from '../utils/reseauSimule'

/*
 * Une page d'invité sans réseau, avec le vrai client d'Inertia (#2048).
 *
 * Le filet des visites retire au rapporteur l'échec réseau d'une visite et
 * l'annonce par l'évènement `gym:sans-reseau`, que seul le layout authentifié
 * écoutait. Sous le layout invité, rien ne le disait : « Se déconnecter » sur
 * la vérification de l'adresse ne faisait rien, et la personne restait
 * connectée sans le savoir. Les deux layouts montent désormais les mêmes
 * toasts.
 */

// Le détachement de l'appareil et la file ont leurs propres tests.
vi.mock('@/composables/useAbonnementPush', () => ({
    detacherLAppareil: () => Promise.resolve(),
    marquerLAbonnementARetransmettre: () => {},
}))
vi.mock('@/Utils/SyncService', () => ({
    default: { compte: '1', enAttente: () => 0, clearFailedRequests: () => {} },
}))

let routeur
let DECONNEXION_SANS_RESEAU
let application
let relais

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    document.body.innerHTML = '<div id="app"></div>'
    window.history.replaceState(null, '', '/verify-email')
    globalThis.route = (nom) => `/${nom}`

    const inertia = await import('@inertiajs/vue3')
    routeur = inertia.router
    ;({ DECONNEXION_SANS_RESEAU } = await import('@/composables/useDeconnexion'))
    const VerifyEmail = (await import('@/Pages/Auth/VerifyEmail.vue')).default

    installerLesVisitesHorsLigne({ routeur })

    await inertia.createInertiaApp({
        page: {
            component: 'Auth/VerifyEmail',
            url: '/verify-email',
            version: 'v',
            props: { errors: {}, auth: { user: { id: 1 } }, status: null },
        },
        resolve: () => VerifyEmail,
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) })
            application.directive('press', {})
            application.use(plugin).mount(el)
        },
    })
})

beforeEach(() => {
    requetes.length = 0
    reseau.mode = 'coupe'
    relais = relayerLesRejetsNonGeres()
})

afterEach(() => {
    relais.retirer()
})

afterAll(() => {
    application.unmount()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

/** Attend la fin de la prochaine visite, puis une tâche. */
const attendreLaFinDeLaVisite = () => {
    let finie = false
    const retirer = routeur.on('finish', () => {
        finie = true
    })

    return async () => {
        await vi.waitUntil(() => finie)
        retirer()
        await uneTache()
    }
}

const boutonNomme = (nom) =>
    [...document.querySelectorAll('button')].find((bouton) => bouton.textContent.trim() === nom)

const toastsAffiches = () => [...document.querySelectorAll('[role="alert"] p')].map((p) => p.textContent.trim())

describe('une page d’invité sans réseau', () => {
    it('dit qu’une déconnexion depuis la vérification de l’adresse n’a pas eu lieu', async () => {
        const fin = attendreLaFinDeLaVisite()
        boutonNomme('Se déconnecter').click()
        await fin()

        expect(requetes.map((requete) => requete.url)).toEqual([expect.stringMatching(/\/logout$/)])
        expect(toastsAffiches()).toEqual([DECONNEXION_SANS_RESEAU])
        expect(relais.rejets).toEqual([])
    })

    it('dit qu’une page n’est pas disponible quand on touche le logo', async () => {
        const fin = attendreLaFinDeLaVisite()
        document.querySelector('a[href="/"]').click()
        await fin()

        expect(requetes).toHaveLength(1)
        expect(toastsAffiches()).toEqual([PAGE_INDISPONIBLE])
        expect(relais.rejets).toEqual([])
    })
})
