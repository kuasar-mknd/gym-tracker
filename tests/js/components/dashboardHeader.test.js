import { describe, it, expect, beforeAll } from 'vitest'
import { mount } from '@vue/test-utils'

import DashboardHeader from '@/Components/Dashboard/DashboardHeader.vue'

beforeAll(() => {
    globalThis.route = (name) => `/${name}`
})

const mountHeader = (user = { name: 'Samuel Dulex', current_streak: 4 }) =>
    mount(DashboardHeader, {
        props: { user },
        global: {
            // The template calls route() directly, so it has to resolve through
            // the component instance, not just on globalThis.
            mocks: { route: globalThis.route, $page: { props: { auth: { user: { unread_notifications_count: 0 } } } } },
            directives: { press: {} },
            stubs: {
                Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
            },
        },
    })

/** L'en-tête de la structure B : la salutation, la série et la cloche, sans le dernier poids. */
describe('DashboardHeader', () => {
    it('salue par « Bon retour » et le prénom', () => {
        const entete = mountHeader()

        expect(entete.get('[dusk="dashboard-welcome"]').text()).toBe('Bon retour')
        expect(entete.text()).toContain('Samuel')
        expect(entete.text()).not.toContain('Dulex')
    })

    it('ne montre plus le dernier poids, ni de quoi dire qu’il est indisponible', () => {
        const entete = mountHeader()

        expect(entete.find('[dusk="dashboard-latest-weight"]').exists()).toBe(false)
        expect(entete.find('[dusk="dashboard-latest-weight-indisponible"]').exists()).toBe(false)
        expect(entete.text()).not.toContain('kg')
    })
})

/**
 * The streak badge carried cursor-pointer, a lift on hover and active:scale-95
 * with no click handler behind any of it: every affordance of a button, and
 * nothing there. It now leads to the stats page.
 */
describe('DashboardHeader streak badge', () => {
    const streakOf = (days) => mountHeader({ name: 'Samuel', current_streak: days }).find('[dusk="dashboard-streak"]')

    it('goes somewhere, rather than only looking like it does', () => {
        expect(streakOf(4).attributes('href')).toBe('/stats.index')
    })

    it('says what the number counts and where it leads', () => {
        // Announced as it stood, it was a bare number beside an icon name.
        expect(streakOf(4).attributes('aria-label')).toBe('Série de 4 jours, voir les statistiques')
    })

    it('does not say "1 jours"', () => {
        expect(streakOf(1).attributes('aria-label')).toBe('Série de 1 jour, voir les statistiques')
    })

    it('reads zero when the streak is broken rather than nothing', () => {
        expect(streakOf(0).attributes('aria-label')).toBe('Série de 0 jours, voir les statistiques')
    })
})
