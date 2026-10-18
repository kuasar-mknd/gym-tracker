import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h } from 'vue'

import { creerUneFabriqueEnMemoire } from './baseIndexeeEnMemoire'
import { chargerSyncService, retirerLesEcouteursDuService } from './fileHorsLigne'
import { RequeteSimulee, relayerLesRejetsNonGeres, reponseInertia, requetes, reseau, uneTache } from './reseauSimule'
import { CHAMPS_DE_LA_PAGE_SERVIE } from '@/Utils/copiesServies'
import { CLEF_DU_TITULAIRE_DE_L_APPAREIL } from '@/Utils/historiqueDuCompte'

/*
 * Une copie hors ligne servie dans l'application ouverte (#2048, partie E),
 * avec le vrai client d'Inertia et le vrai magasin chiffré, sur une base en
 * mémoire. Une visite qui quitte la page vers une page gardée reçoit sa
 * copie sans réponse du réseau, ou au bout du délai sur un réseau qui ne
 * répond pas ; jamais une visite partielle, une écriture ou un préchargement.
 *
 * Le client se monte une fois pour le fichier, après le chargement de la file
 * hors ligne, et chaque module de la page s'importe après lui : un seul
 * graphe, comme dans le navigateur. Chaque cas repart de la séance ouverte,
 * remise par une visite du client seul, et d'un magasin vide (#2044).
 *
 * Les délais sont raccourcis ici (`DELAI_AVANT_UNE_COPIE`,
 * `DELAI_DE_LA_LENTEUR`) : le chiffrement passe par le `crypto.subtle` de
 * Node, que des minuteries simulées ne font pas avancer. Les vraies valeurs,
 * et le branchement par défaut de `main.js`, sont vérifiées à part.
 */

const COMPTE = 7

/** Le délai avant une copie, raccourci pour ce fichier (5 s dans l'application). */
const DELAI_AVANT_UNE_COPIE = 80

/** Le délai avant le toast de la lenteur, raccourci pour ce fichier (8 s dans l'application). */
const DELAI_DE_LA_LENTEUR = 600

/** L'heure du magasin et des réponses, que chaque cas peut avancer. */
let maintenant = Date.parse('2026-10-12T18:04:00Z')

/** Une clé de 32 octets tirés au hasard, en base64url, comme le serveur la tire. */
const cleAuHasard = () =>
    btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '')

/**
 * Une page du compte, telle que le serveur la rend en entier.
 *
 * @param {{ url?: string, component?: string, texte?: string, auth?: object, props?: object, deferredProps?: object }} [options]
 */
const pageDe = ({
    url = '/workouts',
    component = 'Workouts/Index',
    texte = 'Séances',
    auth,
    props = {},
    deferredProps,
} = {}) => ({
    component,
    url,
    version: 'version-de-la-page',
    encryptHistory: true,
    clearHistory: false,
    props: {
        errors: {},
        auth: auth ?? {
            user: { id: COMPTE, name: 'Camille Horsligne', active_workout: null, latest_achievement: null },
        },
        texte,
        ...props,
    },
    ...(deferredProps === undefined ? {} : { deferredProps }),
})

/** La séance ouverte, d'où partent les visites de chaque cas. */
const seanceOuverte = () => pageDe({ url: '/workouts/12', component: 'Workouts/Show', texte: 'Séance en cours' })

let routeur
let fabrique
let magasin
let syncService
let etatDuReseau
let visites
let application
let relais

const annonces = []
const navigations = []
const lectures = []
const morceauxAbsents = new Set()

/** Ferme la lecture du magasin jusqu'à `ouvrir()`, pour jouer un geste pendant qu'elle attend. */
let porte = { attendre: Promise.resolve(), ouvrir: () => {} }

const fermerLaPorte = () => {
    let ouvrir
    const attendre = new Promise((resoudre) => {
        ouvrir = resoudre
    })
    porte = { attendre, ouvrir }
}

const contenu = () => document.getElementById('contenu')?.textContent
const pageMontree = () => navigations.at(-1)

