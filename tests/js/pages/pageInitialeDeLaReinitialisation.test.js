import { describe, it, expect, vi, beforeAll, afterAll } from 'vitest'
import { createApp, h } from 'vue'

/*
 * La page initiale du document, après la page d'un lien de réinitialisation
 * qui vaut, avec le vrai client d'Inertia.
 *
 * Un chargement complet écrit la page dans une balise
 * `<script data-page="app" type="application/json">`. Inertia ne la lit qu'au
 * démarrage et ne la retire jamais : elle gardait le lien lui-même, le jeton
 * et la valeur chiffrée, qui suffisent à changer le mot de passe avec
 * l'adresse du compte, tant que le document vivait, après que l'historique
 * chiffré les avait rangés hors de portée, après une visite qui quitte la page
 * (réinitialisation réussie, puis connexion), et même après la visite qui jette
 * la clé de l'historique. La page la vide une fois montée
 * (`viderLaPageInitialeDuDocument()`) : Inertia, la garde de l'historique et
 * `SyncService` l'ont déjà lue. Elle n'y reçoit pas l'adresse du compte, que la
 * personne saisit ; celle qu'elle tape ne s'y écrit pas non plus.
 *
 * Le client d'Inertia se monte une fois pour le fichier, qui n'a qu'un cas
 * (`.ai/rules/tests-js.md`).
 */

// Le layout invité marque l'abonnement push ; ce n'est pas le sujet ici.
vi.mock('@/composables/useAbonnementPush', () => ({
    detacherLAppareil: () => Promise.resolve(),
    marquerLAbonnementARetransmettre: () => {},
}))
vi.mock('@/Utils/SyncService', () => ({
    default: { compte: null, enAttente: () => 0, clearFailedRequests: () => {} },
}))

const ADRESSE_SAISIE = 'titulaire.document@example.org'
const PARTIE_LOCALE = 'titulaire.document'
const JETON = 'jeton-du-courriel-document'
const VALEUR_CHIFFREE = 'valeur-chiffree-du-document'

/** La page telle que le serveur la rend pour un lien qui vaut, en HTTPS. */
const PAGE_DU_LIEN = {
    component: 'Auth/ResetPassword',
    url: `/reset-password/${JETON}?adresse=${VALEUR_CHIFFREE}`,
    version: 'v1',
    props: {
        errors: {},
        auth: { user: null },
        flash: { success: null, error: null },
        token: JETON,
        adresse: VALEUR_CHIFFREE,
    },
    encryptHistory: true,
}

/** La page de connexion, où mène une réinitialisation réussie. */
const CONNEXION = () => h('main', { 'data-testid': 'connexion' }, 'Connexion')

let application
let routeur

/** Ce que la balise de la page initiale porte encore. */
const pageInitiale = () => document.querySelector('script[data-page="app"][type="application/json"]')?.textContent ?? ''

/** Ce qu'elle laisse lire de ce que le lien a donné à la page. */
const traces = (texte) => [PARTIE_LOCALE, JETON, VALEUR_CHIFFREE].filter((valeur) => texte.includes(valeur))

beforeAll(async () => {
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})

    window.sessionStorage.clear()
    window.localStorage.clear()
    window.history.replaceState(null, '', PAGE_DU_LIEN.url)
    globalThis.route = (nom) => `/${nom}`

    // Le document tel que le serveur l'écrit : la page initiale, lue par Inertia.
    const initiale = document.createElement('script')
    initiale.dataset.page = 'app'
    initiale.type = 'application/json'
    initiale.textContent = JSON.stringify(PAGE_DU_LIEN)
    document.body.replaceChildren(initiale, Object.assign(document.createElement('div'), { id: 'app' }))

    const inertia = await import('@inertiajs/vue3')
    const ResetPassword = (await import('@/Pages/Auth/ResetPassword.vue')).default
    routeur = inertia.router

    await inertia.createInertiaApp({
        resolve: (nom) => (nom === 'Auth/ResetPassword' ? ResetPassword : CONNEXION),
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) })
            application.directive('press', {})
            application.config.globalProperties.route = globalThis.route
            application.use(plugin).mount(el)
        },
    })

    await vi.waitUntil(() => document.querySelector('input[autocomplete="username"]'))
})

afterAll(() => {
    application?.unmount()
    delete globalThis.route
    vi.restoreAllMocks()
})

describe('la page initiale du document, après la page d’un lien qui vaut', () => {
    it('ne garde ni le jeton, ni la valeur chiffrée, ni l’adresse saisie, page ouverte puis quittée', async () => {
        // La page a bien reçu le lien : son formulaire est ouvert, et la personne y saisit son adresse.
        const champ = document.querySelector('input[autocomplete="username"]')
        champ.value = ADRESSE_SAISIE
        champ.dispatchEvent(new Event('input'))
        expect(traces(pageInitiale())).toEqual([])

        // La réinitialisation réussie mène à la connexion, par une visite : le document reste.
        await new Promise((fin) => {
            routeur.push({
                url: '/login',
                component: 'Auth/Login',
                props: { errors: {}, auth: { user: null }, flash: { success: null, error: null } },
                clearHistory: true,
                onFinish: fin,
            })
        })
        await vi.waitUntil(() => document.querySelector('[data-testid="connexion"]'))

        expect(window.location.pathname).toBe('/login')
        expect(traces(pageInitiale())).toEqual([])
        expect(traces(document.documentElement.outerHTML)).toEqual([])
    })
})
