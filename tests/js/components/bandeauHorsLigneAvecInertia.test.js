import { afterAll, afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest'
import { createApp, h } from 'vue'

import { creerUneFabriqueEnMemoire } from '../utils/baseIndexeeEnMemoire'
import {
    RequeteSimulee,
    relayerLesRejetsNonGeres,
    reponseInertia,
    requetes,
    reseau,
    uneTache,
} from '../utils/reseauSimule'
import { CLEF_DU_TITULAIRE_DE_L_APPAREIL } from '@/Utils/historiqueDuCompte'

/*
 * Le bandeau d'une copie hors ligne et son « Réessayer » (#2048, partie E),
 * avec le vrai client d'Inertia, le vrai service des copies et le vrai
 * magasin chiffré, sur une base en mémoire.
 *
 * Une page de salle ouverte sans réseau vient de sa copie : le bandeau le dit
 * et la date. « Réessayer » la recharge en entier, comme le retour du réseau,
 * et dit ce qui l'en empêche : sans réseau, la page n'a pas été rafraîchie ;
 * sans réponse au bout du délai, le réseau ne répond pas, avec « Annuler » ;
 * sur la séance, ses écritures attendent encore. Une réponse complète retire
 * la marque, et le bandeau avec elle.
 *
 * Le client se monte une fois pour le fichier ; chaque cas repart de la page
 * des outils, remise par une visite du client seul, et d'un magasin vide
 * (#2044). Les délais sont raccourcis : le chiffrement passe par le
 * `crypto.subtle` de Node, que des minuteries simulées ne font pas avancer.
 */

const COMPTE = 7

/** Le délai avant une copie, raccourci pour ce fichier (5 s dans l'application). */
const DELAI_AVANT_UNE_COPIE = 80

/** Le délai avant le toast de la lenteur, raccourci pour ce fichier (8 s dans l'application). */
const DELAI_DE_LA_LENTEUR = 300

/** Le lundi 12 octobre 2026 à 18:04, à l'heure de l'appareil : l'heure du magasin. */
const LUNDI_18_04 = new Date(2026, 9, 12, 18, 4).getTime()

/** Une clé de 32 octets tirés au hasard, en base64url, comme le serveur la tire. */
const cleAuHasard = () =>
    btoa(String.fromCharCode(...crypto.getRandomValues(new Uint8Array(32))))
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '')

/**
 * Une page du compte, telle que le serveur la rend en entier.
 *
 * @param {{ url?: string, component?: string, texte?: string }} [options]
 */
const pageDe = ({ url = '/workouts', component = 'Workouts/Index', texte = 'Séances' } = {}) => ({
    component,
    url,
    version: 'version-de-la-page',
    encryptHistory: true,
    clearHistory: false,
    props: {
        errors: {},
        auth: { user: { id: COMPTE, name: 'Camille Horsligne', active_workout: null, latest_achievement: null } },
        texte,
    },
})

const pageDeDepart = () => pageDe({ url: '/tools', component: 'Tools/Index', texte: 'Outils' })

let routeur
let fabrique
let magasin
let etatDuReseau
let visites
let application
let relais
let pageInertia

const annonces = []
const ecouterLesAnnonces = (evenement) => annonces.push(evenement.detail)

/** Ce que la file de la séance dit encore. */
const file = { enAttente: 0 }

const contenu = () => document.getElementById('contenu')?.textContent
const bandeau = () => document.querySelector('[dusk="bandeau-hors-ligne"]')
const titreDuBandeau = () => document.querySelector('[dusk="bandeau-hors-ligne-titre"]')?.textContent
const boutonReessayer = () => document.querySelector('[dusk="reessayer-hors-ligne"]')

/** Les requêtes parties depuis `depuis`, réduites à ce qu'on en lit. */
const requetesDepuis = (depuis) =>
    requetes.slice(depuis).map((requete) => ({
        methode: requete.methode.toUpperCase(),
        chemin: new URL(requete.url, window.location.href).pathname,
        partielle: requete.entetes['X-Inertia-Partial-Data'] !== undefined,
    }))

