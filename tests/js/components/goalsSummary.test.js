import { describe, it, expect, beforeAll, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@inertiajs/vue3', () => ({
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
}))

import GoalsSummary from '@/Components/Dashboard/GoalsSummary.vue'

beforeAll(() => {
    globalThis.route = (name) => `/${name}`
})

const mountSummary = (activeGoals) =>
    mount(GoalsSummary, {
        props: { activeGoals },
        global: {
            mocks: { route: globalThis.route },
            stubs: { GlassCard: { template: '<div><slot /></div>' } },
        },
    })

const goals = [
    { id: 1, title: 'Squat 140 kg', progress_pct: 42.4 },
    { id: 2, title: 'Trois séances par semaine', progress_pct: 66.6 },
]

describe('GoalsSummary', () => {
    it('lists one row per goal in progress', () => {
        const wrapper = mountSummary(goals)

        expect(wrapper.findAll('[role="progressbar"]')).toHaveLength(2)
        expect(wrapper.text()).toContain('Squat 140 kg')
        expect(wrapper.text()).toContain('Trois séances par semaine')
    })

    it('ne dit pas 100 % d’un objectif qui n’est pas atteint', () => {
        const wrapper = mountSummary([{ id: 3, title: '200 séances', progress_pct: 99.5 }])

        expect(wrapper.text()).toContain('99')
        expect(wrapper.text()).not.toContain('100')
    })

    it('offre au compte sans objectif un lien vers la page des objectifs (#2355)', () => {
        const wrapper = mountSummary([])
        const liens = wrapper.findAll('a')

        expect(wrapper.find('[role="progressbar"]').exists()).toBe(false)
        expect(wrapper.text()).toContain('Aucun objectif en cours')
        expect(liens).toHaveLength(1)
        expect(liens[0].attributes('href')).toBe('/goals.index')
        expect(liens[0].text()).toContain('Fixer un objectif')
    })

    it('rounds the figure it prints but not the bar it draws', () => {
        const wrapper = mountSummary([goals[0]])
        const bar = wrapper.get('[role="progressbar"]')

        expect(wrapper.text()).toContain('42 %')
        expect(bar.get('div').attributes('style')).toContain('width: 42.4%')
        expect(bar.attributes('aria-valuenow')).toBe('42.4')
    })

    it('rounds up past the halfway mark', () => {
        expect(mountSummary([goals[1]]).text()).toContain('67 %')
    })

    it('sends every row, and the "see all" link, to the goals page', () => {
        const wrapper = mountSummary(goals)

        const targets = wrapper.findAll('a').map((link) => link.attributes('href'))

        expect(targets).toHaveLength(3)
        expect(new Set(targets)).toEqual(new Set(['/goals.index']))
    })
})

/*
 * Une copie hors ligne de l'accueil ne garde jamais les objectifs (#2048,
 * partie E) : la carte le dit, sans lien vers des objectifs qu'aucune copie
 * ne garde non plus.
 */
describe('GoalsSummary sur une copie hors ligne', () => {
    const monter = (props) =>
        mount(GoalsSummary, {
            props: { activeGoals: [], ...props },
            global: { mocks: { route: globalThis.route }, stubs: { GlassCard: { template: '<div><slot /></div>' } } },
        })

    it('dit les objectifs indisponibles hors ligne, sans lien', () => {
        const carte = monter({ indisponible: true })

        expect(carte.get('[dusk="objectifs-indisponibles"]').text()).toContain('Objectifs en cours')
        expect(carte.get('[data-indisponible-hors-ligne]').text()).toContain('Objectifs indisponibles hors ligne')
        expect(carte.find('a').exists()).toBe(false)
    })

    it('ne dit pas « indisponible » d’un compte sans objectif', () => {
        expect(monter().text()).not.toContain('indisponibles')
    })
})
