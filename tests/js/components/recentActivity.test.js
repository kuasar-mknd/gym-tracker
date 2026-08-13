import { describe, it, expect, vi, beforeAll, afterAll } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * Every line this component prints about a session is rendered in the reader's
 * own zone: `new Date(started_at)` parses an instant, and both `toLocale…` calls
 * then place it locally. Asserted from UTC that is invisible — the formatted day
 * and the UTC day agree, and a component that had dropped the zone entirely
 * would pass. One of the fixtures below is a late-evening session that falls on
 * the *previous* calendar day once placed in New York, which is exactly the case
 * a zone-blind rendering gets wrong.
 */
const originalTimeZone = process.env.TZ
process.env.TZ = 'America/New_York'
afterAll(() => {
    // `process.env.TZ = undefined` stores the *string* "undefined", which Node
    // reads as an invalid zone and silently resolves to UTC — so restoring an
    // unset TZ that way leaves the process somewhere it never was.
    if (originalTimeZone === undefined) {
        delete process.env.TZ
    } else {
        process.env.TZ = originalTimeZone
    }
})

vi.mock('@inertiajs/vue3', () => ({
    Link: {
        props: { href: { type: String, default: '' } },
        template: '<a :href="href"><slot /></a>',
    },
}))

/**
 * The timeline chart arrives through `defineAsyncComponent`; `__esModule` is what
 * tells Vue the component is on `default` rather than mounting the namespace.
 */
vi.mock('@/Components/Stats/RecentWorkoutsTimelineChart.vue', () => ({
    __esModule: true,
    default: {
        name: 'RecentWorkoutsTimelineChart',
        props: { data: { type: Array, default: () => [] } },
        template: '<div class="timeline-stub" />',
    },
}))

import RecentActivity from '@/Components/Dashboard/RecentActivity.vue'

beforeAll(() => {
    globalThis.route = (name, params) => (params === undefined ? `/${name}` : `/${name}/${JSON.stringify(params)}`)
})

const session = (overrides = {}) => ({
    id: 1,
    name: 'Push',
    started_at: '2026-02-10T14:30:00Z',
    ended_at: '2026-02-10T15:15:00Z',
    workout_lines_count: 5,
    ...overrides,
})

const mountActivity = async (props) => {
    const wrapper = mount(RecentActivity, {
        props: { recentWorkouts: [], ...props },
        global: {
            directives: { press: {} },
            mocks: { route: globalThis.route },
        },
    })

    // The chart is lazy; without this the `v-else` branch renders a placeholder.
    await flushPromises()

    return wrapper
}

/** The one card standing for a session, as the reader sees it. */
const cardFor = (wrapper, index = 0) => wrapper.findAll('a[href^="/workouts.show"]')[index]

describe('quand rien n’a encore été fait', () => {
    it('propose de commencer plutôt que d’afficher une liste vide', async () => {
        const wrapper = await mountActivity({ recentWorkouts: [] })

        expect(wrapper.text()).toContain('Aucune séance pour l’instant'.replace('’', "'"))
        expect(wrapper.findComponent({ name: 'RecentWorkoutsTimelineChart' }).exists()).toBe(false)
    })

    it('remonte la demande de démarrage au tableau de bord', async () => {
        const wrapper = await mountActivity({ recentWorkouts: [] })

        await wrapper.find('button').trigger('click')

        expect(wrapper.emitted('startWorkout')).toHaveLength(1)
    })

    it('occupe le bouton pendant que la séance se crée', async () => {
        const idle = await mountActivity({ recentWorkouts: [], processing: false })
        const busy = await mountActivity({ recentWorkouts: [], processing: true })

        // Both sides of `disabled` too: asserted only on the busy button, a
        // button disabled unconditionally reads exactly like a working one.
        expect(idle.find('button').attributes('aria-busy')).toBe('false')
        expect(idle.find('button').attributes('disabled')).toBeUndefined()
        expect(busy.find('button').attributes('aria-busy')).toBe('true')
        expect(busy.find('button').attributes('disabled')).toBe('')
    })

    it('renvoie vers la liste complète des séances', async () => {
        const wrapper = await mountActivity({ recentWorkouts: [] })

        expect(wrapper.find('a').attributes('href')).toBe('/workouts.index')
    })
})