/** Écrit la copie d'une page dans le magasin, à l'heure donnée. */
const garderUneCopie = async (page, { le = maintenant } = {}) => {
    const avant = maintenant
    maintenant = le

    try {
        expect(await magasin.ecrireUneCopie(page)).toBe('ecrite')
    } finally {
        maintenant = avant
    }
}

/** Lance une visite et attend sa fin, quelle qu'en soit l'issue. */
const visiter = async (adresse, options = {}) => {
    let finie = false

    routeur.visit(adresse, { ...options, onFinish: () => (finie = true) })
    await vi.waitUntil(() => finie)
    await uneTache()
}

/** Attend qu'une copie soit montrée. */
const attendreUneCopie = () => vi.waitUntil(() => pageMontree()?.props?.horsLigne !== undefined, { timeout: 3000 })

/** Attend la première annonce. */
const attendreUneAnnonce = () => vi.waitUntil(() => annonces.length > 0, { timeout: 3000 })

const ecouterLesAnnonces = (evenement) => annonces.push(evenement.detail)

/** Une page de l'application qui montre son texte. */
const Page = {
    props: ['texte'],
    render() {
        return h('p', { id: 'contenu' }, this.texte)
    },
}

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    document.body.innerHTML = '<div id="app"></div>'
    window.history.replaceState(null, '', '/workouts/12')
    globalThis.route = (nom) => `/${nom}`

    // La file d'abord, puis tout le reste, dans le même graphe de modules.
    syncService = await chargerSyncService({ compte: COMPTE })

    const { creerLeMagasinDesCopies, ouvrirLaBaseDuNavigateur } = await import('@/Utils/copiesHorsLigne')

    fabrique = creerUneFabriqueEnMemoire()
    magasin = creerLeMagasinDesCopies({
        ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabrique),
        horloge: () => maintenant,
    })
    etatDuReseau = await import('@/composables/useEtatDuReseau')
    visites = await import('@/Utils/visitesHorsLigne')

    const inertia = await import('@inertiajs/vue3')
    routeur = inertia.router

    visites.installerLesVisitesHorsLigne({
        routeur,
        delaiAvantUneCopie: DELAI_AVANT_UNE_COPIE,
        delaiDuReseauLent: DELAI_DE_LA_LENTEUR,
        lireUneCopie: async (adresse) => {
            lectures.push(adresse)
            await porte.attendre

            return magasin.lireUneCopie(adresse)
        },
        horloge: () => maintenant,
    })

    routeur.on('navigate', (evenement) => navigations.push(evenement.detail.page))

    await inertia.createInertiaApp({
        page: seanceOuverte(),
        resolve: (nom) =>
            morceauxAbsents.has(nom)
                ? Promise.reject(new TypeError('Failed to fetch dynamically imported module'))
                : Page,
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) }).use(plugin)
            application.mount(el)
        },
    })

    // La navigation initiale arrive après le démarrage : qu'elle ne tombe pas dans le premier cas.
    await vi.waitUntil(() => navigations.length > 0)
    window.addEventListener(visites.EVENEMENT_SANS_RESEAU, ecouterLesAnnonces)
})

beforeEach(async () => {
    porte = { attendre: Promise.resolve(), ouvrir: () => {} }
    await new Promise((fini) => routeur.replace({ ...seanceOuverte(), onFinish: fini }))

    fabrique.enregistrements('gym-tracker-copies', 'pages').clear()
    fabrique.enregistrements('gym-tracker-copies', 'etat').clear()
    localStorage.clear()
    localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, `compte:${COMPTE}`)
    document.cookie = `gt_copies=${cleAuHasard()}; path=/`
    requetes.length = 0
    annonces.length = 0
    navigations.length = 0
    lectures.length = 0
    morceauxAbsents.clear()
    reseau.mode = 'coupe'
    reseau.prochains.length = 0
    reseau.reponse = null
    etatDuReseau.noterQueLeServeurRepond()
    relais = relayerLesRejetsNonGeres()
})

afterEach(() => {
    relais.retirer()
    routeur.cancelAll()
    routeur.flushAll()
})

