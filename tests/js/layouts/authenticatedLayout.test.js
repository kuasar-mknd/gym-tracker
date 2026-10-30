import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'

const page = reactive({ props: {}, url: '/dashboard' })

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => page,
}))

/** Le rapprochement se teste dans useAbonnementPush.test.js ; ici, seulement l'appel. */
const rapprocherLAbonnementPush = vi.hoisted(() => vi.fn())
vi.mock('@/composables/useAbonnementPush', () => ({ rapprocherLAbonnementPush }))

/**
 * Le réseau ne répond pas : une écriture du compte que la file garde y reste,
 * sans partir vers un serveur que jsdom ne joint pas.
 */
vi.mock('@/Utils/http', () => ({ http: () => Promise.reject({ code: 'ERR_NETWORK', request: {} }) }))

/** La déconnexion se teste dans useDeconnexion.test.js ; ici, seulement l'appel. */
const seDeconnecter = vi.hoisted(() => vi.fn())
vi.mock('@/composables/useDeconnexion', () => ({ seDeconnecter }))

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { CIBLE, cibleDuRendu } from '../conventions/ciblesTactiles'
import { titresExposes } from '../conventions/exposition'
import { PAGE_INDISPONIBLE, RESEAU_LENT, annoncerSansReseau, retirerLAnnonce } from '@/Utils/visitesHorsLigne'
import { chargerSyncService, horodatageRecent, poserLaPage, retirerLesEcouteursDuService } from '../utils/fileHorsLigne'

/** Ziggy, including the wildcard patterns the desktop nav matches on. */
let currentRoute = 'dashboard'
const route = (name) => {
    if (name === undefined) {
        return {
            current: (pattern) => {
                if (pattern === undefined) return currentRoute
                if (!currentRoute) return false

                // Tous les métacaractères sont échappés d'abord — l'antislash
                // compris, que l'ancienne version laissait passer — puis seule
                // l'étoile est réactivée en joker, comme le fait Ziggy.
                const escaped = pattern.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')

                return new RegExp(`^${escaped.replace(/\\\*/g, '.*')}$`).test(currentRoute)
            },
        }
    }

    return `/resolved/${name}`
}
globalThis.route = route

const stubs = {
    LiquidBackground: true,
    CelebrationModal: true,
    BottomNav: true,
    NavLink: { props: ['href', 'active'], template: '<a :href="href" :data-active="String(active)"><slot /></a>' },
    DropdownLink: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    Dropdown: { template: '<div><slot name="trigger" :open="false" /><slot name="content" /></div>' },
    ActiveWorkoutBanner: { name: 'ActiveWorkoutBanner', props: ['workout'], template: '<div />' },
    // La question d'avant la déconnexion a ses propres tests (questionDeDeconnexion.test.js).
    QuestionDeDeconnexion: true,
}

const user = (overrides = {}) => ({ name: 'Samuel', unread_notifications_count: 0, ...overrides })

/**
 * Every layout mounted here watches the one shared `page`, so a layout left
 * mounted keeps reacting to the *next* test's flash and arming its own
 * dismissal timers. They are unmounted after each test.
 */
const mountedLayouts = []

/** Le composant qu'Inertia montre sur chaque route des tests : la page de séance se reconnaît à lui (#2049). */
const COMPOSANTS = { 'workouts.show': 'Workouts/Show' }

const mountLayout = ({
    props = {},
    slots = {},
    flash = {},
    auth = { user: user() },
    onPage = 'dashboard',
    url = `/${onPage}`,
    component = COMPOSANTS[onPage] ?? 'Dashboard',
} = {}) => {
    currentRoute = onPage
    page.props = { flash, auth }
    page.url = url
    page.component = component

    const wrapper = mount(AuthenticatedLayout, {
        props,
        slots: { default: '<p data-testid="page-content">contenu</p>', ...slots },
        global: { directives: { press: {} }, mocks: { route, $page: page }, stubs },
    })
    mountedLayouts.push(wrapper)

    return wrapper
}

/** Unmounts a layout mid-test, without the cleanup below unmounting it twice. */
const unmountLayout = (wrapper) => {
    mountedLayouts.splice(mountedLayouts.indexOf(wrapper), 1)
    wrapper.unmount()
}

const toasts = (wrapper) => wrapper.findAll('[role="alert"]')
const notificationLinks = (wrapper) => wrapper.findAll('a[href="/resolved/notifications.index"]')

/** The message of a toast, without the icon and close-button glyphs around it. */
const messageOf = (toast) => toast.get('p').text()