/** Lance une visite et attend sa fin, quelle qu'en soit l'issue. */
const visiter = async (adresse) => {
    let finie = false

    routeur.visit(adresse, { onFinish: () => (finie = true) })
    await vi.waitUntil(() => finie)
    await uneTache()
}

/** Attend que le bandeau d'une copie soit à l'écran, pour `adresse`. */
const attendreLeBandeau = (adresse) =>
    vi.waitUntil(() => bandeau() !== null && pageInertia?.url === adresse, { timeout: 3000 })

/** Sert, sans réseau, la copie d'une page gardée. */
const servirLaCopieDe = async (page) => {
    expect(await magasin.ecrireUneCopie(page)).toBe('ecrite')
    reseau.mode = 'coupe'
    await visiter(page.url)
    await attendreLeBandeau(page.url)
}

/** Laisse passer un moment : assez pour qu'une requête de trop parte. */
const unMoment = () => new Promise((resoudre) => setTimeout(resoudre, 150))

/** Appuie sur « Réessayer » et attend que le rechargement soit fini, ou n'ait pas eu lieu. */
const reessayer = async () => {
    boutonReessayer().click()
    await uneTache()
    await vi.waitUntil(() => !boutonReessayer()?.disabled, { timeout: 3000 })
}

let BandeauHorsLigne
let ExerciseCard

/** Une page de l'application : le bandeau du layout, son texte, et un bouton qui écrirait. */
const Page = {
    props: ['texte'],
    render() {
        return h('div', [
            h(BandeauHorsLigne),
            h('p', { id: 'contenu' }, this.texte),
            h(ExerciseCard, {
                exercise: { id: 3, name: 'Squat', type: 'strength', category: 'Jambes' },
                isEditing: false,
                editForm: {},
                category: 'Jambes',
                types: [],
                categories: [],
                categoryBorderColors: {},
                typeIcons: {},
                typeLabel: (type) => type,
            }),
        ])
    },
}

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})
    document.body.innerHTML = '<div id="app"></div>'
    window.history.replaceState(null, '', '/tools')
    globalThis.route = (nom) => `/${nom}`

    const { creerLeMagasinDesCopies, ouvrirLaBaseDuNavigateur } = await import('@/Utils/copiesHorsLigne')

    fabrique = creerUneFabriqueEnMemoire()
    magasin = creerLeMagasinDesCopies({
        ouvrirLaBase: () => ouvrirLaBaseDuNavigateur(fabrique),
        horloge: () => LUNDI_18_04,
    })
    etatDuReseau = await import('@/composables/useEtatDuReseau')
    visites = await import('@/Utils/visitesHorsLigne')
    BandeauHorsLigne = (await import('@/Components/UI/BandeauHorsLigne.vue')).default
    ExerciseCard = (await import('@/Components/Workout/ExerciseCard.vue')).default

    const inertia = await import('@inertiajs/vue3')
    routeur = inertia.router

    visites.installerLesVisitesHorsLigne({
        routeur,
        delaiAvantUneCopie: DELAI_AVANT_UNE_COPIE,
        delaiDuReseauLent: DELAI_DE_LA_LENTEUR,
        lireUneCopie: (adresse) => magasin.lireUneCopie(adresse),
        laSeanceEstEnregistree: async () => file.enAttente === 0,
        horloge: () => LUNDI_18_04,
    })

    for (const evenement of ['navigate', 'clientVisit', 'success']) {
        routeur.on(evenement, (detail) => (pageInertia = detail.detail.page))
    }

    await inertia.createInertiaApp({
        page: pageDeDepart(),
        resolve: () => Page,
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) })
            application.directive('press', {})
            application.config.globalProperties.route = globalThis.route
            application.use(plugin).mount(el)
        },
    })

    await vi.waitUntil(() => pageInertia !== undefined)
    window.addEventListener(visites.EVENEMENT_SANS_RESEAU, ecouterLesAnnonces)
})

