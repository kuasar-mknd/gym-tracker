import { describe, it, expect, vi, beforeAll, afterAll } from 'vitest'
import { createApp, h } from 'vue'
import { RequeteSimulee, requetes, reseau } from '../utils/reseauSimule'

/*
 * Le formulaire du profil qui change le mot de passe, avec le vrai client
 * d'Inertia (#PR). Il porte l'adresse du compte connecté dans un champ
 * caché `autocomplete="username"`, pour qu'un gestionnaire de mots de passe
 * range le nouveau mot de passe sous ce compte ; ce champ ne part pas avec le
 * formulaire. Ce fichier lit la requête que le client envoie vraiment : un
 * faux `useForm` ne dirait que ce que le test lui fait dire.
 *
 * Le client d'Inertia se monte une fois pour le fichier, qui n'a qu'un cas.
 */

// La retransmission de l'abonnement push a ses propres tests.
vi.mock('@/composables/useAbonnementPush', () => ({ retransmettreLAbonnementPush: () => Promise.resolve() }))

const ADRESSE_DU_COMPTE = 'titulaire.du-profil@example.org'

/** Le formulaire seul, rendu comme page : c'est lui qu'on lit. */
const PAGE_DU_PROFIL = {
    component: 'Profile/MotDePasse',
    url: '/profile/edit',
    version: 'v1',
    props: {
        errors: {},
        auth: { user: { id: 7, email: ADRESSE_DU_COMPTE } },
        flash: { success: null, error: null },
    },
}

let application
let routeur

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    reseau.mode = 'muet'
    reseau.prochains = []
    requetes.length = 0

    document.body.innerHTML = '<div id="app"></div>'
    window.history.replaceState(null, '', PAGE_DU_PROFIL.url)
    globalThis.route = (nom) => `/${nom}`

    const inertia = await import('@inertiajs/vue3')
    routeur = inertia.router
    const UpdatePasswordForm = (await import('@/Pages/Profile/Partials/UpdatePasswordForm.vue')).default

    await inertia.createInertiaApp({
        page: PAGE_DU_PROFIL,
        resolve: () => UpdatePasswordForm,
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) })
            application.directive('press', {})
            application.config.globalProperties.route = globalThis.route
            application.use(plugin).mount(el)
        },
    })

    await vi.waitUntil(() => document.querySelector('form'))
})

afterAll(() => {
    // La requête restée sans réponse s'abandonne avant le démontage.
    routeur?.cancelAll()
    application?.unmount()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
    delete globalThis.route
})

/** Tape `valeur` dans le champ dont l'attribut `dusk` est donné. */
const taper = (dusk, valeur) => {
    const champ = document.querySelector(`[dusk="${dusk}"]`)
    champ.value = valeur
    champ.dispatchEvent(new Event('input'))
}

describe('le changement du mot de passe au profil', () => {
    it('offre l’adresse du compte aux gestionnaires de mots de passe, et ne l’envoie pas', async () => {
        const formulaire = document.querySelector('form')
        const identifiant = formulaire.querySelector('input[autocomplete="username"]')

        expect(identifiant.value).toBe(ADRESSE_DU_COMPTE)
        expect(identifiant.hidden).toBe(true)

        taper('current-password-input', 'Ancien-mot-de-passe-1')
        taper('new-password-input', 'Nouveau-mot-de-passe-2')
        taper('confirm-password-input', 'Nouveau-mot-de-passe-2')
        formulaire.requestSubmit()

        await vi.waitUntil(() => requetes.length === 1)
        const [envoi] = requetes

        expect([envoi.methode.toUpperCase(), new URL(envoi.url).pathname]).toEqual(['PUT', '/password.update'])
        expect(JSON.parse(envoi.corps)).toEqual({
            current_password: 'Ancien-mot-de-passe-1',
            password: 'Nouveau-mot-de-passe-2',
            password_confirmation: 'Nouveau-mot-de-passe-2',
        })
        expect(envoi.corps).not.toContain('titulaire.du-profil')
    })
})
