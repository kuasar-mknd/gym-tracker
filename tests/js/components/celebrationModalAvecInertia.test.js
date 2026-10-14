import { describe, it, expect, vi, beforeAll, beforeEach, afterAll } from 'vitest'
import { createApp, h } from 'vue'

/*
 * La célébration d'un badge, avec le vrai client d'Inertia (#2052).
 *
 * Un compte qui débloque plusieurs badges d'un coup les voit l'un après
 * l'autre : le serveur ne partage que le plus récent des non lus
 * (`latest_achievement`). Fermer la modale marque ce badge lu, le serveur
 * répond par la page avec le badge suivant, et la modale le montre.
 *
 * C'est l'ordre des évènements d'Inertia qui faisait tomber la modale : la page
 * est posée, Vue rejoue ce qui la regarde, PUIS le `onSuccess` de la visite est
 * appelé. Le badge suivant était donc déjà affiché quand le rappel de la
 * lecture effaçait « le badge affiché », devenu le suivant : la modale restait
 * ouverte sur un badge nul, et son rendu levait « Cannot read properties of
 * null (reading 'icon') », vu dans un vrai Chromium. Un composant monté avec un
 * faux `router` ne rejoue pas cet ordre : d'où le vrai client ici. Le dernier
 * badge fermé, la page n'en porte plus aucun : ce cas-là ne cassait pas, il
 * clôt chaque parcours.
 *
 * jsdom ne joint pas de serveur : `XMLHttpRequest` est remplacé par un faux
 * serveur qui tient les badges non lus du compte et calcule la page quand il
 * traite chaque requête, comme le vrai. La lecture y répond directement par la
 * page, comme le navigateur la rend après la redirection de `back()`. Un test
 * peut retenir les requêtes, pour choisir l'ordre dans lequel le serveur les
 * traite puis celui dans lequel leurs réponses arrivent.
 */

/** Les badges que le compte n'a pas lus, du plus récent au plus ancien. */
let nonLus = []
/** Les requêtes reçues par le faux serveur. */
const requetes = []
/** Les erreurs que Vue a relevées, celles du rendu comprises. */
const erreurs = []
/** Vrai, le faux serveur garde chaque requête sans la traiter, jusqu'à ce que le test le fasse. */
let retenir = false
/** Les requêtes retenues, chacune avec son traitement par le serveur et la livraison de sa réponse. */
const retenues = []

/** Un badge tel que le partage `HandleInertiaRequests` : ce qu'`AchievementUnlocked` range en base, et le badge lui-même. */
const unBadge = (id, nom) => ({
    id,
    name: nom,
    icon: '🏅',
    description: `Ce qui a valu « ${nom} ».`,
    data: {
        type: 'achievement',
        title: 'Badge débloqué ! 🏆',
        message: `Félicitations ! Tu as débloqué le badge : ${nom}.`,
        achievement_id: 7,
        achieved_at: '2026-10-12T08:00:00.000000Z',
    },
    created_at: '2026-10-12T08:00:00.000000Z',
})

/** La page du tableau de bord, telle que le serveur la rend à cet instant. */
const tableauDeBord = () => ({
    component: 'Dashboard',
    url: '/dashboard',
    version: 'v1',
    props: {
        errors: {},
        is_testing: false,
        auth: { user: { id: 1, latest_achievement: nonLus[0] ?? null } },
    },
})

class RequeteSimulee {
    upload = {}
    status = 0
    responseText = ''

    open(methode, url) {
        this.methode = methode
        this.url = url
    }

    setRequestHeader() {}

    getAllResponseHeaders() {
        return 'x-inertia: true\r\ncontent-type: application/json'
    }

    abort() {
        this.onabort?.()
    }

    send() {
        const chemin = new URL(this.url, window.location.href).pathname
        requetes.push(`${this.methode} ${chemin}`)

        let corps = null

        /** Le serveur traite la requête : la lecture marque son badge lu, puis la page se calcule. */
        const traiter = () => {
            const lecture = chemin.match(/^\/notifications\/([^/]+)\/read$/)

            if (lecture) {
                nonLus = nonLus.filter((badge) => badge.id !== lecture[1])
            }

            corps = JSON.stringify(tableauDeBord())
        }

        const livrer = () => {
            this.status = 200
            this.responseText = corps
            this.onload?.()
        }

        if (retenir) {
            retenues.push({ methode: this.methode, traiter, livrer })

            return
        }

        traiter()
        queueMicrotask(livrer)
    }
}

let routeur