beforeEach(async () => {
    await new Promise((fini) => routeur.replace({ ...pageDeDepart(), onFinish: fini }))

    fabrique.enregistrements('gym-tracker-copies', 'pages').clear()
    fabrique.enregistrements('gym-tracker-copies', 'etat').clear()
    localStorage.clear()
    localStorage.setItem(CLEF_DU_TITULAIRE_DE_L_APPAREIL, `compte:${COMPTE}`)
    document.cookie = `gt_copies=${cleAuHasard()}; path=/`
    file.enAttente = 0
    requetes.length = 0
    annonces.length = 0
    reseau.mode = 'coupe'
    reseau.prochains.length = 0
    reseau.reponse = null
    etatDuReseau.noterQueLeServeurRepond()
    relais = relayerLesRejetsNonGeres()
})

afterEach(async () => {
    relais.retirer()
    routeur.cancelAll()
    await uneTache()
})

afterAll(async () => {
    window.removeEventListener(visites.EVENEMENT_SANS_RESEAU, ecouterLesAnnonces)
    application.unmount()
    await magasin.fermer()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

describe('le bandeau d’une copie', () => {
    it('sans réseau, un lien vers une page gardée l’ouvre aussitôt, avec le bandeau qui date la copie', async () => {
        expect(bandeau()).toBeNull()

        await servirLaCopieDe(pageDe())

        expect(contenu()).toBe('Séances')
        expect(titreDuBandeau()).toBe('Hors ligne · copie du lun. 12 oct. 2026 à 18:04')
        expect(bandeau().querySelector('[role="status"]').textContent).toContain(
            "Ce qui a changé depuis n'apparaît pas ici.",
        )
        expect(annonces).toEqual([])
        expect(relais.rejets).toEqual([])
    })

    it('depuis la copie, un lien vers une page qui n’est pas gardée laisse la copie et son bandeau, et le dit', async () => {
        await servirLaCopieDe(pageDe())

        await visiter('/stats')

        expect(annonces).toEqual([{ message: visites.PAGE_INDISPONIBLE, annuler: null }])
        expect(window.location.pathname).toBe('/workouts')
        expect(contenu()).toBe('Séances')
        expect(titreDuBandeau()).toBe('Hors ligne · copie du lun. 12 oct. 2026 à 18:04')
        expect(relais.rejets).toEqual([])
    })

    it('servie pour lenteur, la copie dit que le réseau ne répond pas', async () => {
        expect(await magasin.ecrireUneCopie(pageDe())).toBe('ecrite')
        reseau.mode = 'muet'

        routeur.visit('/workouts')
        await attendreLeBandeau('/workouts')

        expect(titreDuBandeau()).toBe('Le réseau ne répond pas · copie du lun. 12 oct. 2026 à 18:04')
    })
})

describe('« Réessayer »', () => {
    it('le réseau revenu, recharge la page en entier : le bandeau part avec la marque', async () => {
        /*
         * Un bouton qui écrirait par une visite : supprimer un exercice. Démarrer
         * une séance, l'exemple d'avant, passe par la file depuis #2049, et reste
         * ouvert sur une copie.
         */
        const demarrer = () => document.querySelector('[dusk="delete-exercise-btn-3"]')

        expect(demarrer().disabled).toBe(false)

        await servirLaCopieDe(pageDe())
        const depuis = requetes.length

        // Sur la copie, un bouton qui écrirait le dit d'avance (`useCopieHorsLigne`).
        expect(demarrer().disabled).toBe(true)
        expect(demarrer().getAttribute('aria-describedby')).toBe('ecritures-sur-une-copie')
        expect(document.getElementById('ecritures-sur-une-copie')).not.toBeNull()

        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(pageDe({ texte: 'Séances du serveur' }))
        await reessayer()
        await vi.waitUntil(() => contenu() === 'Séances du serveur')
        await unMoment()

        expect(requetesDepuis(depuis)).toEqual([{ methode: 'GET', chemin: '/workouts', partielle: false }])
        expect(bandeau()).toBeNull()
        expect(pageInertia.props.horsLigne).toBeUndefined()
        expect(annonces).toEqual([])
        expect(demarrer().disabled).toBe(false)
        expect(demarrer().hasAttribute('aria-describedby')).toBe(false)
    })

    it('sans réseau, le dit, et la copie reste', async () => {
        await servirLaCopieDe(pageDe())
        const depuis = requetes.length

        await reessayer()

        expect(requetesDepuis(depuis)).toEqual([{ methode: 'GET', chemin: '/workouts', partielle: false }])
        expect(annonces).toEqual([{ message: visites.RAFRAICHISSEMENT_SANS_RESEAU, annuler: null }])
        expect(contenu()).toBe('Séances')
        expect(bandeau()).not.toBeNull()
        expect(boutonReessayer().disabled).toBe(false)
        expect(relais.rejets).toEqual([])
    })

    it('sans réponse, annonce au bout du délai que le réseau ne répond pas, et « Annuler » l’arrête', async () => {
        await servirLaCopieDe(pageDe())
        const depuis = requetes.length
        reseau.mode = 'muet'

        boutonReessayer().click()
        await vi.waitUntil(() => requetes.length > depuis)
        await uneTache()

        expect(boutonReessayer().disabled).toBe(true)
        expect(annonces).toEqual([])

        await vi.waitUntil(() => annonces.length > 0, { timeout: DELAI_DE_LA_LENTEUR * 4 })

        expect(annonces).toEqual([{ message: visites.RESEAU_LENT, annuler: expect.any(Function) }])

        annonces[0].annuler()
        await vi.waitUntil(() => !boutonReessayer().disabled)

        expect(requetes[depuis].annulee).toBe(true)
        expect(annonces.at(-1)).toEqual({ message: null, retire: visites.RESEAU_LENT })
        expect(contenu()).toBe('Séances')
        expect(bandeau()).not.toBeNull()
    })

    it('une réponse avant le délai n’annonce aucune lenteur', async () => {
        await servirLaCopieDe(pageDe())
        const depuis = requetes.length
        reseau.mode = 'muet'

        boutonReessayer().click()
        await vi.waitUntil(() => requetes.length > depuis)
        requetes[depuis].repondre(reponseInertia(pageDe({ texte: 'Séances du serveur' })))
        await vi.waitUntil(() => contenu() === 'Séances du serveur')
        await new Promise((resoudre) => setTimeout(resoudre, DELAI_DE_LA_LENTEUR * 2))

        expect(annonces).toEqual([])
        expect(bandeau()).toBeNull()
    })

    it('sur la séance, attend que sa file soit vide, et dit que ses écritures attendent', async () => {
        file.enAttente = 1
        await servirLaCopieDe(pageDe({ url: '/workouts/12', component: 'Workouts/Show', texte: 'Séance en cours' }))
        const depuis = requetes.length

        // Le serveur répond de nouveau, mais la file de la séance ne s'est pas encore vidée.
        etatDuReseau.noterQueLeServeurRepond()
        await unMoment()
        await reessayer()
        await unMoment()

        expect(requetesDepuis(depuis)).toEqual([])
        expect(annonces).toEqual([{ message: visites.SEANCE_APRES_SES_ECRITURES, annuler: null }])

        // Sans réseau, c'est le réseau qui manque.
        etatDuReseau.noterQueLeServeurNeRepondPas()
        await reessayer()

        expect(annonces.at(-1)).toEqual({ message: visites.RAFRAICHISSEMENT_SANS_RESEAU, annuler: null })
        expect(requetesDepuis(depuis)).toEqual([])

        // La file vidée, la séance se recharge.
        file.enAttente = 0
        reseau.mode = 'repond'
        reseau.reponse = reponseInertia(
            pageDe({ url: '/workouts/12', component: 'Workouts/Show', texte: 'Séance du serveur' }),
        )
        await reessayer()
        await vi.waitUntil(() => contenu() === 'Séance du serveur')

        expect(requetesDepuis(depuis)).toEqual([{ methode: 'GET', chemin: '/workouts/12', partielle: false }])
        expect(bandeau()).toBeNull()
    })

    it('sur une page que le serveur a rendue, ne fait rien', async () => {
        await visites.reessayerLaCopie()
        await unMoment()

        expect(requetes).toEqual([])
        expect(annonces).toEqual([])
    })
})
