import { describe, it, expect, vi, beforeAll, beforeEach, afterEach, afterAll } from 'vitest'
import { createApp, h } from 'vue'
import {
    CONNEXION_SANS_RESEAU,
    ECRITURE_NON_CONFIRMEE,
    ENVOI_DU_LIEN_NON_CONFIRME,
    INSCRIPTION_NON_CONFIRMEE,
    NOUVEAU_MOT_DE_PASSE_NON_CONFIRME,
    PAGE_INDISPONIBLE,
    VERIFICATION_DU_MOT_DE_PASSE_NON_CONFIRMEE,
    installerLesVisitesHorsLigne,
} from '@/Utils/visitesHorsLigne'
import {
    RequeteSimulee,
    relayerLesRejetsNonGeres,
    reponseInertia,
    requetes,
    reseau,
    uneTache,
} from '../utils/reseauSimule'

/*
 * Une page d'invité sans réseau, avec le vrai client d'Inertia (#2048).
 *
 * Le filet des visites retire au rapporteur l'échec réseau d'une visite et
 * l'annonce par l'évènement `gym:sans-reseau`, que seul le layout authentifié
 * écoutait. Sous le layout invité, rien ne le disait : « Se déconnecter » sur
 * la vérification de l'adresse ne faisait rien, et la personne restait
 * connectée sans le savoir. Les deux layouts montent désormais les mêmes
 * toasts.
 *
 * Et chaque formulaire d'invité dit le sien : aucun n'enregistre rien, et le
 * message général d'une écriture, « l'enregistrement n'est pas confirmé »,
 * était faux pour une connexion comme pour une demande de lien.
 *
 * Le client d'Inertia se monte une fois pour le fichier. Chaque cas retrouve
 * pourtant la vérification de l'adresse telle qu'elle s'est ouverte, sans
 * toast (#2044) : un formulaire ouvre sa propre page, et un cas tiré après lui
 * en ordre aléatoire (`--sequence.shuffle`) n'y trouvait plus « Se déconnecter ».
 */

// Le détachement de l'appareil et la file ont leurs propres tests.
vi.mock('@/composables/useAbonnementPush', () => ({
    detacherLAppareil: () => Promise.resolve(),
    marquerLAbonnementARetransmettre: () => {},
}))
vi.mock('@/Utils/SyncService', () => ({
    default: { compte: '1', enAttente: () => 0, clearFailedRequests: () => {} },
}))

/** La vérification de l'adresse, telle que le serveur l'a rendue. */
const verificationDeLAdresse = () => ({
    component: 'Auth/VerifyEmail',
    url: '/verify-email',
    version: 'v',
    props: { errors: {}, auth: { user: { id: 1 } }, status: null },
})

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
    const pages = {
        'Auth/ConfirmPassword': (await import('@/Pages/Auth/ConfirmPassword.vue')).default,
        'Auth/ForgotPassword': (await import('@/Pages/Auth/ForgotPassword.vue')).default,
        'Auth/Login': (await import('@/Pages/Auth/Login.vue')).default,
        'Auth/Register': (await import('@/Pages/Auth/Register.vue')).default,
        'Auth/ResetPassword': (await import('@/Pages/Auth/ResetPassword.vue')).default,
        'Auth/VerifyEmail': (await import('@/Pages/Auth/VerifyEmail.vue')).default,
    }

    installerLesVisitesHorsLigne({ routeur })

    await inertia.createInertiaApp({
        page: verificationDeLAdresse(),
        resolve: (nom) => pages[nom],
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) })
            application.directive('press', {})
            application.config.globalProperties.route = globalThis.route
            application.use(plugin).mount(el)
        },
    })
})

beforeEach(async () => {
    // Une visite du client seul, sans requête : la page revient, remontée, telle qu'elle s'est ouverte.
    let remise = false
    routeur.replace({ ...verificationDeLAdresse(), onFinish: () => (remise = true) })
    await vi.waitUntil(() => remise)
    await uneTache()

    requetes.length = 0
    reseau.mode = 'coupe'
    reseau.prochains = []
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

/** Les toasts du layout ; l'erreur cachée d'un champ porte aussi `role="alert"`, mais pas `aria-live`. */
const toastsAffiches = () =>
    [...document.querySelectorAll('[role="alert"][aria-live] p')].map((p) => p.textContent.trim())

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

/**
 * Ouvre `composant` comme le serveur l'enverrait, puis coupe le réseau.
 *
 * @param {string} composant
 * @param {string} adresse
 * @param {Record<string, unknown>} props
 */
const ouvrirLaPage = async (composant, adresse, props = {}) => {
    reseau.mode = 'repond'
    reseau.reponse = reponseInertia({ component: composant, url: adresse, version: 'v', props })

    await new Promise((resoudre) => routeur.visit(adresse, { onFinish: resoudre }))
    await uneTache()

    requetes.length = 0
    reseau.mode = 'coupe'
}

describe('un formulaire d’invité envoyé sans réseau', () => {
    it.each([
        ['Auth/Login', '/login', { auth: { user: null } }, 'login', CONNEXION_SANS_RESEAU],
        ['Auth/Register', '/register', { auth: { user: null } }, 'register', INSCRIPTION_NON_CONFIRMEE],
        [
            'Auth/ForgotPassword',
            '/forgot-password',
            { auth: { user: null } },
            'password.email',
            ENVOI_DU_LIEN_NON_CONFIRME,
        ],
        [
            'Auth/ResetPassword',
            '/reset-password/jeton',
            { auth: { user: null }, email: 'sam@example.org', token: 'jeton' },
            'password.store',
            NOUVEAU_MOT_DE_PASSE_NON_CONFIRME,
        ],
        [
            'Auth/ConfirmPassword',
            '/confirm-password',
            { auth: { user: { id: 1 } } },
            'password.confirm',
            VERIFICATION_DU_MOT_DE_PASSE_NON_CONFIRMEE,
        ],
        [
            'Auth/VerifyEmail',
            '/verify-email',
            { auth: { user: { id: 1 } } },
            'verification.send',
            ENVOI_DU_LIEN_NON_CONFIRME,
        ],
    ])('%s dit ce qui manque, sans parler d’enregistrement', async (composant, adresse, props, route, message) => {
        await ouvrirLaPage(composant, adresse, props)

        const fin = attendreLaFinDeLaVisite()
        document.querySelector('form').dispatchEvent(new Event('submit', { cancelable: true }))
        await fin()

        expect(requetes.map((requete) => `${requete.methode} ${new URL(requete.url).pathname}`)).toEqual([
            `POST /${route}`,
        ])
        expect(toastsAffiches()).toEqual([message])
        expect(toastsAffiches()).not.toContain(ECRITURE_NON_CONFIRMEE)
        expect(relais.rejets).toEqual([])
    })
})