/**
 * Runs the clock forward in small steps and reports, for each message, the
 * moment its toast left the screen — or `null` if it never did. All the
 * messages are followed in a single pass, since the clock only moves forwards.
 *
 * @param {object} wrapper A mounted layout, under fake timers.
 * @param {string[]} messages The flashed messages to follow.
 * @returns {Promise<Object<string, ?number>>} Milliseconds from mount, per message.
 */
const dismissalTimes = async (wrapper, messages) => {
    const step = 250
    const giveUpAfter = 60000
    const times = Object.fromEntries(messages.map((message) => [message, null]))
    const stillUp = (message) => toasts(wrapper).some((toast) => messageOf(toast) === message)

    for (let now = 0; now <= giveUpAfter; now += step) {
        for (const message of messages) {
            if (times[message] === null && !stillUp(message)) {
                times[message] = now
            }
        }
        if (messages.every((message) => times[message] !== null)) break

        vi.advanceTimersByTime(step)
        await wrapper.vm.$nextTick()
    }

    return times
}

beforeEach(() => {
    currentRoute = 'dashboard'
})

afterEach(() => {
    mountedLayouts.splice(0).forEach((wrapper) => wrapper.unmount())
    vi.useRealTimers()
})

describe('la largeur du contenu', () => {
    /**
     * Un outil garde une colonne de formulaire. Étirée sur les 1 280 px du
     * conteneur, elle donne des champs et des tuiles de choix vides aux trois
     * quarts (#1802, #1817).
     */
    const conteneur = (wrapper) => wrapper.get('[data-testid="page-content"]').element.parentElement

    it('laisse la page occuper toute la largeur par défaut', () => {
        expect(conteneur(mountLayout()).className).toContain('max-w-7xl')
    })

    it.each([
        ['moyenne', 'max-w-3xl'],
        ['etroite', 'max-w-2xl'],
    ])('resserre la page à %s', (largeur, classe) => {
        const rendu = conteneur(mountLayout({ props: { largeur } })).className

        expect(rendu).toContain(classe)
        expect(rendu).not.toContain('max-w-7xl')
    })
})

describe('la marque de la barre de navigation', () => {
    /**
     * Le logo, tracé dans la page, puis le nom : le lien vers l'accueil se lit
     * « GymTracker », et l'en-tête reste entier hors ligne.
     */
    it('montre le logo et le nom de l’application, en lien vers l’accueil', () => {
        const accueil = mountLayout()
            .findAll('a[href="/resolved/dashboard"]')
            .find((lien) => lien.find('[data-testid="logo-de-l-application"]').exists())

        expect(accueil).toBeDefined()
        expect(accueil.get('[data-testid="logo-de-l-application"]').classes()).toContain('size-10')
        expect(accueil.text()).toBe('GymTracker')
    })

    /**
     * Le logo se montre seul, à la demande du propriétaire : le nom, caché,
     * nomme le lien à toute largeur.
     */
    it('garde le nom pour les lecteurs d’écran, sans le montrer', () => {
        const accueil = mountLayout()
            .findAll('a[href="/resolved/dashboard"]')
            .find((lien) => lien.find('[data-testid="logo-de-l-application"]').exists())
        const nom = accueil.get('[data-testid="logo-de-l-application"] + span')

        expect(nom.classes()).toContain('sr-only')
        expect(nom.classes().some((classe) => classe.includes('not-sr-only'))).toBe(false)
        expect(nom.text()).toBe('GymTracker')
    })
})

