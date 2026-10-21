import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const routerPost = vi.fn()
const triggerHaptic = vi.fn()

/** La page d'Inertia : rendue par le serveur, ou servie depuis sa copie hors ligne (#2048). */
const pageDInertia = vi.hoisted(() => ({ props: {}, component: 'Dashboard' }))

/**
 * Le démarrage d'une séance, commun aux boutons qui la démarrent (#2049) : ce
 * qu'il fait, avec ou sans réseau, se joue avec le vrai client d'Inertia
 * (`tests/js/pages/seanceDemarreeSansReseauAvecInertia.test.js`). Ici, que la
 * barre l'appelle, et ce qu'elle montre pendant.
 */
const demarrage = vi.hoisted(() => ({ demarrer: null, enCours: null }))

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: (...args) => routerPost(...args) },
    usePage: () => pageDInertia,
}))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: (...args) => triggerHaptic(...args) }))
vi.mock('@/composables/useDemarrageDUneSeance', () => ({
    useDemarrageDUneSeance: () => ({ demarrer: demarrage.demarrer, enCours: demarrage.enCours }),
}))

import { ref } from 'vue'
import BottomNav from '@/Components/Navigation/BottomNav.vue'

/** Ziggy, reduced to the two forms the bar uses: route(name) and route().current(). */
let currentRoute = 'dashboard'
const route = (name) => {
    if (name === undefined) {
        return { current: (pattern) => (pattern === undefined ? currentRoute : currentRoute === pattern) }
    }

    return `/resolved/${name}`
}

// The bar calls route() from <script setup> as well as from the template, so it
// has to be the global Ziggy installs, not only an injected mock.
globalThis.route = route

/** Le composant que montre chaque page : la séance est `Workouts/Show`, à son adresse ou à celle de sa clé. */
const COMPOSANTS = { 'workouts.show': 'Workouts/Show', 'workouts.en-attente': 'Workouts/Show' }

const mountNav = (onPage = 'dashboard') => {
    currentRoute = onPage
    pageDInertia.component = COMPOSANTS[onPage] ?? 'Dashboard'

    return mount(BottomNav, { global: { directives: { press: {} }, mocks: { route } } })
}

const item = (wrapper, prefix) => wrapper.get(`[dusk="nav-${prefix}"]`)
const fab = (wrapper) => wrapper.get('button')
const activeLabels = (wrapper) =>
    wrapper
        .findAll('a[aria-current="page"]')
        .map((link) => link.findAll('span').at(-1).text().trim())
        .sort()

beforeEach(() => {
    routerPost.mockReset()
    triggerHaptic.mockReset()
    pageDInertia.props = {}
    demarrage.enCours = ref(false)
    demarrage.demarrer = vi.fn(() => {
        demarrage.enCours.value = true
    })
})

describe('BottomNav — which tab is lit', () => {
    it('lights the tab of the page you are on, and only that one', () => {
        expect(activeLabels(mountNav('dashboard'))).toEqual(['Accueil'])
    })

    /**
     * A workout page has no tab of its own. Leaving every tab dark there told
     * the user they were nowhere; the section the page belongs to stays lit.
     */
    it('lights the section a sub-page belongs to', () => {
        const wrapper = mountNav('workouts.show')

        expect(activeLabels(wrapper)).toEqual(['Séances'])
        expect(item(wrapper, 'workouts').attributes('aria-current')).toBe('page')
    })

    it('lights Plus for the pages that live behind it', () => {
        expect(activeLabels(mountNav('profile.edit'))).toEqual(['Plus'])
    })

    it('leaves the untouched tabs without aria-current at all', () => {
        expect(item(mountNav('dashboard'), 'stats').attributes('aria-current')).toBeUndefined()
    })

    /** Ziggy answers null on a URL it cannot name; startsWith on null throws. */
    it('survives a page the router cannot name', () => {
        currentRoute = null

        const wrapper = mount(BottomNav, { global: { directives: { press: {} }, mocks: { route } } })

        expect(activeLabels(wrapper)).toEqual([])
    })

    it('points each tab at its own route', () => {
        const wrapper = mountNav('dashboard')

        expect(item(wrapper, 'dashboard').attributes('href')).toBe('/resolved/dashboard')
        expect(item(wrapper, 'stats').attributes('href')).toBe('/resolved/stats.index')
        expect(item(wrapper, 'workouts').attributes('href')).toBe('/resolved/workouts.index')
        expect(item(wrapper, 'profile').attributes('href')).toBe('/resolved/profile.index')
    })
})

