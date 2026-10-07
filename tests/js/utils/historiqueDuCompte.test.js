import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { createApp, h } from 'vue'
import {
    CLEF_DE_L_HISTORIQUE,
    CLEF_DU_TITULAIRE_DE_L_APPAREIL,
    CLEF_DU_TITULAIRE_DE_L_ONGLET,
    INVITE,
    installerLaGardeDeLHistorique,
    pageInitialeDuDocument,
    titulaireDeLaPage,
} from '@/Utils/historiqueDuCompte'

/**
 * Une fenêtre de test : de vrais évènements, des stockages qu'on choisit, et un
 * rechargement qu'on compte au lieu de le subir.
 *
 * @param {{ stockage?: Storage, stockageCommun?: Storage, document?: object }} options
 */
const fenetreDeTest = ({
    stockage = window.sessionStorage,
    stockageCommun = window.localStorage,
    document = { documentElement: { style: {} } },
} = {}) => {
    const cible = new EventTarget()

    return {
        addEventListener: cible.addEventListener.bind(cible),
        dispatchEvent: cible.dispatchEvent.bind(cible),
        sessionStorage: stockage,
        localStorage: stockageCommun,
        document,
        location: { reload: vi.fn() },
    }
}

/**
 * Une entrée chiffrée par Inertia : un `ArrayBuffer`, reconnu par sa marque,
 * puisque l'historique de jsdom le recopie dans un autre domaine que celui du
 * test, où `instanceof` ne le reconnaît plus.
 */
const estChiffree = (donnees) => Object.prototype.toString.call(donnees) === '[object ArrayBuffer]'

/** Un routeur qui retient ses écouteurs, et jette la clé comme Inertia. */
const routeurDeTest = () => {
    const ecouteurs = {}

    return {
        ecouteurs,
        on: (evenement, rappel) => {
            ecouteurs[evenement] = rappel
        },
        clearHistory: vi.fn(() => {
            window.sessionStorage.removeItem(CLEF_DE_L_HISTORIQUE)
            window.sessionStorage.removeItem('historyIv')
        }),
    }
}

/** Une page servie au compte donné, ou à un invité (`null`). */
const pageServieA = (identifiant, options = {}) => ({
    component: 'Journal/Index',
    props: { errors: {}, auth: { user: identifiant === null ? null : { id: identifiant } } },
    encryptHistory: identifiant !== null,
    ...options,
})

/** Le retour d'une page par le bouton Retour, depuis la mémoire du navigateur ou non. */
const retour = (fenetre, persisted) => {
    const evenement = new Event('pageshow')
    Object.defineProperty(evenement, 'persisted', { value: persisted })
    fenetre.dispatchEvent(evenement)
}