afterAll(async () => {
    window.removeEventListener(visites.EVENEMENT_SANS_RESEAU, ecouterLesAnnonces)
    application.unmount()
    await magasin.fermer()
    retirerLesEcouteursDuService()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

describe('sans réseau, une page gardée s’ouvre depuis sa copie', () => {
    it('un lien vers les séances montre leur copie, marquée, sans message ni rejet', async () => {
        const ordre = []
        const avantLaCopie = (evenement) => ordre.push(['avant', evenement.detail.page.url, navigations.length])

        await garderUneCopie(pageDe())
        window.addEventListener(visites.EVENEMENT_AVANT_UNE_COPIE, avantLaCopie)

        try {
            await visiter('/workouts')
            await attendreUneCopie()
        } finally {
            window.removeEventListener(visites.EVENEMENT_AVANT_UNE_COPIE, avantLaCopie)
        }

        // La séance a été prévenue avant que la copie prenne l'entrée courante (`useSeanceEtSaFile`).
        expect(ordre).toEqual([['avant', '/workouts', 0]])

        expect(requetes).toHaveLength(1)
        expect(contenu()).toBe('Séances')
        expect(window.location.pathname).toBe('/workouts')
        expect(pageMontree()).toMatchObject({
            component: 'Workouts/Index',
            url: '/workouts',
            props: { horsLigne: { copieDu: maintenant, raison: 'reseau' }, errors: {} },
            encryptHistory: true,
        })
        expect(annonces).toEqual([])
        expect(relais.rejets).toEqual([])
        expect(etatDuReseau.useEtatDuReseau().joignable.value).toBe(false)
    })

    it('n’émet ni `success` ni `beforeUpdate`, et la file garde son compte des rejeux', async () => {
        const evenements = []
        const noter = (evenement) => evenements.push(evenement.type)

        await garderUneCopie(pageDe())
        syncService.rejeuxDepuisLaDerniereVisite = 3
        document.addEventListener('inertia:success', noter)
        document.addEventListener('inertia:beforeUpdate', noter)

        try {
            await visiter('/workouts')
            await attendreUneCopie()
        } finally {
            document.removeEventListener('inertia:success', noter)
            document.removeEventListener('inertia:beforeUpdate', noter)
        }

        expect(evenements).toEqual([])
        expect(syncService.rejeuxDepuisLaDerniereVisite).toBe(3)
    })

    it('range la copie chiffrée dans l’historique : `history.state.page` est un ArrayBuffer', async () => {
        await garderUneCopie(pageDe())

        await visiter('/workouts')
        await attendreUneCopie()
        await vi.waitUntil(() => window.history.state?.page instanceof ArrayBuffer)

        expect(window.history.state.page).toBeInstanceOf(ArrayBuffer)
        expect(JSON.stringify(window.history.state)).not.toContain('Séances')

        const dechiffree = await routeur.decryptHistory()

        expect(dechiffree).toMatchObject({ url: '/workouts', encryptHistory: true, props: { texte: 'Séances' } })
    })

    /*
     * La visite côté client recopie la page courante avant d'y verser ce
     * qu'on lui passe : un champ qu'on oublie survivrait de la page quittée,
     * dans l'écran comme dans l'historique.
     */
    it('ne garde rien de la page quittée hors de la version : garde de forme', async () => {
        await garderUneCopie(pageDe())
        await new Promise((fini) =>
            routeur.replace({
                ...seanceOuverte(),
                encryptHistory: false,
                flash: { success: 'sentinelle' },
                initialDeferredProps: { sentinelle: ['sentinelle'] },
                rescuedProps: ['sentinelle'],
                mergeProps: ['sentinelle'],
                prependProps: ['sentinelle'],
                deepMergeProps: ['sentinelle'],
                matchPropsOn: ['sentinelle.id'],
                onceProps: { sentinelle: { prop: 'sentinelle', expiresAt: null } },
                scrollProps: { sentinelle: { pageName: 'sentinelle' } },
                sharedProps: ['sentinelle'],
                rememberedState: { sentinelle: 'sentinelle' },
                preserveFragment: 'sentinelle',
                cleInconnue: 'sentinelle',
                onFinish: fini,
            }),
        )

        await visiter('/workouts')
        await attendreUneCopie()

        const montree = pageMontree()
        const ecrits = new Set([...CHAMPS_DE_LA_PAGE_SERVIE, 'rememberedState'])

        expect(JSON.stringify(montree)).not.toContain('sentinelle')
        expect(Object.keys(montree).filter((champ) => montree[champ] !== undefined && !ecrits.has(champ))).toEqual([])
        expect(montree).toMatchObject({ version: 'version-de-la-page', encryptHistory: true, clearHistory: false })
        expect(window.history.state.page).toBeInstanceOf(ArrayBuffer)
        expect(JSON.stringify(await routeur.decryptHistory())).not.toContain('sentinelle')
    })

    it('montre le `auth` de la dernière réponse du serveur, plus récent que la copie, sans badge à célébrer', async () => {
        await garderUneCopie(pageDe(), { le: maintenant - 60 * 60 * 1000 })

        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(
            pageDe({
                url: '/workouts/12',
                component: 'Workouts/Show',
                texte: 'Séance en cours',
                auth: {
                    user: {
                        id: COMPTE,
                        name: 'Camille',
                        active_workout: { id: 12 },
                        latest_achievement: { name: 'Fer' },
                    },
                },
            }),
        )
        await visiter('/workouts/12', { preserveState: true })

        reseau.mode = 'coupe'
        await visiter('/workouts')
        await attendreUneCopie()

        expect(pageMontree().props.auth.user).toEqual({
            id: COMPTE,
            name: 'Camille',
            active_workout: { id: 12 },
            latest_achievement: null,
        })
    })

    it('montre le `auth` de la copie quand elle est plus récente que la dernière réponse', async () => {
        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(seanceOuverte())
        await visiter('/workouts/12', { preserveState: true })

        await garderUneCopie(
            pageDe({
                auth: {
                    user: {
                        id: COMPTE,
                        name: 'Camille',
                        active_workout: { id: 99 },
                        latest_achievement: { name: 'Fer' },
                    },
                },
            }),
            { le: maintenant + 1 },
        )

        reseau.mode = 'coupe'
        await visiter('/workouts')
        await attendreUneCopie()

        expect(pageMontree().props.auth.user).toMatchObject({ active_workout: { id: 99 }, latest_achievement: null })
    })
})

describe('ce qui ne reçoit jamais de copie', () => {
    it('une page gardée sans copie dit qu’elle n’est pas disponible', async () => {
        await visiter('/exercises')
        await attendreUneAnnonce()

        expect(annonces).toEqual([{ message: visites.PAGE_INDISPONIBLE, annuler: null }])
        expect(contenu()).toBe('Séance en cours')
        expect(relais.rejets).toEqual([])
    })

    it('une page hors de la liste blanche, ou filtrée, ne demande rien au magasin', async () => {
        await garderUneCopie(pageDe())

        await visiter('/stats')
        await visiter('/workouts?type=jambes')
        await vi.waitUntil(() => annonces.length === 2)

        expect(lectures).toEqual([])
        expect(annonces.map((annonce) => annonce.message)).toEqual([
            visites.PAGE_INDISPONIBLE,
            visites.PAGE_INDISPONIBLE,
        ])
        expect(navigations).toEqual([])
    })

    it('le morceau du composant manque : « Mise à jour à terminer avec le réseau », et la page reste', async () => {
        await garderUneCopie(pageDe({ url: '/tools', component: 'Tools/Index', texte: 'Outils' }))
        morceauxAbsents.add('Tools/Index')

        await visiter('/tools')
        await attendreUneAnnonce()

        expect(annonces).toEqual([{ message: visites.MISE_A_JOUR_A_TERMINER, annuler: null }])
        expect(visites.MISE_A_JOUR_A_TERMINER).toBe('Mise à jour à terminer avec le réseau.')
        expect(contenu()).toBe('Séance en cours')
        expect(window.location.pathname).toBe('/workouts/12')
        expect(navigations).toEqual([])
        expect(relais.rejets).toEqual([])
    })

    it('sans la clé du témoin, ou sous un autre titulaire de l’appareil, rien n’est servi et le magasin se vide', async () => {
        await garderUneCopie(pageDe())
        document.cookie = 'gt_copies=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/'

        await visiter('/workouts')
        await attendreUneAnnonce()

        expect(annonces).toEqual([{ message: visites.PAGE_INDISPONIBLE, annuler: null }])
        expect(fabrique.enregistrements('gym-tracker-copies', 'pages').size).toBe(0)

        annonces.length = 0
        document.cookie = `gt_copies=${cleAuHasard()}; path=/`
        await garderUneCopie(pageDe())
        localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, 'compte:8')

        await visiter('/workouts')
        await attendreUneAnnonce()

        expect(annonces).toEqual([{ message: visites.PAGE_INDISPONIBLE, annuler: null }])
        expect(navigations).toEqual([])
    })

    it('la copie d’un autre compte que celui de la page affichée n’y est jamais servie', async () => {
        /*
         * Un onglet resté sur une page du compte 7 ; dans un autre onglet, 7
         * s'est déconnecté et 8 s'est connecté : titulaire, témoin et magasin
         * sont ceux de 8, qui garde la copie de ses séances, lisible. Sans
         * réseau, un lien de l'onglet de 7 ne montre pas les séances de 8.
         */
        localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, 'compte:8')
        await garderUneCopie(
            pageDe({
                texte: 'Séances de Dominique',
                auth: { user: { id: 8, name: 'Dominique Autrecompte', active_workout: null, latest_achievement: null } },
            }),
        )

        await visiter('/workouts')
        await attendreUneAnnonce()

        expect(lectures).toEqual(['/workouts'])
        expect(annonces).toEqual([{ message: visites.PAGE_INDISPONIBLE, annuler: null }])
        expect(contenu()).toBe('Séance en cours')
        expect(window.location.pathname).toBe('/workouts/12')
        expect(navigations).toEqual([])
    })

    it('le `auth` d’une réponse d’un autre compte ne passe jamais dans la copie de celui de la page', async () => {
        // La dernière réponse complète du serveur vient du compte 8, plus récente que la copie…
        const autreCompte = {
            user: { id: 8, name: 'Dominique Autrecompte', active_workout: null, latest_achievement: null },
        }
        await garderUneCopie(pageDe(), { le: maintenant - 60 * 1000 })
        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(
            pageDe({ url: '/tools', component: 'Tools/Index', texte: 'Outils de Dominique', auth: autreCompte }),
        )
        await visiter('/tools')
        expect(contenu()).toBe('Outils de Dominique')

        // … puis l'onglet revient, sans le serveur, à une page du compte 7.
        await new Promise((fini) => routeur.replace({ ...seanceOuverte(), onFinish: fini }))
        navigations.length = 0
        reseau.mode = 'coupe'

        await visiter('/workouts')
        await attendreUneCopie()

        expect(pageMontree().props.auth.user).toMatchObject({ id: COMPTE, name: 'Camille Horsligne' })
        expect(contenu()).toBe('Séances')
    })

    it('une écriture, un rechargement partiel, un préchargement ou une visite qui garde l’état n’en reçoivent pas', async () => {
        await garderUneCopie(pageDe())

        await visiter('/workouts', { method: 'post' })

        let fini = false
        routeur.reload({ only: ['texte'], onFinish: () => (fini = true) })
        await vi.waitUntil(() => fini)

        await visiter('/workouts', { preserveState: true })
        await vi.waitUntil(() => annonces.length === 2)

        // Un préchargement échoue sans un mot ; le cache qu'il laisse ne doit pas servir aux cas suivants.
        const parties = requetes.length
        routeur.prefetch('/workouts')
        await vi.waitUntil(() => requetes.length === parties + 1)
        await uneTache()
        routeur.flushAll()

        expect(annonces.map((annonce) => annonce.message)).toEqual([
            visites.ECRITURE_NON_CONFIRMEE,
            visites.PAGE_INDISPONIBLE,
        ])
        expect(lectures).toEqual([])
        expect(navigations).toEqual([])
    })

    it('un lien vers la page affichée ne la remplace pas par sa copie', async () => {
        await garderUneCopie(seanceOuverte())

        await visiter('/workouts/12')
        await attendreUneAnnonce()

        expect(annonces).toEqual([{ message: visites.PAGE_INDISPONIBLE, annuler: null }])
        expect(navigations).toEqual([])
    })

    it('une copie qu’un geste plus récent a dépassée n’est pas poussée', async () => {
        await garderUneCopie(pageDe())
        fermerLaPorte()

        await visiter('/workouts')
        await vi.waitUntil(() => lectures.length === 1)

        // Un autre lien touché pendant que la copie se lit : celui-là compte.
        reseau.mode = 'muet'
        routeur.visit('/dashboard')
        porte.ouvrir()
        await uneTache()
        await new Promise((resoudre) => setTimeout(resoudre, 100))

        expect(navigations).toEqual([])
        expect(annonces).toEqual([])
        expect(contenu()).toBe('Séance en cours')
    })
})

