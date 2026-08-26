import { describe, it, expect, beforeAll } from 'vitest'
import { mount } from '@vue/test-utils'

import GoalCard from '@/Components/Goals/GoalCard.vue'

beforeAll(() => {
    globalThis.route = (name, params) => `/${name}/${Object.values(params ?? {}).join('/')}`
})

const goal = (overrides = {}) => ({
    id: 7,
    title: 'Développé couché 100 kg',
    type: 'weight',
    unit: 'kg',
    start_value: 60,
    current_value: 82.5,
    target_value: 100,
    progress_pct: 56.25,
    deadline: null,
    completed_at: null,
    ...overrides,
})

const mountCard = (overrides = {}) =>
    mount(GoalCard, {
        props: { goal: goal(overrides) },
        global: {
            directives: { press: {} },
            mocks: { route: globalThis.route },
            stubs: { Link: { template: '<a :href="href"><slot /></a>', props: ['href'] } },
        },
    })

const bar = (wrapper) => wrapper.get('[role="progressbar"]')
const fill = (wrapper) => bar(wrapper).get('div')
const percentText = (wrapper) => wrapper.get('.text-right').text()

const flat = (value) => value.replace(/\s+/g, ' ').trim()

/** The two ends written above the bar, in the order they are read: start, target. */
const barLegend = (wrapper) =>
    [...bar(wrapper).element.previousElementSibling.querySelectorAll('span')].map((span) => flat(span.textContent))

/**
 * One of the two figures below the bar, found by the word above it.
 *
 * The label and the figure are joined here rather than read from `.text()`,
 * which concatenates children with nothing between them. The tile renders
 * `<p>Actuel</p><p>3 séances</p>`: whether that yields `Actuel 3 séances` or
 * `Actuel3 séances` depends on whether Prettier kept the tags on separate
 * lines, which is a formatting accident and not a rendering difference — the
 * two paragraphs are blocks either way. Joining the children says what this
 * helper means, and stops a line-length change from failing the test.
 */
const statTile = (wrapper, heading) => {
    const tile = wrapper.findAll('.grid > div').find((candidate) => flat(candidate.text()).startsWith(heading))

    return [...tile.element.children]
        .map((child) => flat(child.textContent))
        .filter(Boolean)
        .join(' ')
}

/**
 * Runs `body` with the clock sitting behind UTC.
 *
 * A calendar day read as an instant only lands a day early where the browser is
 * behind UTC. This suite runs in Europe/Zurich locally and UTC on CI — from
 * either, the bug is invisible and the assertion passes on a broken component.
 * So the test goes and stands where the defect shows.
 */
const inATimezoneBehindUtc = (body) => {
    const original = process.env.TZ
    process.env.TZ = 'America/Los_Angeles'

    try {
        return body()
    } finally {
        if (original === undefined) {
            delete process.env.TZ
        } else {
            process.env.TZ = original
        }
    }
}

/**
 * GoalType has exactly four cases (app/Enums/GoalType.php); a goal whose type
 * the card does not recognise still has to render something.
 */
const DOMAIN_TYPES = ['weight', 'volume', 'frequency', 'measurement']

describe('GoalCard — progress', () => {
    it('rounds the percentage it shows', () => {
        expect(percentText(mountCard({ progress_pct: 56.25 }))).toContain('56%')
        expect(percentText(mountCard({ progress_pct: 56.75 }))).toContain('57%')
    })

    it('sizes the bar with the unrounded percentage and announces it', () => {
        const wrapper = mountCard({ progress_pct: 56.25 })

        expect(fill(wrapper).attributes('style')).toContain('width: 56.25%')
        expect(bar(wrapper).attributes('aria-valuenow')).toBe('56.25')
    })

    it('reads zero, not NaN, for a goal the server sent no progress for', () => {
        const wrapper = mountCard({ progress_pct: null })

        expect(percentText(wrapper)).toContain('0%')
        expect(fill(wrapper).attributes('style')).toContain('width: 0%')
    })
})