describe('AuthenticatedLayout — flash messages', () => {
    it('says nothing when the server flashed nothing', () => {
        expect(toasts(mountLayout())).toHaveLength(0)
    })

    it('shows the message the server flashed', () => {
        const wrapper = mountLayout({ flash: { success: 'Séance enregistrée' } })

        expect(toasts(wrapper)).toHaveLength(1)
        expect(toasts(wrapper)[0].text()).toContain('Séance enregistrée')
    })

    /**
     * A failure a screen reader is told about "politely" waits for the user to
     * stop what they are doing — which, for a save that did not happen, is too
     * late. Successes interrupt nobody.
     */
    it('interrupts for an error, and waits its turn for a success', () => {
        const wrapper = mountLayout({ flash: { success: 'Enregistré', error: 'Échec de la sauvegarde' } })

        const byMessage = Object.fromEntries(toasts(wrapper).map((t) => [messageOf(t), t]))

        expect(toasts(wrapper)).toHaveLength(2)
        expect(byMessage['Enregistré'].attributes('aria-live')).toBe('polite')
        expect(byMessage['Échec de la sauvegarde'].attributes('aria-live')).toBe('assertive')
    })

    it('lets the user dismiss it', async () => {
        const wrapper = mountLayout({ flash: { success: 'Séance enregistrée' } })

        await wrapper.get('[role="alert"] button[aria-label="Fermer le message"]').trigger('click')

        expect(toasts(wrapper)).toHaveLength(0)
    })

    it('takes the message away on its own', async () => {
        vi.useFakeTimers()
        const wrapper = mountLayout({ flash: { success: 'Séance enregistrée' } })

        expect(toasts(wrapper)).toHaveLength(1)

        vi.advanceTimersByTime(5000)
        await wrapper.vm.$nextTick()

        expect(toasts(wrapper)).toHaveLength(0)
    })

    /**
     * An error the user has to act on must outlast a confirmation they only
     * glance at — and must still leave on its own.
     *
     * The figures are asserted now, where this test used to compare the two
     * deadlines to each other and say why it could not do better: every flash
     * change re-armed every live toast, so clearing the success at 5 s pushed
     * the error's own deadline back and it stayed 13 s instead of 8. The order
     * held, the duration was an accident of what happened next. Fixed in #1387,
     * so the configured deadline is now the thing worth asserting — an error
     * that leaves after the success but at some unpredictable moment is exactly
     * the bug that used to pass here.
     */
    it('leaves an error up for its own eight seconds, whatever else is on screen', async () => {
        vi.useFakeTimers()
        const wrapper = mountLayout({ flash: { success: 'Enregistré', error: 'Échec' } })

        const dismissed = await dismissalTimes(wrapper, ['Enregistré', 'Échec'])

        expect(dismissed).toEqual({ Enregistré: 5000, Échec: 8000 })
    })

    /**
     * The dismissal timer outlived the page it belonged to: navigating away and
     * back within five seconds cleared the *new* page's flash.
     */
    it('drops its pending timer when the page goes away', () => {
        vi.useFakeTimers()
        const wrapper = mountLayout({ flash: { success: 'Séance enregistrée' } })

        unmountLayout(wrapper)
        vi.advanceTimersByTime(10000)

        expect(page.props.flash.success).toBe('Séance enregistrée')
    })
})

/*
 * Sans réseau, l'application le dit par ce toast (#2048) : une page qui
 * n'est pas disponible, une écriture sans confirmation, un réseau qui ne
 * répond pas, avec de quoi annuler la visite qui attend.
 */
describe('AuthenticatedLayout — sans réseau', () => {
    const boutonDuToast = (wrapper) => wrapper.find('[dusk="action-du-toast"]')

    it('montre ce que le filet des visites annonce, et le retire après ses huit secondes', async () => {
        vi.useFakeTimers()
        const wrapper = mountLayout()

        annoncerSansReseau(PAGE_INDISPONIBLE)
        await wrapper.vm.$nextTick()

        expect(toasts(wrapper).map(messageOf)).toEqual([PAGE_INDISPONIBLE])
        expect(toasts(wrapper)[0].attributes('aria-live')).toBe('assertive')
        expect(boutonDuToast(wrapper).exists()).toBe(false)

        expect(await dismissalTimes(wrapper, [PAGE_INDISPONIBLE])).toEqual({ [PAGE_INDISPONIBLE]: 8000 })
    })

    it('redonne ses huit secondes au même message annoncé une seconde fois', async () => {
        vi.useFakeTimers()
        const wrapper = mountLayout()

        annoncerSansReseau(PAGE_INDISPONIBLE)
        await wrapper.vm.$nextTick()
        vi.advanceTimersByTime(6000)
        annoncerSansReseau(PAGE_INDISPONIBLE)
        await wrapper.vm.$nextTick()

        // Sans quoi le second appui sur un lien mort ne laisserait le message que deux secondes.
        expect(await dismissalTimes(wrapper, [PAGE_INDISPONIBLE])).toEqual({ [PAGE_INDISPONIBLE]: 8000 })
    })

    it('garde « Le réseau ne répond pas » tant que la visite attend, et « Annuler » l’arrête', async () => {
        vi.useFakeTimers()
        const annuler = vi.fn()
        const wrapper = mountLayout()

        annoncerSansReseau(RESEAU_LENT, { annuler })
        await wrapper.vm.$nextTick()
        vi.advanceTimersByTime(30000)
        await wrapper.vm.$nextTick()

        expect(toasts(wrapper).map(messageOf)).toEqual([RESEAU_LENT])
        expect(boutonDuToast(wrapper).text()).toBe('Annuler')

        await boutonDuToast(wrapper).trigger('click')

        expect(annuler).toHaveBeenCalledTimes(1)
        expect(toasts(wrapper)).toHaveLength(0)
    })

    it('retire l’annonce de la lenteur avec sa visite, mais pas un message qui l’a remplacée', async () => {
        const wrapper = mountLayout()

        annoncerSansReseau(RESEAU_LENT, { annuler: () => {} })
        await wrapper.vm.$nextTick()
        retirerLAnnonce(RESEAU_LENT)
        await wrapper.vm.$nextTick()

        expect(toasts(wrapper)).toHaveLength(0)

        annoncerSansReseau(RESEAU_LENT, { annuler: () => {} })
        await wrapper.vm.$nextTick()
        annoncerSansReseau(PAGE_INDISPONIBLE)
        await wrapper.vm.$nextTick()

        // Le bouton appartenait à l'annonce remplacée.
        expect(boutonDuToast(wrapper).exists()).toBe(false)

        retirerLAnnonce(RESEAU_LENT)
        await wrapper.vm.$nextTick()

        expect(toasts(wrapper).map(messageOf)).toEqual([PAGE_INDISPONIBLE])
    })

    it('n’écoute plus rien une fois la page partie', async () => {
        const wrapper = mountLayout()
        unmountLayout(wrapper)

        annoncerSansReseau(PAGE_INDISPONIBLE)

        expect(page.props.flash).toEqual({})
    })
})

