import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { createApp, h } from 'vue'
import { CLEF_DE_L_HISTORIQUE, installerLaGardeDeLHistorique } from '@/Utils/historiqueDuCompte'

/**
 * Une fenêtre de test : de vrais évènements, un stockage qu'on choisit, et un
 * rechargement qu'on compte au lieu de le subir.
 *
 * @param {{ stockage?: Storage }} options
 */
const fenetreDeTest = ({ stockage = window.sessionStorage } = {}) => {
    const cible = new EventTarget()

    return {
        addEventListener: cible.addEventListener.bind(cible),
        dispatchEvent: cible.dispatchEvent.bind(cible),
        sessionStorage: stockage,
        document: { documentElement: { style: {} } },
        location: { reload: vi.fn() },
    }
}

/**
 * Une entrée chiffrée par Inertia : un `ArrayBuffer`, reconnu par sa marque,
 * puisque l'historique de jsdom le recopie dans un autre domaine que celui du
 * test, où `instanceof` ne le reconnaît plus.
 */
const estChiffree = (donnees) => Object.prototype.toString.call(donnees) === '[object ArrayBuffer]'

/** Un routeur qui ne fait que retenir ses écouteurs. */
const routeurDeTest = () => {
    const ecouteurs = {}

    return {
        ecouteurs,
        on: (evenement, rappel) => {
            ecouteurs[evenement] = rappel
        },
    }
}

/** Le retour d'une page par le bouton Retour, depuis la mémoire du navigateur ou non. */
const retour = (fenetre, persisted) => {
    const evenement = new Event('pageshow')
    Object.defineProperty(evenement, 'persisted', { value: persisted })
    fenetre.dispatchEvent(evenement)
}

describe('une page de compte rendue de mémoire après le départ du compte', () => {
    beforeEach(() => {
        window.sessionStorage.clear()
    })

    it('est masquée et redemandée au serveur', () => {
        const fenetre = fenetreDeTest()
        installerLaGardeDeLHistorique({ routeur: routeurDeTest(), pageInitiale: { encryptHistory: true }, fenetre })

        // La déconnexion, faite dans un autre document de l'onglet, a jeté la clé.
        retour(fenetre, true)

        expect(fenetre.document.documentElement.style.visibility).toBe('hidden')
        expect(fenetre.location.reload).toHaveBeenCalledTimes(1)
    })

    it('suit la page courante : une page de compte atteinte en naviguant est gardée aussi', () => {
        const fenetre = fenetreDeTest()
        const routeur = routeurDeTest()
        installerLaGardeDeLHistorique({ routeur, pageInitiale: { encryptHistory: false }, fenetre })

        routeur.ecouteurs.navigate({ detail: { page: { component: 'Journal/Index', encryptHistory: true } } })
        retour(fenetre, true)

        expect(fenetre.location.reload).toHaveBeenCalledTimes(1)
    })
})

describe('ce que la garde laisse passer', () => {
    beforeEach(() => {
        window.sessionStorage.clear()
    })

    it('une page de compte dont la clé est toujours là : le compte n’est pas parti', () => {
        window.sessionStorage.setItem(CLEF_DE_L_HISTORIQUE, '[1,2,3]')
        const fenetre = fenetreDeTest()
        installerLaGardeDeLHistorique({ routeur: routeurDeTest(), pageInitiale: { encryptHistory: true }, fenetre })

        retour(fenetre, true)

        // La mémoire de retour arrière garde le minuteur et la saisie en cours :
        // la recharger sans raison les perdrait.
        expect(fenetre.document.documentElement.style.visibility).toBeUndefined()
        expect(fenetre.location.reload).not.toHaveBeenCalled()
    })

    it('une page publique, qui ne porte rien du compte', () => {
        const fenetre = fenetreDeTest()
        const routeur = routeurDeTest()
        installerLaGardeDeLHistorique({ routeur, pageInitiale: { encryptHistory: true }, fenetre })

        // La page de connexion qui suit la déconnexion n'est pas chiffrée.
        routeur.ecouteurs.navigate({ detail: { page: { component: 'Auth/Login' } } })
        retour(fenetre, true)

        expect(fenetre.location.reload).not.toHaveBeenCalled()
    })

    it('un chargement ordinaire, qui vient déjà du serveur', () => {
        const fenetre = fenetreDeTest()
        installerLaGardeDeLHistorique({ routeur: routeurDeTest(), pageInitiale: { encryptHistory: true }, fenetre })

        retour(fenetre, false)

        expect(fenetre.location.reload).not.toHaveBeenCalled()
    })

    it('un stockage illisible, qui ne prouve pas que la clé est partie', () => {
        const fenetre = fenetreDeTest({
            stockage: {
                getItem: () => {
                    throw new DOMException('refusé', 'SecurityError')
                },
            },
        })
        installerLaGardeDeLHistorique({ routeur: routeurDeTest(), pageInitiale: { encryptHistory: true }, fenetre })

        retour(fenetre, true)

        expect(fenetre.location.reload).not.toHaveBeenCalled()
    })

    it('une page sans page initiale connue, avant toute navigation', () => {
        const fenetre = fenetreDeTest()
        installerLaGardeDeLHistorique({ routeur: routeurDeTest(), fenetre })

        retour(fenetre, true)

        expect(fenetre.location.reload).not.toHaveBeenCalled()
    })
})

/*
 * Avec le vrai client d'Inertia : la garde lit la clé là où Inertia la range,
 * et c'est bien `clearHistory` qui la fait disparaître. Si Inertia changeait
 * l'une ou l'autre, ce cas tomberait au lieu de laisser la garde aveugle.
 */
describe('avec le vrai client d’Inertia', () => {
    let routeur

    beforeAll(async () => {
        document.body.innerHTML = '<div id="app"></div>'
        window.history.replaceState(null, '', '/daily-journals')

        const inertia = await import('@inertiajs/vue3')
        routeur = inertia.router

        const Journal = {
            props: ['note'],
            render() {
                return h('p', { id: 'contenu' }, this.note)
            },
        }

        await inertia.createInertiaApp({
            page: {
                component: 'Journal/Index',
                url: '/daily-journals',
                version: 'v1',
                props: { errors: {}, auth: { user: { id: 1 } }, note: 'Note privée du compte parti' },
                encryptHistory: true,
            },
            resolve: () => Journal,
            setup: ({ el, App, props, plugin }) =>
                createApp({ render: () => h(App, props) })
                    .use(plugin)
                    .mount(el),
        })

        await vi.waitUntil(() => estChiffree(window.history.state?.page))
    })

    it('chiffre la page dans l’historique, avec une clé à l’endroit que lit la garde', () => {
        expect(JSON.stringify(window.history.state)).not.toContain('Note privée')
        expect(window.sessionStorage.getItem(CLEF_DE_L_HISTORIQUE)).not.toBeNull()
    })

    it('masque la page après `clearHistory`, et seulement après', async () => {
        const fenetre = fenetreDeTest()
        installerLaGardeDeLHistorique({ routeur, pageInitiale: { encryptHistory: true }, fenetre })

        // Encore connecté : la page revient telle quelle.
        await vi.waitUntil(() => estChiffree(window.history.state?.page))
        retour(fenetre, true)
        expect(fenetre.location.reload).not.toHaveBeenCalled()

        // Ce que fait la première page après la déconnexion, dans un autre document.
        routeur.clearHistory()
        retour(fenetre, true)

        expect(fenetre.document.documentElement.style.visibility).toBe('hidden')
        expect(fenetre.location.reload).toHaveBeenCalledTimes(1)
    })
})