describe('un réseau qui ne répond pas', () => {
    it('au bout du délai, la copie remplace la visite, qui s’arrête, et la lenteur n’est jamais annoncée', async () => {
        await garderUneCopie(pageDe())
        reseau.mode = 'muet'

        routeur.visit('/workouts')
        await new Promise((resoudre) => setTimeout(resoudre, DELAI_AVANT_UNE_COPIE / 2))

        expect(navigations).toEqual([])

        await attendreUneCopie()

        expect(pageMontree().props.horsLigne).toEqual({
            copieDu: maintenant,
            raison: 'lent',
            datesDesProps: { auth: maintenant, errors: maintenant, texte: maintenant },
        })
        expect(requetes).toHaveLength(1)
        expect(requetes[0].annulee).toBe(true)
        expect(etatDuReseau.useEtatDuReseau().joignable.value).toBe(false)

        await new Promise((resoudre) => setTimeout(resoudre, DELAI_DE_LA_LENTEUR + 100))

        expect(annonces).toEqual([])
        expect(contenu()).toBe('Séances')
    })

    it('sans copie, la visite attend, et la lenteur s’annonce à son délai', async () => {
        reseau.mode = 'muet'

        routeur.visit('/exercises')
        await vi.waitUntil(() => annonces.length > 0, { timeout: DELAI_DE_LA_LENTEUR * 3 })

        expect(annonces).toEqual([{ message: visites.RESEAU_LENT, annuler: expect.any(Function) }])
        expect(requetes[0].annulee).toBe(false)
        expect(navigations).toEqual([])
    })

    it('une réponse arrivée avant le délai passe, et aucune copie ne la suit', async () => {
        await garderUneCopie(pageDe())
        reseau.mode = 'muet'
        let finie = false

        // Le serveur répond par une autre page (une redirection) : la copie des séances n'a plus rien à y faire.
        routeur.visit('/workouts', { onFinish: () => (finie = true) })
        await vi.waitUntil(() => requetes.length === 1)
        requetes[0].repondre(
            reponseInertia(pageDe({ url: '/dashboard', component: 'Dashboard', texte: 'Accueil du serveur' })),
        )
        await vi.waitUntil(() => finie)
        await new Promise((resoudre) => setTimeout(resoudre, DELAI_AVANT_UNE_COPIE * 2))

        expect(contenu()).toBe('Accueil du serveur')
        expect(navigations.filter((page) => page.props.horsLigne !== undefined)).toEqual([])
    })
})

describe('les délais et le branchement', () => {
    it('attend cinq secondes avant une copie, huit avant le toast de la lenteur', () => {
        expect(visites.DELAI_AVANT_UNE_COPIE_MS).toBe(5000)
        expect(visites.DELAI_DU_RESEAU_LENT_MS).toBe(8000)
    })
})