beforeAll(async () => {
    vi.stubGlobal('XMLHttpRequest', RequeteSimulee)
    vi.stubGlobal('route', (nom, parametres) => {
        expect(nom).toBe('notifications.mark-as-read')

        return `/notifications/${parametres.id}/read`
    })
    // jsdom ne fait pas défiler la page, et le dit à chaque visite.
    vi.spyOn(window, 'scrollTo').mockImplementation(() => {})

    document.body.replaceChildren(Object.assign(document.createElement('div'), { id: 'app' }))
    window.history.replaceState(null, '', '/dashboard')

    nonLus = [unBadge('guerrier', 'Guerrier de la Semaine'), unBadge('novice', 'Novice du Volume')]

    const inertia = await import('@inertiajs/vue3')
    const { default: CelebrationModal } = await import('@/Components/Achievements/CelebrationModal.vue')
    routeur = inertia.router

    // Le tableau de bord rend le layout, qui rend la modale : ici, la modale seule.
    const TableauDeBord = { render: () => h('main', [h(CelebrationModal)]) }

    await inertia.createInertiaApp({
        page: tableauDeBord(),
        resolve: () => TableauDeBord,
        setup: ({ el, App, props, plugin }) => {
            const application = createApp({ render: () => h(App, props) })

            application.config.errorHandler = (erreur) => erreurs.push(erreur)

            return application.use(plugin).directive('press', {}).mount(el)
        },
    })
})

beforeEach(() => {
    requetes.length = 0
    erreurs.length = 0
})

afterAll(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

const dialogue = () => document.querySelector('dialog')
const nomDuBadge = () => document.getElementById('achievement-name')?.textContent.trim() ?? null
const boutonDeFermeture = () => document.querySelector('[dusk="celebration-dismiss"]')

/** Attend la fin de la visite que `geste` déclenche, puis le rendu de ce qu'elle a changé. */
const jusquALaFinDeLaVisite = async (geste) => {
    let finie = false
    const arreter = routeur.on('finish', () => (finie = true))

    geste()

    await vi.waitUntil(() => finie)
    arreter()
    // Le rappel `onSuccess` passe après la page : ce qu'il change se rend au tour suivant.
    await new Promise((resolve) => setTimeout(resolve, 0))
}

const fermerParLeBouton = () => jusquALaFinDeLaVisite(() => boutonDeFermeture().click())

/** Échap ferme un `<dialog>` de lui-même : le composant ne l'apprend que par l'évènement `close`. */
const fermerParEchap = () => jusquALaFinDeLaVisite(() => dialogue().close())

/** Montre au compte les badges donnés, du plus récent au plus ancien, par un rechargement de la page. */
const debloquer = async (...badges) => {
    nonLus = badges
    await jusquALaFinDeLaVisite(() => routeur.reload())
    requetes.length = 0
}

describe('la célébration de plusieurs badges', () => {
    it('les montre l’un après l’autre, puis se ferme quand le serveur n’en rend plus, sans erreur', async () => {
        expect(dialogue().open).toBe(true)
        expect(nomDuBadge()).toBe('Guerrier de la Semaine')

        await fermerParLeBouton()

        expect(requetes).toEqual(['POST /notifications/guerrier/read'])
        expect(erreurs).toEqual([])
        expect(dialogue().open).toBe(true)
        expect(nomDuBadge()).toBe('Novice du Volume')

        await fermerParLeBouton()

        expect(requetes).toEqual(['POST /notifications/guerrier/read', 'POST /notifications/novice/read'])
        expect(erreurs).toEqual([])
        expect(dialogue().open).toBe(false)
        expect(nomDuBadge()).toBeNull()
    })

    it('fermée par Échap, passe au badge suivant sans erreur, et lit chaque badge une seule fois', async () => {
        await debloquer(unBadge('premiere', 'Première Séance'), unBadge('volume', 'Novice du Volume'))

        expect(dialogue().open).toBe(true)
        expect(nomDuBadge()).toBe('Première Séance')

        await fermerParEchap()

        expect(requetes).toEqual(['POST /notifications/premiere/read'])
        expect(erreurs).toEqual([])
        expect(dialogue().open).toBe(true)
        expect(nomDuBadge()).toBe('Novice du Volume')

        await fermerParEchap()

        expect(requetes).toEqual(['POST /notifications/premiere/read', 'POST /notifications/volume/read'])
        expect(erreurs).toEqual([])
        expect(dialogue().open).toBe(false)
    })

    /**
     * Un rechargement parti pendant la lecture (tirer pour actualiser) peut
     * être traité par le serveur avant elle, et répondre après elle : sa page
     * porte encore le badge que la personne vient de fermer. L'ancienne modale
     * avait oublié ce badge dans le rappel de la lecture, et le rouvrait.
     */
    it('ne rouvre pas un badge fermé que rapporte un rechargement traité avant la lecture', async () => {
        await debloquer(unBadge('record', 'Premier Record'))

        expect(dialogue().open).toBe(true)
        expect(nomDuBadge()).toBe('Premier Record')

        retenir = true
        boutonDeFermeture().click()
        routeur.reload()
        await vi.waitUntil(() => retenues.length === 2)
        retenir = false

        const [lecture, rechargement] = ['POST', 'GET'].map((methode) =>
            retenues.find((requete) => requete.methode === methode),
        )
        retenues.length = 0

        rechargement.traiter()
        lecture.traiter()
        await jusquALaFinDeLaVisite(lecture.livrer)

        expect(dialogue().open).toBe(false)

        await jusquALaFinDeLaVisite(rechargement.livrer)

        expect(requetes).toEqual(['POST /notifications/record/read', 'GET /dashboard'])
        expect(erreurs).toEqual([])
        expect(dialogue().open).toBe(false)
        expect(nomDuBadge()).toBeNull()
    })
})