describe('BottomNav — the central button', () => {
    it('offers to start a session everywhere else', () => {
        expect(fab(mountNav('dashboard')).attributes('aria-label')).toBe('Séance')
    })

    it('offers to add an exercise while a session is open', () => {
        expect(fab(mountNav('workouts.show')).attributes('aria-label')).toBe('Exercice')
    })

    it('starts a session from anywhere else', async () => {
        const wrapper = mountNav('stats.index')
        const opened = vi.fn()
        window.addEventListener('open-add-exercise', opened)

        await fab(wrapper).trigger('click')

        // Le démarrage commun, par la file hors ligne (#2049), et plus une visite d'Inertia.
        expect(demarrage.demarrer).toHaveBeenCalledTimes(1)
        expect(routerPost).not.toHaveBeenCalled()
        expect(opened).not.toHaveBeenCalled()

        window.removeEventListener('open-add-exercise', opened)
    })

    /*
     * Sans réseau, l'appui ne faisait rien, puis disait qu'il fallait le réseau
     * (#2048). La séance démarre désormais sans lui (#2049) : la barre ne dit
     * plus rien elle-même, et aucune visite ne part qui le dirait.
     */
    it('ne dit plus qu’il faut le réseau pour démarrer une séance : la séance démarre par la file', async () => {
        const wrapper = mountNav('stats.index')

        await fab(wrapper).trigger('click')

        expect(routerPost).not.toHaveBeenCalled()
        expect(demarrage.demarrer).toHaveBeenCalledTimes(1)
    })

    it('ne crée pas deux séances quand on appuie deux fois', async () => {
        /*
         * Le bouton est au centre de la barre, sous le pouce : c'est le plus
         * facile à double-cliquer de toute l'application, et c'était le seul des
         * quatre chemins vers « démarrer une séance » sans aucun verrou. Deux
         * appuis rapprochés créaient deux séances, et l'écran s'ouvrait sur la
         * mauvaise. Le verrou est désormais celui du démarrage, commun à tous.
         *
         * Le démarrage est ici remplacé : ce cas prouve seulement que la barre
         * se désactive tant que `enCours` est vrai. Le verrou lui-même, qui
         * tient pour deux boutons appelant chacun le composable, se prouve
         * avec le vrai composable (`useDemarrageDUneSeance.test.js`, « le
         * verrou de l'onglet »).
         */
        const wrapper = mountNav('stats.index')

        await fab(wrapper).trigger('click')
        await fab(wrapper).trigger('click')

        expect(demarrage.demarrer).toHaveBeenCalledTimes(1)
        expect(fab(wrapper).attributes('disabled')).toBeDefined()
        expect(fab(wrapper).attributes('aria-busy')).toBe('true')
    })

    it('rend le bouton quand le démarrage a fini, même en échec', async () => {
        const wrapper = mountNav('stats.index')

        await fab(wrapper).trigger('click')
        demarrage.enCours.value = false
        await wrapper.vm.$nextTick()

        expect(fab(wrapper).attributes('disabled')).toBeUndefined()
    })

    /** Starting a second session from inside the first is the bug this guards. */
    it('asks the open session for an exercise instead of starting another', async () => {
        const wrapper = mountNav('workouts.show')
        const opened = vi.fn()
        window.addEventListener('open-add-exercise', opened)

        await fab(wrapper).trigger('click')

        expect(opened).toHaveBeenCalledTimes(1)
        expect(demarrage.demarrer).not.toHaveBeenCalled()

        window.removeEventListener('open-add-exercise', opened)
    })

    /*
     * Une séance démarrée sans réseau s'ouvre à l'adresse de sa clé (#2049),
     * que `route().current('workouts.show')` ne nomme pas : la barre y
     * démarrait une seconde séance. Elle reconnaît la séance à sa page.
     */
    it('ajoute un exercice sur une séance démarrée sans réseau, à l’adresse de sa clé', async () => {
        const wrapper = mountNav('workouts.en-attente')
        const opened = vi.fn()
        window.addEventListener('open-add-exercise', opened)

        expect(fab(wrapper).attributes('aria-label')).toBe('Exercice')

        await fab(wrapper).trigger('click')

        expect(opened).toHaveBeenCalledTimes(1)
        expect(demarrage.demarrer).not.toHaveBeenCalled()

        window.removeEventListener('open-add-exercise', opened)
    })

    it('answers the tap with a haptic', async () => {
        await fab(mountNav('dashboard')).trigger('click')

        expect(triggerHaptic).toHaveBeenCalledWith('toggle')
    })
})

/*
 * Sur une copie hors ligne, rien ne part vers le serveur par une visite (#2048,
 * partie E). Démarrer une séance passe par la file depuis #2049, sans rien
 * tirer de la copie : le bouton reste ouvert. Sur la séance, il ajoute un
 * exercice, que la file hors ligne garde.
 */
describe('BottomNav — sur une copie hors ligne', () => {
    beforeEach(() => {
        pageDInertia.props = { horsLigne: { copieDu: Date.now(), raison: 'reseau', datesDesProps: {} } }
    })

    it('démarre une séance, sans raison de désactivation : la séance passe par la file', async () => {
        const wrapper = mountNav('dashboard')

        expect(fab(wrapper).attributes('disabled')).toBeUndefined()
        expect(fab(wrapper).attributes('aria-describedby')).toBeUndefined()

        await fab(wrapper).trigger('click')

        expect(demarrage.demarrer).toHaveBeenCalledTimes(1)
        expect(routerPost).not.toHaveBeenCalled()
    })

    it('sur la séance, ajoute toujours un exercice', async () => {
        const wrapper = mountNav('workouts.show')
        const opened = vi.fn()
        window.addEventListener('open-add-exercise', opened)

        expect(fab(wrapper).attributes('disabled')).toBeUndefined()
        expect(fab(wrapper).attributes('aria-describedby')).toBeUndefined()

        await fab(wrapper).trigger('click')

        expect(opened).toHaveBeenCalledTimes(1)
        window.removeEventListener('open-add-exercise', opened)
    })

    it('reste le démarrage d’une séance sur une page que le serveur a rendue', () => {
        pageDInertia.props = {}

        expect(fab(mountNav('dashboard')).attributes('disabled')).toBeUndefined()
    })
})