describe('GoalCard — completion', () => {
    it('marks a finished goal as complete', () => {
        const wrapper = mountCard({ completed_at: '2026-07-01T10:00:00.000000Z', progress_pct: 100 })

        expect(wrapper.text()).toContain('COMPLÉTÉ')
    })

    it('does not mark a goal still in progress', () => {
        expect(mountCard({ progress_pct: 99.5 }).text()).not.toContain('COMPLÉTÉ')
    })

    it('reads as done even when the progress figure lags behind', () => {
        // A frequency goal can be completed early; the completion is the fact,
        // the percentage is only an estimate of it.
        const wrapper = mountCard({ completed_at: '2026-07-01T10:00:00.000000Z', progress_pct: 30 })
        const done = wrapper.get('.text-right > div').classes().join(' ')
        const running = mountCard({ progress_pct: 30 }).get('.text-right > div').classes().join(' ')

        expect(done).toContain('green')
        expect(done).not.toBe(running)
    })
})

describe('GoalCard — the kind of goal', () => {
    it('gives every kind of goal its own label and icon', () => {
        const rendered = DOMAIN_TYPES.map((type) => {
            const wrapper = mountCard({ type })

            return {
                type,
                label: wrapper.get('h4 + span').text(),
                icon: wrapper.get('.text-2xl').text(),
            }
        })

        expect(new Set(rendered.map((entry) => entry.label)).size).toBe(DOMAIN_TYPES.length)
        expect(new Set(rendered.map((entry) => entry.icon)).size).toBe(DOMAIN_TYPES.length)

        expect(rendered.find((entry) => entry.type === 'frequency').label).toBe('Fréquence')
        expect(rendered.find((entry) => entry.type === 'volume').label).toBe('Volume')
        expect(rendered.find((entry) => entry.type === 'measurement').label).toBe('Mesure')
        expect(rendered.find((entry) => entry.type === 'weight').label).toBe('Poids (Max)')
    })

    it('still renders a goal whose type it does not know', () => {
        const wrapper = mountCard({ type: 'streak' })

        expect(wrapper.get('h4 + span').text()).toBe('Objectif')
        expect(wrapper.text()).toContain('Développé couché 100 kg')
    })
})

describe('GoalCard — figures', () => {
    /*
     * Each figure is read off the place it belongs to rather than off the card
     * as a whole: three `toContain` on the whole text all still pass with the
     * two ends of the bar swapped, since both numbers are on the card either
     * way.
     */
    it('shows where the goal started, stands and is heading, each with its unit', () => {
        const wrapper = mountCard({ unit: 'séances', start_value: 2, current_value: 3, target_value: 5 })

        expect(barLegend(wrapper)).toEqual(['2 séances', '5 séances'])
        expect(statTile(wrapper, 'Actuel')).toBe('Actuel 3 séances')
        expect(statTile(wrapper, 'Cible')).toBe('Cible 5 séances')
    })

    it('renders a deadline as a calendar day, not as an instant', () => {
        // The column is cast `date:Y-m-d`; read as an instant it lands a day
        // early anywhere behind UTC.
        inATimezoneBehindUtc(() => {
            const wrapper = mountCard({ deadline: '2026-12-25' })

            expect(wrapper.text()).toContain('Échéance')
            expect(wrapper.text()).toContain(new Date('2026-12-25T00:00:00').toLocaleDateString())
            expect(wrapper.text()).not.toContain(new Date('2026-12-24T00:00:00').toLocaleDateString())
        })
    })

    it('says nothing about a deadline the goal does not have', () => {
        expect(mountCard({ deadline: null }).text()).not.toContain('Échéance')
    })
})

describe('GoalCard — actions', () => {
    it('hands the goal itself to the delete handler', async () => {
        const wrapper = mountCard()

        await wrapper.get('[dusk="delete-goal-7"]').trigger('click')

        expect(wrapper.emitted('delete')).toHaveLength(1)
        expect(wrapper.emitted('delete')[0][0].id).toBe(7)
    })

    it('links to the edit page of this goal', () => {
        expect(mountCard().get('[dusk="edit-goal-7"]').attributes('href')).toBe('/goals.edit/7')
    })

    it('names both controls after the goal they act on', () => {
        const wrapper = mountCard()

        expect(wrapper.get('[dusk="edit-goal-7"]').attributes('aria-label')).toBe(
            "Modifier l'objectif Développé couché 100 kg",
        )
        expect(wrapper.get('[dusk="delete-goal-7"]').attributes('aria-label')).toBe(
            "Supprimer l'objectif Développé couché 100 kg",
        )
    })
})