describe('AuthenticatedLayout — notifications', () => {
    it('counts the unread ones for a screen reader as well as for the eye', () => {
        const wrapper = mountLayout({
            props: { pageTitle: 'Accueil' },
            auth: { user: user({ unread_notifications_count: 3 }) },
        })

        expect(notificationLinks(wrapper)).toHaveLength(2)

        for (const link of notificationLinks(wrapper)) {
            expect(link.attributes('aria-label')).toBe('Notifications (3 non lues)')
            expect(link.text()).toContain('3')
        }
    })

    it('drops the badge, and the count in the label, when everything is read', () => {
        const wrapper = mountLayout({
            props: { pageTitle: 'Accueil' },
            auth: { user: user({ unread_notifications_count: 0 }) },
        })

        for (const link of notificationLinks(wrapper)) {
            expect(link.attributes('aria-label')).toBe('Notifications')
            expect(link.text()).not.toContain('0')
        }
    })
})

describe('AuthenticatedLayout — the mobile header', () => {
    it('stays away on a page that gave it neither a title nor a back link', () => {
        expect(mountLayout().find('header').exists()).toBe(false)
    })

    it('shows the page title it was given', () => {
        expect(
            mountLayout({ props: { pageTitle: 'Mesures' } })
                .get('h1')
                .text(),
        ).toBe('Mesures')
    })

    it('sends the back link to the route the page named', () => {
        const wrapper = mountLayout({ props: { showBack: true, backRoute: 'templates.index' } })

        expect(wrapper.get('[aria-label="Retour"]').attributes('href')).toBe('/resolved/templates.index')
    })

    /**
     * Le retour de l'en-tête mobile, sur les seize pages qui en ont un, dont
     * l'écran de séance, tenait en 40 px (`h-10 w-10`) : moins que les 44 de
     * la charte, que la cloche voisine atteint déjà (#2116).
     */
    it('offre au pouce un retour de 44 px, comme la cloche voisine', () => {
        const wrapper = mountLayout({ props: { pageTitle: 'Séance', showBack: true } })

        expect(cibleDuRendu(wrapper.get('[aria-label="Retour"]').element)).toBeGreaterThanOrEqual(CIBLE)
        for (const cloche of notificationLinks(wrapper)) {
            expect(cibleDuRendu(cloche.element)).toBeGreaterThanOrEqual(CIBLE)
        }
    })

    it('falls back to the browser’s own history when no route was named', () => {
        const wrapper = mountLayout({ props: { showBack: true } })

        expect(wrapper.get('[aria-label="Retour"]').attributes('href')).toBe('javascript:history.back()')
    })

    it('makes room for the page’s own actions', () => {
        const wrapper = mountLayout({
            props: { pageTitle: 'Habitudes' },
            slots: { 'header-actions': '<button dusk="add-habit">+</button>' },
        })

        expect(wrapper.find('[dusk="add-habit"]').exists()).toBe(true)
    })

    it('leaves out the desktop heading band when the page supplies no header', () => {
        expect(mountLayout({ props: { pageTitle: 'Mesures' } }).findAll('header')).toHaveLength(1)
    })

    it('renders the desktop heading band when the page supplies one', () => {
        const wrapper = mountLayout({ props: { pageTitle: 'Mesures' }, slots: { header: '<h2>Mesures</h2>' } })

        expect(wrapper.findAll('header')).toHaveLength(2)
    })
})

