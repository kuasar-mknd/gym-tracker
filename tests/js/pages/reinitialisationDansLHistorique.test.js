import { describe, it, expect, vi, beforeAll, afterAll } from 'vitest'
import { createApp, h } from 'vue'

/*
 * La page de réinitialisation du mot de passe, ouverte par un lien qui vaut,
 * avec le vrai client d'Inertia. Le serveur ne lui donne pas l'adresse du
 * compte : la personne la tape dans un champ vide (`autocomplete="username"`),
 * et ce qu'elle tape ne doit rien laisser que le navigateur garde. Inertia
 * range la page dans l'historique du navigateur, que le bouton Retour relit
 * sans requête ; le serveur la sert avec `encryptHistory`, comme une page de
 * compte, pour le lien qu'elle porte, jeton et valeur chiffrée
 * (`HandleInertiaRequests::traiterCommeUnePageDeCompte()`,
 * `OuvertureDuLienDeReinitialisationTest`) : Inertia chiffre l'entrée avec la
 * clé de l'onglet (`crypto.subtle`, que le navigateur n'offre qu'en HTTPS et
 * sur la boucle locale). Ce fichier vérifie que le client le fait bien de
 * cette page, et que l'adresse tapée ne va ni dans l'entrée, un `ArrayBuffer`,
 * ni dans les stockages : seul le champ de la page ouverte la tient. La page
 * initiale du document, que l'historique chiffré ne couvre pas, est vidée au
 * montage (pageInitialeDeLaReinitialisation.test.js).
 *
 * L'entrée se reconnaît comme Inertia la reconnaît avant de la déchiffrer,
 * par `instanceof ArrayBuffer`, jamais par sa marque (`.ai/rules/tests-js.md`).
 * Le client d'Inertia se monte une fois pour le fichier, qui n'a qu'un cas.
 */

// Le layout invité marque l'abonnement push ; ce n'est pas le sujet ici.
vi.mock('@/composables/useAbonnementPush', () => ({
    detacherLAppareil: () => Promise.resolve(),
    marquerLAbonnementARetransmettre: () => {},
}))
vi.mock('@/Utils/SyncService', () => ({
    default: { compte: null, enAttente: () => 0, clearFailedRequests: () => {} },
}))

const ADRESSE_SAISIE = 'titulaire.historique@example.org'
const PARTIE_LOCALE = 'titulaire.historique'

/** La page telle que le serveur la rend pour un lien qui vaut, en HTTPS. */
const PAGE_DU_LIEN = {
    component: 'Auth/ResetPassword',
    url: '/reset-password/jeton-du-courriel?adresse=valeur-chiffree',
    version: 'v1',
    props: {
        errors: {},
        auth: { user: null },
        flash: { success: null, error: null },
        token: 'jeton-du-courriel',
        adresse: 'valeur-chiffree',
    },
    encryptHistory: true,
}

let application

/** Tout ce qu'un stockage du navigateur garde, en texte. */
const contenuDe = (stockage) =>
    Object.keys(stockage)
        .map((cle) => `${cle}=${stockage.getItem(cle)}`)
        .join('\n')

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

    await inertia.createInertiaApp({
        resolve: () => ResetPassword,
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) })
            application.directive('press', {})
            application.config.globalProperties.route = globalThis.route
            application.use(plugin).mount(el)
        },
    })

    // L'entrée, quelle qu'elle soit : chiffrée ou non, c'est au test de le dire.
    await vi.waitUntil(() => window.history.state?.page)
})

afterAll(() => {
    application?.unmount()
    delete globalThis.route
    vi.restoreAllMocks()
})

describe('la page d’un lien de réinitialisation qui vaut', () => {
    it('offre un champ vide à remplir, et ce que la personne y tape ne va ni dans l’historique chiffré ni dans les stockages', async () => {
        const champ = document.querySelector('input[autocomplete="username"]')

        expect(champ.value).toBe('')
        expect(champ.readOnly).toBe(false)
        expect(champ.closest('form')).not.toBeNull()

        champ.value = ADRESSE_SAISIE
        champ.dispatchEvent(new Event('input'))
        // Une tâche, le temps qu'une écriture de l'historique ou d'un stockage se fasse.
        await new Promise((fin) => setTimeout(fin, 0))
        const etat = window.history.state

        expect(etat.page).toBeInstanceOf(ArrayBuffer)
        expect(new TextDecoder().decode(new Uint8Array(etat.page))).not.toContain(PARTIE_LOCALE)
        expect(JSON.stringify(etat)).not.toContain(PARTIE_LOCALE)
        expect(contenuDe(window.sessionStorage)).not.toContain(PARTIE_LOCALE)
        expect(contenuDe(window.localStorage)).not.toContain(PARTIE_LOCALE)
    })
})
