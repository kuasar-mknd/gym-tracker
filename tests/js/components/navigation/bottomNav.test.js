import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'

const routerPost = vi.fn()
const triggerHaptic = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { post: (...args) => routerPost(...args) },
}))
vi.mock('@/composables/useHaptics', () => ({ triggerHaptic: (...args) => triggerHaptic(...args) }))

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

const mountNav = (onPage = 'dashboard') => {
    currentRoute = onPage

    return mount(BottomNav, { global: { directives: { press: {} }, mocks: { route } } })
}

const item = (wrapper, prefix) => wrapper.get(`[dusk="nav-${prefix}"]`)
const fab = (wrapper) => wrapper.get('button')
const activeLabels = (wrapper) =>
    wrapper
        .findAll('a[aria-current="page"]')
        .map((link) => link.attributes('aria-label'))
        .sort()

beforeEach(() => {
    routerPost.mockReset()
    triggerHaptic.mockReset()
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

        // On lit le premier argument plutôt que la signature entière : le
        // bouton passe désormais des options à Inertia pour relâcher son verrou,
        // et ce test-là parle de la DESTINATION, pas de la façon d'y aller.
        expect(routerPost).toHaveBeenCalledTimes(1)
        expect(routerPost.mock.calls[0][0]).toBe('/resolved/workouts.store')
        expect(opened).not.toHaveBeenCalled()

        window.removeEventListener('open-add-exercise', opened)
    })

    it('ne crée pas deux séances quand on appuie deux fois', async () => {
        /*
         * Le bouton est au centre de la barre, sous le pouce : c'est le plus
         * facile à double-cliquer de toute l'application, et c'était le seul des
         * quatre chemins vers « démarrer une séance » sans aucun verrou. Deux
         * appuis rapprochés créaient deux séances, et l'écran s'ouvrait sur la
         * mauvaise.
         *
         * Inertia n'appelle `onFinish` qu'à la fin de la requête ; tant qu'elle
         * dure, le second appui doit être sans effet.
         */
        const wrapper = mountNav('stats.index')

        await fab(wrapper).trigger('click')
        await fab(wrapper).trigger('click')

        expect(routerPost).toHaveBeenCalledTimes(1)
        expect(fab(wrapper).attributes('disabled')).toBeDefined()
        expect(fab(wrapper).attributes('aria-busy')).toBe('true')
    })

    it('rend le bouton même quand la création échoue', async () => {
        /*
         * `onFinish` et non `onSuccess` : un refus du serveur doit relâcher le
         * verrou aussi, sinon une erreur réseau condamne le bouton jusqu'au
         * rechargement de la page.
         */
        const wrapper = mountNav('stats.index')

        await fab(wrapper).trigger('click')

        const options = routerPost.mock.calls[0][2]

        expect(options).toHaveProperty('onFinish')

        options.onFinish()
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
        expect(routerPost).not.toHaveBeenCalled()

        window.removeEventListener('open-add-exercise', opened)
    })

    it('answers the tap with a haptic', async () => {
        await fab(mountNav('dashboard')).trigger('click')

        expect(triggerHaptic).toHaveBeenCalledWith('toggle')
    })
})