/**
 * Le seul `<h1>` du layout était celui de l'en-tête mobile, caché à partir de
 * 1 024 px : sur bureau, une page sans titre dans son en-tête de page n'avait
 * aucun titre de niveau 1 (#2120). Le layout tire de `page-title` un `<h1>`
 * `sr-only` de bureau, sauf quand la page porte le sien : dans `#header`, ou
 * dans son contenu, ce qu'elle dit par `titre-dans-la-page`.
 */
describe('AuthenticatedLayout — un seul titre de niveau 1 à toute largeur', () => {
    it('pose pour les lecteurs d’écran le titre de bureau d’une page qui n’en a pas', () => {
        const wrapper = mountLayout({ props: { pageTitle: 'Accueil' } })

        expect(titresExposes(wrapper.element)).toEqual({ telephone: ['Accueil'], bureau: ['Accueil'] })
        expect(wrapper.findAll('h1').at(-1).classes()).toContain('sr-only')
    })

    it('laisse l’en-tête de page porter le titre de bureau', () => {
        const wrapper = mountLayout({ props: { pageTitle: 'Mes Séances' }, slots: { header: '<h1>Mes Séances</h1>' } })

        expect(titresExposes(wrapper.element)).toEqual({ telephone: ['Mes Séances'], bureau: ['Mes Séances'] })
    })

    it('s’efface devant le titre de bureau que la page pose dans son contenu', () => {
        const wrapper = mountLayout({
            props: { pageTitle: 'Mon Évolution', titreDansLaPage: true },
            slots: { default: '<div class="bureau:block hidden"><h1>Mon Évolution</h1></div>' },
        })

        expect(titresExposes(wrapper.element)).toEqual({ telephone: ['Mon Évolution'], bureau: ['Mon Évolution'] })
    })

    it('ne pose aucun titre sans page-title, la page portant le sien', () => {
        expect(titresExposes(mountLayout({ props: { showBack: true } }).element)).toEqual({ telephone: [], bureau: [] })
    })
})

describe('AuthenticatedLayout — the session in progress', () => {
    const active = { id: 7, name: 'Push A' }

    it('offers the way back into a session left running', () => {
        const wrapper = mountLayout({ auth: { user: user({ active_workout: active }) } })
        const banner = wrapper.findComponent({ name: 'ActiveWorkoutBanner' })

        expect(banner.exists()).toBe(true)
        expect(banner.props('workout')).toEqual(active)
    })

    /** On the session's own page the banner links to where the user already is. */
    it('stays quiet on the session’s own page', () => {
        const wrapper = mountLayout({ auth: { user: user({ active_workout: active }) }, onPage: 'workouts.show' })

        expect(wrapper.findComponent({ name: 'ActiveWorkoutBanner' }).exists()).toBe(false)
    })

    /**
     * Une séance démarrée sans réseau s'ouvre à l'adresse de sa clé (#2049),
     * que la route `workouts.show` ne nomme pas : la page se reconnaît à son
     * composant, et le bandeau n'y mène pas là où l'on est déjà.
     */
    it('stays quiet on a session started without a network, at the address of its key', () => {
        const wrapper = mountLayout({
            auth: { user: user({ active_workout: active }) },
            onPage: null,
            url: '/workouts/en-attente/cle-de-la-seance',
            component: 'Workouts/Show',
        })

        expect(wrapper.findComponent({ name: 'ActiveWorkoutBanner' }).exists()).toBe(false)
    })

    it('stays quiet when no session is running', () => {
        const wrapper = mountLayout()

        expect(wrapper.findComponent({ name: 'ActiveWorkoutBanner' }).exists()).toBe(false)
    })
})

/*
 * Une page servie depuis sa copie hors ligne le dit sur toute page du compte
 * (#2048, partie E). Ce que dit le bandeau se teste dans
 * `bandeauHorsLigne.test.js`, son « Réessayer » avec le vrai client
 * d'Inertia dans `bandeauHorsLigneAvecInertia.test.js`.
 */
