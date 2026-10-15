import { describe, it, expect, vi, beforeAll, beforeEach, afterAll } from 'vitest'
import { createApp, h } from 'vue'
import { RequeteSimulee, reponseInertia, requetes, reseau, uneTache } from '../utils/reseauSimule'

/*
 * « Badge débloqué » sous le layout authentifié, avec le vrai client d'Inertia (#2052).
 *
 * Un compte qui débloque plusieurs badges d'un coup les voit l'un après
 * l'autre : le serveur ne partage que le plus récent des non lus
 * (`auth.user.latest_achievement`). Fermer la modale marque ce badge lu, et la
 * page qui revient porte le badge suivant.
 *
 * L'ancienne modale tombait dans l'ordre des évènements d'Inertia : la page est
 * posée, Vue rejoue ce qui la regarde (observateurs, rendu), et c'est SEULEMENT
 * ensuite qu'Inertia appelle le `onSuccess` de la visite. L'observateur de la
 * modale affichait le badge suivant, puis le rappel de la lecture effaçait « le
 * badge affiché », devenu ce suivant : la modale restait ouverte sur un badge
 * nul, et son rendu levait « Cannot read properties of null (reading 'icon') »,
 * une fois par badge suivant. Vu dans un vrai Chromium sur un compte à trois
 * badges non lus, deux fois, et rapporté dans `AuthenticatedLayout`, dont le
 * morceau porte la modale. Un faux `router` appelle ses rappels quand le test le
 * décide et ne rejoue pas cet ordre : d'où le vrai client, et le vrai layout,
 * que rien ne remplace ici. Le dernier badge fermé, la page n'en porte plus
 * aucun : ce cas-là, celui que l'issue soupçonnait, ne cassait pas ; il clôt
 * chaque parcours.
 *
 * Le réseau est celui de `reseauSimule.js`, muet : chaque requête attend que le
 * test la serve. `traiter()` joue le serveur, qui tient les badges non lus du
 * compte : une lecture marque son badge lu, puis la page se calcule à cet
 * instant, comme le navigateur la reçoit après la redirection de `back()`. Un
 * test peut donc traiter deux requêtes dans un ordre et livrer leurs réponses
 * dans l'autre.
 */

/** Les badges que le compte n'a pas lus, du plus récent au plus ancien. */
let nonLus = []

/** Les erreurs que Vue a relevées, rendu compris, avec le composant qui l'a levée et ses parents. */
const erreurs = []

let routeur
let application

/**
 * Un badge tel que le partage `HandleInertiaRequests` : ce qu'`AchievementUnlocked`
 * range en base, et le nom, l'icône et la description du badge lui-même.
 */
const unBadge = (id, nom, description) => ({
    id,
    name: nom,
    icon: '🏅',
    description,
    data: {
        type: 'achievement',
        title: 'Badge débloqué ! 🏆',
        message: `Félicitations ! Tu as débloqué le badge : ${nom}.`,
        achievement_id: 7,
        achieved_at: '2026-10-12T08:00:00.000000Z',
    },
    created_at: '2026-10-12T08:00:00.000000Z',
})

/** Le tableau de bord, tel que le serveur le rend à cet instant. */
const tableauDeBord = () => ({
    component: 'Dashboard',
    url: '/dashboard',
    version: 'v1',
    props: {
        is_testing: false,
        flash: {},
        auth: {
            user: {
                id: 1,
                name: 'Samuel',
                unread_notifications_count: nonLus.length,
                active_workout: null,
                latest_achievement: nonLus[0] ?? null,
            },
        },
    },
})

const cheminDe = (requete) => new URL(requete.url, window.location.href).pathname

/** Ce que le serveur a reçu, dans l'ordre. */
const recues = () => requetes.map((requete) => `${requete.methode} ${cheminDe(requete)}`)

/**
 * Le serveur traite `requete` : une lecture marque son badge lu, puis la page
 * se calcule. Rend la livraison de la réponse, que le test fait quand il veut.
 */