describe('une page de compte rendue de mémoire après le départ du compte', () => {
    beforeEach(() => {
        window.sessionStorage.clear()
        window.localStorage.clear()
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
        window.localStorage.clear()
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
 * Une session sert tous les onglets du navigateur, et chacun a sa clé : le
 * serveur ne pose `clearHistory` que sur la page qu'il rend, dans l'onglet qui
 * la demande. Chaque onglet compare donc lui-même le titulaire de chaque page
 * au sien. Le parcours complet, avec le vrai client d'Inertia, est dans
 * historiqueDuCompteParOnglet.test.js.
 */
describe('le titulaire que déclare chaque page', () => {
    it('est le compte de `auth.user`, un invité, ou rien quand la page ne porte pas `auth`', () => {
        expect(titulaireDeLaPage(pageServieA(7))).toBe('compte:7')
        expect(titulaireDeLaPage(pageServieA(null))).toBe(INVITE)
        expect(titulaireDeLaPage({ props: { auth: {} } })).toBe(INVITE)
        expect(titulaireDeLaPage({ props: { auth: null } })).toBeNull()
        expect(titulaireDeLaPage({ props: {} })).toBeNull()
        expect(titulaireDeLaPage(null)).toBeNull()
    })

    it('se lit dans la page que le serveur a écrite dans le document', () => {
        const script = document.createElement('script')
        script.dataset.page = 'app'
        script.type = 'application/json'
        script.textContent = JSON.stringify(pageServieA(7))
        document.body.append(script)

        try {
            expect(titulaireDeLaPage(pageInitialeDuDocument(document))).toBe('compte:7')

            script.textContent = '{ pas du JSON'
            expect(pageInitialeDuDocument(document)).toBeNull()
        } finally {
            script.remove()
        }

        expect(pageInitialeDuDocument(document)).toBeNull()
        expect(pageInitialeDuDocument(undefined)).toBeNull()
    })
})

describe('une page d’un autre titulaire que l’onglet', () => {
    beforeEach(() => {
        window.sessionStorage.clear()
        window.localStorage.clear()
        window.sessionStorage.setItem(CLEF_DE_L_HISTORIQUE, '[1,2,3]')
    })

    it('jette la clé de l’onglet dès la page initiale, avant qu’Inertia ne lise l’historique', () => {
        window.sessionStorage.setItem(CLEF_DU_TITULAIRE_DE_L_ONGLET, 'compte:1')
        const routeur = routeurDeTest()

        installerLaGardeDeLHistorique({ routeur, pageInitiale: pageServieA(2), fenetre: fenetreDeTest() })

        expect(routeur.clearHistory).toHaveBeenCalledTimes(1)
        expect(window.sessionStorage.getItem(CLEF_DE_L_HISTORIQUE)).toBeNull()
        expect(window.sessionStorage.getItem(CLEF_DU_TITULAIRE_DE_L_ONGLET)).toBe('compte:2')
        expect(window.localStorage.getItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL)).toBe('compte:2')
    })

    it('lit la page initiale dans le document quand on ne la lui donne pas', () => {
        window.sessionStorage.setItem(CLEF_DU_TITULAIRE_DE_L_ONGLET, 'compte:1')
        const routeur = routeurDeTest()
        const script = { textContent: JSON.stringify(pageServieA(null)) }
        const fenetre = fenetreDeTest({
            document: { documentElement: { style: {} }, querySelector: () => script },
        })

        installerLaGardeDeLHistorique({ routeur, fenetre })

        expect(routeur.clearHistory).toHaveBeenCalledTimes(1)
        expect(window.sessionStorage.getItem(CLEF_DU_TITULAIRE_DE_L_ONGLET)).toBe(INVITE)
    })

    it('jette la clé à la réponse d’une visite, avant qu’Inertia ne la range', () => {
        const routeur = routeurDeTest()
        installerLaGardeDeLHistorique({ routeur, pageInitiale: pageServieA(1), fenetre: fenetreDeTest() })
        window.sessionStorage.setItem(CLEF_DE_L_HISTORIQUE, '[4,5,6]')
        routeur.clearHistory.mockClear()

        // Le compte est parti dans un autre onglet : la page suivante est celle d'un invité.
        routeur.ecouteurs.beforeUpdate({ detail: { page: pageServieA(null, { component: 'Auth/Login' }) } })

        expect(routeur.clearHistory).toHaveBeenCalledTimes(1)
        expect(window.sessionStorage.getItem(CLEF_DE_L_HISTORIQUE)).toBeNull()
        expect(window.localStorage.getItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL)).toBe(INVITE)
    })

    it('garde la clé tant que les pages servent le même titulaire', () => {
        const routeur = routeurDeTest()
        installerLaGardeDeLHistorique({ routeur, pageInitiale: pageServieA(1), fenetre: fenetreDeTest() })
        window.sessionStorage.setItem(CLEF_DE_L_HISTORIQUE, '[4,5,6]')
        routeur.clearHistory.mockClear()

        routeur.ecouteurs.beforeUpdate({ detail: { page: pageServieA(1, { component: 'Profile/Edit' }) } })
        installerLaGardeDeLHistorique({ routeur, pageInitiale: pageServieA(1), fenetre: fenetreDeTest() })

        expect(routeur.clearHistory).not.toHaveBeenCalled()
        expect(window.sessionStorage.getItem(CLEF_DE_L_HISTORIQUE)).toBe('[4,5,6]')
    })

    it('ne décide rien sur une page qui ne déclare pas de titulaire', () => {
        window.sessionStorage.setItem(CLEF_DU_TITULAIRE_DE_L_ONGLET, 'compte:1')
        const routeur = routeurDeTest()

        installerLaGardeDeLHistorique({
            routeur,
            pageInitiale: { component: 'Error', props: {} },
            fenetre: fenetreDeTest(),
        })
        routeur.ecouteurs.beforeUpdate({ detail: {} })

        expect(routeur.clearHistory).not.toHaveBeenCalled()
        expect(window.sessionStorage.getItem(CLEF_DU_TITULAIRE_DE_L_ONGLET)).toBe('compte:1')
    })
})

describe('un autre onglet a reçu la page d’un autre titulaire', () => {
    let routeur
    let fenetre

    beforeEach(() => {
        window.sessionStorage.clear()
        window.localStorage.clear()
        routeur = routeurDeTest()
        fenetre = fenetreDeTest()
        installerLaGardeDeLHistorique({ routeur, pageInitiale: pageServieA(1), fenetre })
        window.sessionStorage.setItem(CLEF_DE_L_HISTORIQUE, '[1,2,3]')
        routeur.clearHistory.mockClear()
    })

    /** Ce qu'écrit un autre onglet, et l'évènement que le navigateur en donne à celui-ci. */
    const unAutreOngletRecoit = (titulaire, { evenement = true, clef = CLEF_DU_TITULAIRE_DE_L_APPAREIL } = {}) => {
        window.localStorage.setItem(clef, titulaire)

        if (evenement) {
            fenetre.dispatchEvent(new StorageEvent('storage', { key: clef, newValue: titulaire }))
        }
    }

    it('jette la clé de cet onglet aussitôt', () => {
        unAutreOngletRecoit(INVITE)

        expect(routeur.clearHistory).toHaveBeenCalledTimes(1)
        expect(window.sessionStorage.getItem(CLEF_DE_L_HISTORIQUE)).toBeNull()
    })

    it('jette la clé au bouton Retour, quand l’évènement n’est pas arrivé', () => {
        unAutreOngletRecoit('compte:2', { evenement: false })

        fenetre.dispatchEvent(new PopStateEvent('popstate', { state: null }))

        expect(routeur.clearHistory).toHaveBeenCalledTimes(1)
    })

    it('jette la clé et masque la page qui revient de la mémoire de retour arrière', () => {
        unAutreOngletRecoit(INVITE, { evenement: false })

        retour(fenetre, true)

        expect(routeur.clearHistory).toHaveBeenCalledTimes(1)
        expect(fenetre.document.documentElement.style.visibility).toBe('hidden')
        expect(fenetre.location.reload).toHaveBeenCalledTimes(1)
    })

    it('laisse la clé quand l’appareil sert toujours le titulaire de l’onglet, ou pour un autre stockage', () => {
        unAutreOngletRecoit('compte:1')
        unAutreOngletRecoit(INVITE, { clef: 'gym-tracker:autre-chose' })
        fenetre.dispatchEvent(new PopStateEvent('popstate', { state: null }))

        expect(routeur.clearHistory).not.toHaveBeenCalled()
        expect(window.sessionStorage.getItem(CLEF_DE_L_HISTORIQUE)).toBe('[1,2,3]')
    })
})

describe('des stockages que le navigateur refuse', () => {
    it('n’empêchent ni l’installation ni la navigation', () => {
        const refuse = () => {
            throw new DOMException('refusé', 'SecurityError')
        }
        const stockageRefuse = { getItem: refuse, setItem: refuse }
        const routeur = { ...routeurDeTest(), clearHistory: vi.fn(refuse) }
        const fenetre = fenetreDeTest({ stockage: stockageRefuse, stockageCommun: stockageRefuse })

        expect(() => {
            installerLaGardeDeLHistorique({ routeur, pageInitiale: pageServieA(1), fenetre })
            routeur.ecouteurs.beforeUpdate({ detail: { page: pageServieA(2) } })
            fenetre.dispatchEvent(new PopStateEvent('popstate', { state: null }))
            retour(fenetre, true)
        }).not.toThrow()
        expect(routeur.clearHistory).toHaveBeenCalled()
        expect(fenetre.location.reload).not.toHaveBeenCalled()
    })

    it('ni une fenêtre dont l’accès aux stockages lève', () => {
        const routeur = routeurDeTest()
        const fenetre = fenetreDeTest()
        Object.defineProperty(fenetre, 'sessionStorage', {
            get: () => {
                throw new DOMException('refusé', 'SecurityError')
            },
        })
        Object.defineProperty(fenetre, 'localStorage', { value: undefined })

        expect(() => installerLaGardeDeLHistorique({ routeur, pageInitiale: pageServieA(1), fenetre })).not.toThrow()

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
        window.sessionStorage.clear()
        window.localStorage.clear()
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