describe('AuthenticatedLayout — une copie hors ligne', () => {
    it('montre le bandeau de la copie en tête du contenu, avant la page', async () => {
        const wrapper = mountLayout()

        expect(wrapper.find('[dusk="bandeau-hors-ligne"]').exists()).toBe(false)

        page.props.horsLigne = { copieDu: new Date(2026, 9, 12, 18, 4).getTime(), raison: 'reseau', datesDesProps: {} }
        await wrapper.vm.$nextTick()

        const main = wrapper.get('main')
        const bandeau = main.get('[dusk="bandeau-hors-ligne"]')
        const contenu = main.get('[data-testid="page-content"]')

        expect(bandeau.text()).toContain('Hors ligne · copie du lun. 12 oct. 2026 à 18:04')
        expect(bandeau.element.compareDocumentPosition(contenu.element) & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy()
    })
})

describe('AuthenticatedLayout — l’abonnement push', () => {
    beforeEach(() => {
        rapprocherLAbonnementPush.mockClear()
    })

    /*
     * Le profil était le seul endroit où l'abonnement du navigateur rejoignait
     * le serveur. Sur iPhone, où le worker ne reçoit pas
     * `pushsubscriptionchange`, un abonnement révoqué ou remplacé ne se
     * réparait donc qu'en rouvrant le profil (#1847).
     */
    it('rapproche l’abonnement du compte connecté, quelle que soit la page', () => {
        mountLayout({ auth: { user: user({ id: 42 }) }, onPage: 'stats.index' })

        expect(rapprocherLAbonnementPush).toHaveBeenCalledWith(42)
    })
})

/*
 * Une écriture mise en file avant que la file ne note son compte ne part
 * jamais, pour ne pas partir sous un autre compte : le chargement l'efface. La
 * version précédente la rejouait, et la personne qui rouvrait l'application
 * après la mise à jour perdait ses dernières séries sans un mot (#1964).
 */
describe('AuthenticatedLayout — les écritures hors ligne effacées au chargement', () => {
    /** Une écriture telle que la version précédente la mettait en file : sans compte. */
    const ecritureSansCompte = (id) => ({
        method: 'patch',
        url: `/api/v1/sets/${id}`,
        data: { is_completed: true },
        id: `ancienne-${id}`,
        timestamp: horodatageRecent(),
    })

    beforeEach(() => {
        localStorage.clear()
    })

    afterEach(() => {
        retirerLesEcouteursDuService()
        poserLaPage(null)
        localStorage.clear()
    })

    it('dit une fois combien le chargement en a effacé, sans rien de leur contenu', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([ecritureSansCompte(1), ecritureSansCompte(2)]))
        localStorage.setItem('offline_sync_failed', JSON.stringify([{ ...ecritureSansCompte(3), status: 422 }]))

        const service = await chargerSyncService({ compte: 42 })
        await service.pending
        const wrapper = mountLayout({ auth: { user: user({ id: 42 }) } })
        await wrapper.vm.$nextTick()

        expect(service.queue).toEqual([])
        expect(toasts(wrapper)).toHaveLength(1)
        expect(toasts(wrapper)[0].attributes('aria-live')).toBe('assertive')
        expect(messageOf(toasts(wrapper)[0])).toBe(
            "3 modifications faites hors ligne avant la mise à jour de l'application n'ont pas pu être enregistrées et ont été effacées de cet appareil. Vérifie ta dernière séance.",
        )
        expect(messageOf(toasts(wrapper)[0])).not.toContain('/api/v1/sets')

        unmountLayout(wrapper)
        const ecranSuivant = mountLayout({ auth: { user: user({ id: 42 }) } })
        await ecranSuivant.vm.$nextTick()

        expect(toasts(ecranSuivant)).toHaveLength(0)
    })

    it('ne dit rien quand le chargement n’a rien effacé', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([{ ...ecritureSansCompte(1), compte: '42' }]))

        const service = await chargerSyncService({ compte: null })
        await service.pending
        const wrapper = mountLayout()
        await wrapper.vm.$nextTick()

        expect(toasts(wrapper)).toHaveLength(0)
    })

    it('ne remplace pas un message déjà affiché : l’avis attend l’écran suivant', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([ecritureSansCompte(1)]))
        await chargerSyncService({ compte: 42 })

        const avecUneErreur = mountLayout({ flash: { error: 'Séance introuvable' } })
        await avecUneErreur.vm.$nextTick()

        expect(toasts(avecUneErreur).map(messageOf)).toEqual(['Séance introuvable'])

        unmountLayout(avecUneErreur)
        const suivant = mountLayout()
        await suivant.vm.$nextTick()

        expect(toasts(suivant).map(messageOf)).toEqual([
            "Une modification faite hors ligne avant la mise à jour de l'application n'a pas pu être enregistrée et a été effacée de cet appareil. Vérifie ta dernière séance.",
        ])
    })
})

/*
 * Une écriture qui attend sur l'appareil depuis plus de trente jours est
 * abandonnée et effacée (#2050). La personne le sait, au premier écran de son
 * compte, avec le nombre et la date : jamais une perte sans un mot (#1964).
 */