describe('quand il y a des séances', () => {
    it('donne une carte à chacune, vers sa propre page', async () => {
        const wrapper = await mountActivity({
            recentWorkouts: [session({ id: 7, name: 'Push' }), session({ id: 9, name: 'Pull' })],
        })

        // Paired name to href: two cards both linking to the same session would
        // survive any assertion made on the set of hrefs alone.
        expect(
            wrapper
                .findAll('a[href^="/workouts.show"]')
                .map((card) => [card.find('h4').text(), card.attributes('href')]),
        ).toEqual([
            ['Push', '/workouts.show/{"workout":7}'],
            ['Pull', '/workouts.show/{"workout":9}'],
        ])
    })

    it('remplace la liste vide par la courbe des durées', async () => {
        const wrapper = await mountActivity({ recentWorkouts: [session()] })

        expect(wrapper.findComponent({ name: 'RecentWorkoutsTimelineChart' }).props('data')).toHaveLength(1)
    })

    it('date et horodate la séance dans le fuseau du lecteur', async () => {
        const wrapper = await mountActivity({
            recentWorkouts: [session({ started_at: '2026-08-03T01:15:00Z', ended_at: '2026-08-03T02:00:00Z' })],
        })

        // 01:15 UTC on Monday 3 August is 21:15 on Sunday 2 August in New York.
        // A rendering that dropped the zone would print "lundi 3 août" here.
        expect(cardFor(wrapper).text()).toContain('dimanche 2 août')
        expect(cardFor(wrapper).text()).toContain('21:15')
    })

    it('affiche des tirets, pas un zéro, tant que la séance court', async () => {
        const running = await mountActivity({ recentWorkouts: [session({ ended_at: null })] })
        const finished = await mountActivity({ recentWorkouts: [session()] })

        // "0 min" reads as a session that lasted no time at all; the session is
        // simply still going.
        expect(running.text()).toContain('-- min')
        expect(running.text()).not.toContain('0 min')
        expect(finished.text()).toContain('45 min')
    })

    it('distingue une séance terminée d’une séance en cours', async () => {
        const running = await mountActivity({ recentWorkouts: [session({ ended_at: null })] })
        const finished = await mountActivity({ recentWorkouts: [session()] })

        expect(running.text()).toContain('En cours')
        expect(running.text()).not.toContain('Fait')
        expect(finished.text()).toContain('Fait')
        expect(finished.text()).not.toContain('En cours')
    })

    it('remplace un intitulé manquant par un libellé plutôt que par du vide', async () => {
        const wrapper = await mountActivity({ recentWorkouts: [session({ name: null })] })

        expect(cardFor(wrapper).text()).toContain('Séance')
    })

    it('bascule l’icône au-dessus de trois lignes, pas à trois', async () => {
        const three = await mountActivity({ recentWorkouts: [session({ workout_lines_count: 3 })] })
        const four = await mountActivity({ recentWorkouts: [session({ workout_lines_count: 4 })] })

        // Tested on both sides of the boundary: at 4 only, a `>` turned into a
        // `>=` still passes every check made in the middle of the range.
        expect(three.find('.material-symbols-outlined').text()).toBe('fitness_center')
        expect(four.find('.material-symbols-outlined').text()).toBe('timer')
    })

    it('traite une séance sans compte de lignes comme une séance courte', async () => {
        const wrapper = await mountActivity({ recentWorkouts: [session({ workout_lines_count: null })] })

        // `null > 3` is false either way; the `|| 0` is there so an undefined
        // count cannot become NaN in a later refactor.
        expect(wrapper.find('.material-symbols-outlined').text()).toBe('fitness_center')
    })
})