const traiter = (requete) => {
    const lecture = cheminDe(requete).match(/^\/notifications\/([^/]+)\/read$/)

    if (lecture) {
        nonLus = nonLus.filter((badge) => badge.id !== lecture[1])
    }

    const reponse = reponseInertia(tableauDeBord())

    return () => requete.repondre(reponse)
}

/** Les noms du composant et de ses parents, du plus proche au plus lointain. */
const lignee = (instance) => {
    const noms = []

    for (let composant = instance; composant; composant = composant.$parent) {
        noms.push(composant.$options.__name ?? composant.$options.name ?? '?')
    }

    return noms.join(' < ')
}

/** La route de Ziggy : `route()` dit la page courante, le tableau de bord. */
const route = (nom, parametres) => {
    if (nom === undefined) {
        return { current: (motif) => (motif === undefined ? 'dashboard' : motif === 'dashboard') }
    }

    return nom === 'notifications.mark-as-read' ? `/notifications/${parametres.id}/read` : `/${nom}`
}

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.stubGlobal('route', route)
    // jsdom ne fait pas défiler la page, et le dit à chaque visite.
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})

    document.body.replaceChildren(Object.assign(document.createElement('div'), { id: 'app' }))
    window.history.replaceState(null, '', '/dashboard')

    const inertia = await import('@inertiajs/vue3')
    const { default: AuthenticatedLayout } = await import('@/Layouts/AuthenticatedLayout.vue')
    routeur = inertia.router

    const TableauDeBord = { render: () => h(AuthenticatedLayout, null, () => h('p', 'Tableau de bord')) }

    await inertia.createInertiaApp({
        page: tableauDeBord(),
        resolve: () => TableauDeBord,
        setup: ({ el, App, props, plugin }) => {
            application = createApp({ render: () => h(App, props) })
            application.config.errorHandler = (erreur, instance, ou) =>
                erreurs.push(`${erreur} — ${ou}, dans ${lignee(instance)}`)
            application.config.globalProperties.route = route
            application.directive('press', {})
            application.use(plugin).mount(el)
        },
    })
})

beforeEach(() => {
    reseau.mode = 'muet'
    reseau.prochains = []
    requetes.length = 0
    erreurs.length = 0
})