describe('AuthenticatedLayout — les écritures abandonnées après trente jours', () => {
    const MAINTENANT = new Date('2026-10-16T12:00:00.000Z')
    const JOUR = 24 * 60 * 60 * 1000

    const ecriture = (id, compte, ilYA) => ({
        method: 'patch',
        url: `/api/v1/sets/${id}`,
        data: { weight: 80 },
        id: `q${id}`,
        timestamp: new Date(MAINTENANT.getTime() - ilYA).toISOString(),
        compte,
    })

    beforeEach(() => {
        localStorage.clear()
        vi.useFakeTimers({ toFake: ['Date'] })
        vi.setSystemTime(MAINTENANT)
    })

    afterEach(() => {
        vi.useRealTimers()
        retirerLesEcouteursDuService()
        poserLaPage(null)
        localStorage.clear()
    })

    it('dit au compte combien ont été abandonnées, et depuis quand, une seule fois', async () => {
        localStorage.setItem(
            'offline_sync_queue',
            JSON.stringify([ecriture(1, '42', 31 * JOUR), ecriture(2, '42', 40 * JOUR), ecriture(3, '42', JOUR)]),
        )

        const service = await chargerSyncService({ compte: 42 })
        const wrapper = mountLayout({ auth: { user: user({ id: 42 }) } })
        await wrapper.vm.$nextTick()

        expect(service.queue.map((entree) => entree.id)).toEqual(['q3'])
        expect(toasts(wrapper).map(messageOf)).toEqual([
            "2 modifications faites hors ligne depuis le 06/09/2026 n'ont pas pu être enregistrées : gardées 30 jours sur cet appareil sans pouvoir partir, elles ont été abandonnées et effacées. Vérifie tes séances.",
        ])
        expect(messageOf(toasts(wrapper)[0])).not.toContain('80')

        unmountLayout(wrapper)
        const ecranSuivant = mountLayout({ auth: { user: user({ id: 42 }) } })
        await ecranSuivant.vm.$nextTick()

        expect(toasts(ecranSuivant)).toHaveLength(0)
    })

    it('le dit sur l’écran déjà ouvert quand l’application reprise après des semaines les abandonne', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([ecriture(1, '42', 20 * JOUR)]))
        const service = await chargerSyncService({ compte: 42 })
        const wrapper = mountLayout({ auth: { user: user({ id: 42 }) } })
        await wrapper.vm.$nextTick()

        expect(toasts(wrapper)).toHaveLength(0)

        vi.setSystemTime(new Date(MAINTENANT.getTime() + 11 * JOUR))
        await service.processQueue()
        await wrapper.vm.$nextTick()

        expect(service.queue).toEqual([])
        expect(toasts(wrapper).map(messageOf)).toEqual([
            "Une modification faite hors ligne le 26/09/2026 n'a pas pu être enregistrée : gardée 30 jours sur cet appareil sans pouvoir partir, elle a été abandonnée et effacée. Vérifie tes séances.",
        ])
    })

    it('garde l’avis des écritures d’un autre compte pour ce compte, sans le montrer à celui qui est connecté', async () => {
        localStorage.setItem(
            'offline_sync_queue',
            JSON.stringify([ecriture(1, '7', 31 * JOUR), ecriture(2, '42', 2 * JOUR)]),
        )

        const service = await chargerSyncService({ compte: 42 })
        const sousLeCompte42 = mountLayout({ auth: { user: user({ id: 42 }) } })
        await sousLeCompte42.vm.$nextTick()

        expect(service.queue.map((entree) => entree.compte)).toEqual(['42'])
        expect(toasts(sousLeCompte42)).toHaveLength(0)

        unmountLayout(sousLeCompte42)
        const sousLeCompte7 = mountLayout({ auth: { user: user({ id: 7 }) } })
        await sousLeCompte7.vm.$nextTick()

        expect(toasts(sousLeCompte7).map(messageOf)).toEqual([
            expect.stringMatching(/^Une modification faite hors ligne le 15\/09\/2026 n'a pas pu être enregistrée/),
        ])
    })

    it('ne remplace pas un message déjà affiché : l’avis attend l’écran suivant', async () => {
        localStorage.setItem('offline_sync_queue', JSON.stringify([ecriture(1, '42', 31 * JOUR)]))
        await chargerSyncService({ compte: 42 })

        const avecUneErreur = mountLayout({ flash: { error: 'Séance introuvable' }, auth: { user: user({ id: 42 }) } })
        await avecUneErreur.vm.$nextTick()

        expect(toasts(avecUneErreur).map(messageOf)).toEqual(['Séance introuvable'])

        unmountLayout(avecUneErreur)
        const suivant = mountLayout({ auth: { user: user({ id: 42 }) } })
        await suivant.vm.$nextTick()

        expect(toasts(suivant).map(messageOf)).toEqual([
            expect.stringMatching(/^Une modification faite hors ligne le 15\/09\/2026/),
        ])
    })
})

