import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@inertiajs/vue3', () => ({
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    usePage: () => ({ props: {}, component: 'Dashboard' }),
}))

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
const libelle = (link) => link.findAll('span').at(-1).text().trim()
const labels = (wrapper) => wrapper.findAll('a').map(libelle)
const activeLabels = (wrapper) => wrapper.findAll('a[aria-current="page"]').map(libelle).sort()

describe('BottomNav — les quatre onglets', () => {
    /** Pas de « Plus », pas de « Stats » : les noms que la maquette de navigation fige. */
    it('shows Accueil, Séances, Progrès and Profil, in that order', () => {
        expect(labels(mountNav())).toEqual(['Accueil', 'Séances', 'Progrès', 'Profil'])
    })

    it('points each tab at its own route, under the Dusk names the journeys click', () => {
        const wrapper = mountNav('dashboard')

        expect(item(wrapper, 'dashboard').attributes('href')).toBe('/resolved/dashboard')
        expect(item(wrapper, 'workouts').attributes('href')).toBe('/resolved/workouts.index')
        expect(item(wrapper, 'stats').attributes('href')).toBe('/resolved/stats.index')
        expect(item(wrapper, 'profile').attributes('href')).toBe('/resolved/profile.index')
    })

    /**
     * Le bouton central est parti : la séance en cours est dans l'accessoire du
     * layout, « Démarrer » sur l'Accueil et sur Séances, « Exercice » dans la
     * barre d'outils de la séance. La barre ne porte que des liens.
     */
    it('has no button at all, on any page, and no central button class', () => {
        for (const page of ['dashboard', 'workouts.show', 'stats.index', 'profile.index']) {
            const wrapper = mountNav(page)

            expect(wrapper.findAll('button')).toHaveLength(0)
            expect(wrapper.find('.glass-nav-fab').exists()).toBe(false)
        }
    })

    it('does not ask the open session for an exercise when a tab is pressed', async () => {
        const wrapper = mountNav('workouts.show')
        const opened = vi.fn()
        window.addEventListener('open-add-exercise', opened)

        await item(wrapper, 'workouts').trigger('click')

        expect(opened).not.toHaveBeenCalled()

        window.removeEventListener('open-add-exercise', opened)
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

    it('lights Profil for the pages that live behind it', () => {
        expect(activeLabels(mountNav('profile.edit'))).toEqual(['Profil'])
    })

    it.each([
        ['calendar.index', 'Séances'],
        ['templates.index', 'Séances'],
        ['goals.edit', 'Progrès'],
        ['achievements.index', 'Progrès'],
        ['body-parts.show', 'Progrès'],
        ['exercises.show', 'Progrès'],
        ['habits.index', 'Profil'],
        ['tools.water.index', 'Profil'],
    ])('lights the tab that %s belongs to', (page, label) => {
        expect(activeLabels(mountNav(page))).toEqual([label])
    })

    /** La cloche mène aux notifications : aucun onglet ne s'y allume, même si son retour mène à l'Accueil. */
    it('lights no tab on the notifications', () => {
        expect(activeLabels(mountNav('notifications.index'))).toEqual([])
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
})