afterAll(() => {
    application.unmount()
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

const dialogue = () => document.querySelector('dialog')
/** Ce que la modale montre, quelle qu'en soit la mise en page : l'ancienne n'avait que le message, qui nomme le badge. */
const texteDuDialogue = () => dialogue()?.textContent.replace(/\s+/g, ' ').trim() ?? ''
const nomDuBadge = () => document.getElementById('achievement-name')?.textContent.trim() ?? null
const boutonDeFermeture = () => document.querySelector('[dusk="celebration-dismiss"]')

/** Attend la fin de la visite que `geste` mène, puis une tâche : ce que `onSuccess` change se rend après la page. */
const jusquALaFinDeLaVisite = async (geste) => {
    let finie = false
    const arreter = routeur.on('finish', () => (finie = true))

    await geste()
    await vi.waitUntil(() => finie)
    arreter()
    await uneTache()
}

/** Mène la visite que `geste` déclenche, que le serveur traite et livre aussitôt. */
const visiteServie = (geste) =>
    jusquALaFinDeLaVisite(async () => {
        const avant = requetes.length

        geste()
        await vi.waitUntil(() => requetes.length > avant)
        traiter(requetes[avant])()
    })

const fermerParLeBouton = () => visiteServie(() => boutonDeFermeture().click())

/** Échap ferme un `<dialog>` de lui-même : le composant ne l'apprend que par l'évènement `close`. */
const fermerParEchap = () => visiteServie(() => dialogue().close())

/** Ouvre le tableau de bord d'un compte qui a débloqué `badges`, du plus récent au plus ancien : une page neuve. */
const ouvrirLeTableauDeBordAvec = async (...badges) => {
    nonLus = badges
    await visiteServie(() => routeur.visit('/dashboard'))
    requetes.length = 0
}

describe('« Badge débloqué » sous le layout, avec plusieurs badges non lus', () => {
    it('montre les badges l’un après l’autre, puis se ferme quand le serveur n’en rend plus, sans erreur', async () => {
        await ouvrirLeTableauDeBordAvec(
            unBadge('guerrier', 'Guerrier de la Semaine', 'Tu as complété 3 séances en une semaine.'),
            unBadge('novice', 'Novice du Volume', 'Tu as soulevé 1 000 kg au total.'),
            unBadge('premiere', 'Première Séance', 'Tu as terminé ta première séance.'),
        )

        expect(dialogue().open).toBe(true)
        expect(texteDuDialogue()).toContain('Guerrier de la Semaine')

        await fermerParLeBouton()

        expect(erreurs).toEqual([])
        expect(recues()).toEqual(['POST /notifications/guerrier/read'])
        expect(dialogue().open).toBe(true)
        expect(texteDuDialogue()).toContain('Novice du Volume')
        expect(nomDuBadge()).toBe('Novice du Volume')

        await fermerParLeBouton()

        expect(erreurs).toEqual([])
        expect(dialogue().open).toBe(true)
        expect(nomDuBadge()).toBe('Première Séance')

        await fermerParLeBouton()

        expect(erreurs).toEqual([])
        expect(recues()).toEqual([
            'POST /notifications/guerrier/read',
            'POST /notifications/novice/read',
            'POST /notifications/premiere/read',
        ])
        expect(dialogue().open).toBe(false)
        expect(nomDuBadge()).toBeNull()
    })

    it('fermée par Échap, passe au badge suivant sans erreur, et lit chaque badge une seule fois', async () => {
        await ouvrirLeTableauDeBordAvec(
            unBadge('record', 'Premier Record', 'Tu as battu ton premier record.'),
            unBadge('volume', 'Novice du Volume', 'Tu as soulevé 1 000 kg au total.'),
        )

        expect(dialogue().open).toBe(true)
        expect(texteDuDialogue()).toContain('Premier Record')

        await fermerParEchap()

        expect(erreurs).toEqual([])
        expect(recues()).toEqual(['POST /notifications/record/read'])
        expect(dialogue().open).toBe(true)
        expect(nomDuBadge()).toBe('Novice du Volume')

        await fermerParEchap()

        expect(erreurs).toEqual([])
        expect(recues()).toEqual(['POST /notifications/record/read', 'POST /notifications/volume/read'])
        expect(dialogue().open).toBe(false)
    })

    /**
     * Tirer pour rafraîchir pendant la lecture : le serveur peut traiter ce
     * rechargement avant elle, et sa réponse arriver après : sa page porte
     * encore le badge que la personne vient de fermer. L'ancienne modale
     * l'avait oublié dans le rappel de la lecture, et le rouvrait.
     */
    it('ne rouvre pas un badge fermé que rapporte un rechargement traité avant la lecture', async () => {
        await ouvrirLeTableauDeBordAvec(unBadge('serie', 'Série de Feu', 'Tu t’es entraîné 7 jours de suite.'))

        expect(dialogue().open).toBe(true)
        expect(texteDuDialogue()).toContain('Série de Feu')

        boutonDeFermeture().click()
        routeur.reload()
        await vi.waitUntil(() => requetes.length === 2)

        const [lecture, rechargement] = ['POST', 'GET'].map((methode) =>
            requetes.find((requete) => requete.methode === methode),
        )
        const livrerLeRechargement = traiter(rechargement)
        const livrerLaLecture = traiter(lecture)

        await jusquALaFinDeLaVisite(livrerLaLecture)

        expect(dialogue().open).toBe(false)

        await jusquALaFinDeLaVisite(livrerLeRechargement)

        expect(erreurs).toEqual([])
        expect(recues()).toEqual(['POST /notifications/serie/read', 'GET /dashboard'])
        expect(dialogue().open).toBe(false)
        expect(nomDuBadge()).toBeNull()
    })
})