/*
 * La personne se déconnecte dans un autre onglet, ou y supprime son compte
 * (#2050) : la session, commune aux onglets, n'existe plus. Celui-ci, resté sur
 * une page du compte, ne disait rien, et ce qu'on y faisait ensuite sans réseau
 * n'était ni envoyé ni gardé.
 */
describe('AuthenticatedLayout — la session finie dans un autre onglet', () => {
    const SESSION_FINIE =
        "La session de ce compte a pris fin dans un autre onglet : ce qui n'était pas encore envoyé a été effacé de cet appareil. Reconnecte-toi pour continuer, sinon tes modifications ne seront pas enregistrées."

    /** L'avis qu'un autre onglet écrit quand un compte quitte l'appareil. */
    const avisDUnAutreOnglet = (compte) =>
        window.dispatchEvent(
            new StorageEvent('storage', {
                key: 'gym-tracker:compte-oublie-de-la-file',
                newValue: JSON.stringify({ compte }),
            }),
        )

    afterEach(() => {
        retirerLesEcouteursDuService()
        poserLaPage(null)
        localStorage.clear()
    })

    it('le dit par le toast d’erreur, à la place de ce qu’il montrait, et seulement pour son compte', async () => {
        await chargerSyncService({ compte: 42 })
        const wrapper = mountLayout({ flash: { error: 'Séance introuvable' }, auth: { user: user({ id: 42 }) } })

        avisDUnAutreOnglet('7')
        await wrapper.vm.$nextTick()

        expect(toasts(wrapper).map(messageOf)).toEqual(['Séance introuvable'])

        avisDUnAutreOnglet('42')
        await wrapper.vm.$nextTick()

        expect(toasts(wrapper).map(messageOf)).toEqual([SESSION_FINIE])
    })

    it('n’écoute plus une fois la page partie', async () => {
        await chargerSyncService({ compte: 42 })
        const wrapper = mountLayout({ auth: { user: user({ id: 42 }) } })
        unmountLayout(wrapper)

        avisDUnAutreOnglet('42')

        expect(page.props.flash.error).toBeUndefined()
    })
})

describe('AuthenticatedLayout — la déconnexion', () => {
    beforeEach(() => {
        seDeconnecter.mockClear()
    })

    /*
     * L'entrée était un lien Inertia vers `logout` : la déconnexion partait
     * sans détacher l'appareil, qui gardait les notifications du compte parti
     * (#1926).
     */
    it('passe par seDeconnecter, qui détache l’appareil avant de partir', async () => {
        const wrapper = mountLayout()
        const entree = wrapper.findAll('a').find((lien) => lien.text().includes('Déconnexion'))

        expect(entree.attributes('href')).toBeUndefined()

        await entree.trigger('click')

        expect(seDeconnecter).toHaveBeenCalledTimes(1)
    })
})

describe('AuthenticatedLayout — the frame around the page', () => {
    /*
     * Looked for inside the main landmark, by a marker of its own, rather than
     * by its words anywhere on the frame: the skip link at the top already
     * reads "Aller au contenu principal", so a `toContain('contenu')` on the
     * whole layout passes with the default slot deleted outright.
     */
    it('renders the page inside it', () => {
        const wrapper = mountLayout()

        expect(wrapper.get('#main-content').find('[data-testid="page-content"]').exists()).toBe(true)
    })

    /** A skip link pointing at an id nothing carries skips to nowhere. */
    it('gives the skip link something to skip to', () => {
        const wrapper = mountLayout()
        const target = wrapper.get('a[href^="#"]').attributes('href').substring(1)

        expect(wrapper.find(`#${target}`).exists()).toBe(true)
    })

    /*
     * Le hub de profil se rejoint depuis le menu.
     *
     * « Profil » pointait sur `profile.edit`, la page de modification.
     * `profile.index`, qui liste toutes les sections, n'etait atteignable sur
     * ordinateur qu'en tapant l'adresse — la barre du bas y menait deja sur
     * mobile, mais elle n'existe pas ici (#1314).
     */
    it('mène au hub de profil, et garde les réglages à un clic', () => {
        const wrapper = mountLayout()
        const parHref = wrapper.findAll('a').map((a) => [a.text(), a.attributes('href')])

        expect(parHref).toContainEqual(['person Profil', '/resolved/profile.index'])
        expect(parHref).toContainEqual(['settings Paramètres', '/resolved/profile.edit'])
    })

    it('lights the desktop tab of the section being viewed', () => {
        const wrapper = mountLayout({ onPage: 'workouts.show' })
        const byLabel = Object.fromEntries(wrapper.findAll('a[data-active]').map((a) => [a.text(), a]))

        expect(byLabel['Séances'].attributes('data-active')).toBe('true')
        expect(byLabel['Accueil'].attributes('data-active')).toBe('false')
    })
})
