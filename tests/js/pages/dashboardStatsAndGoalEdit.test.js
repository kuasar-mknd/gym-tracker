import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { nextTick } from 'vue'

const formPost = vi.fn()
const formPut = vi.fn()
const routerGet = vi.fn()
const routerVisit = vi.fn()

/**
 * Three screens that had never been mounted. Two of them are mostly composition,
 * and composition is exactly where a test can go hollow: asserting that a child
 * is on the page says nothing, because the child is on the page whatever it was
 * handed. So what is checked here is the part the page itself decides — which
 * value reaches which child, which branch of a deferred load is showing, and
 * what a click actually sends to the server.
 *
 * The `useForm` stand-in is reactive: `Dashboard` hands `form.processing` to two
 * different children, and a frozen `false` would let a page that never wires it
 * up at all look identical to one that does.
 */
let openRequest = null
let deferredHasLanded = false

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
        Head: { template: '<div />' },
        Link: {
            props: { href: { type: String, default: '' } },
            template: '<a :href="href"><slot /></a>',
        },
        // Inertia renders the fallback until the deferred prop lands, then swaps
        // in the default slot. Both halves matter and the page is never seen in
        // either of them otherwise, so the stand-in is switchable.
        Deferred: {
            setup: () => ({ hasLanded: () => deferredHasLanded }),
            template: '<div><slot v-if="hasLanded()" /><slot v-else name="fallback" /></div>',
        },
        router: {
            get: (...args) => routerGet(...args),
            visit: (...args) => routerVisit(...args),
        },
        useForm: (data) => {
            const form = reactive({
                ...data,
                errors: {},
                processing: false,
                post: (...args) => {
                    form.processing = true
                    openRequest = form

                    return formPost(...args)
                },
                put: (...args) => {
                    form.processing = true
                    openRequest = form

                    return formPut(...args)
                },
                reset: () => {},
            })

            return form
        },
    }
})

import Dashboard from '@/Pages/Dashboard.vue'
import GoalsEdit from '@/Pages/Goals/Edit.vue'
import StatsIndex from '@/Pages/Stats/Index.vue'
import { layoutStub, passesSlot } from './pageStubs'

beforeAll(() => {
    globalThis.route = (name, params) => (params === undefined ? `/${name}` : `/${name}/${JSON.stringify(params)}`)
})

beforeEach(() => {
    vi.clearAllMocks()
    openRequest = null
    deferredHasLanded = false
})

/**
 * A stub that keeps the props it was given, so the page's wiring is visible.
 *
 * @param {string} name - The component name to answer to.
 * @param {Array<string>} props - The props to record.
 * @returns {Object} A stub component.
 */
const recording = (name, props) => ({ name, props, template: '<div />' })

const settleRequest = async () => {
    openRequest.processing = false
    await nextTick()
}

describe('Dashboard', () => {
    const quickActions = recording('QuickActions', ['processing'])
    const recentActivity = recording('RecentActivity', ['recentWorkouts', 'processing'])
    const goalsSummary = recording('GoalsSummary', ['activeGoals'])
    const activeGoalsChart = recording('ActiveGoalsChart', ['data'])

    const mountDashboard = (props = {}) =>
        mount(Dashboard, {
            props,
            global: {
                directives: { press: {} },
                mocks: { route: globalThis.route, $page: { props: { auth: { user: { name: 'Sam' } } } } },
                stubs: {
                    AuthenticatedLayout: layoutStub,
                    GlassCard: passesSlot,
                    QuickActions: quickActions,
                    RecentActivity: recentActivity,
                    GoalsSummary: goalsSummary,
                    ActiveGoalsChart: activeGoalsChart,
                },
            },
        })

    it('crée la séance sur la route de création', async () => {
        const wrapper = mountDashboard()

        wrapper.findComponent({ name: 'QuickActions' }).vm.$emit('startWorkout')

        expect(formPost.mock.calls[0][0]).toBe('/workouts.store')
    })

    it('accepte la demande de démarrage des deux endroits qui la proposent', () => {
        const wrapper = mountDashboard()

        wrapper.findComponent({ name: 'RecentActivity' }).vm.$emit('startWorkout')

        // The empty state inside RecentActivity offers the same button; wired to
        // nothing it is a button that visibly does not work.
        expect(formPost.mock.calls[0][0]).toBe('/workouts.store')
    })

    it('signale aux deux boutons que la séance est en train de se créer', async () => {
        const wrapper = mountDashboard()
        const processingOf = (name) => wrapper.findComponent({ name }).props('processing')

        expect([processingOf('QuickActions'), processingOf('RecentActivity')]).toEqual([false, false])

        wrapper.findComponent({ name: 'QuickActions' }).vm.$emit('startWorkout')
        await nextTick()

        // Read while the request is still open: checked afterwards, a button
        // that was never told anything reads exactly like one that was.
        expect([processingOf('QuickActions'), processingOf('RecentActivity')]).toEqual([true, true])

        await settleRequest()

        expect([processingOf('QuickActions'), processingOf('RecentActivity')]).toEqual([false, false])
    })

    it('montre des squelettes tant que les statistiques n’ont pas atterri', () => {
        const wrapper = mountDashboard()

        // Three placeholders, not one: the fallback stands in for two side-by-side
        // cards and the strip underneath them, so a single skeleton leaves the
        // page jumping when the real content arrives.
        expect(wrapper.findAllComponents({ name: 'GlassSkeleton' })).toHaveLength(3)
    })

    it('remplace les squelettes par les sections une fois les statistiques arrivées', () => {
        deferredHasLanded = true

        const wrapper = mountDashboard({
            analyticalStats: {
                weeklyVolume: { stats: { current_week_volume: 1200, percentage: 12 }, trend: [{ week: 'S1' }] },
                workoutDistributions: { duration: [{ range: '60-90', count: 3 }], time_of_day: [{ hour: 18 }] },
            },
        })

        // Each section fed from its own branch: reading them all out of the same
        // one leaves three populated cards showing the same thing.
        expect(wrapper.findAllComponents({ name: 'GlassSkeleton' })).toHaveLength(0)
        expect(wrapper.findComponent({ name: 'WeeklyVolumeSection' }).props('weeklyVolumeTrend')).toEqual([
            { week: 'S1' },
        ])
        expect(wrapper.findComponent({ name: 'DurationSection' }).props('durationDistribution')).toEqual([
            { range: '60-90', count: 3 },
        ])
        expect(wrapper.findComponent({ name: 'TimeOfDaySection' }).props('timeOfDayDistribution')).toEqual([
            { hour: 18 },
        ])
    })

    it('ne dessine la progression des objectifs que s’il y en a', () => {
        const goals = [{ id: 1, title: 'Squat 100', progress_pct: 40 }]
        const none = mountDashboard({ activeGoals: [] })
        const one = mountDashboard({ activeGoals: goals })

        // An empty chart under a "Progression des Objectifs" heading is worse
        // than no chart: it reads as no progress rather than no goal. So the
        // heading is checked *and* the series behind it — the heading alone is
        // satisfied by exactly the empty chart this test exists to forbid.
        expect(none.text()).not.toContain('Progression des Objectifs')
        expect(one.text()).toContain('Progression des Objectifs')
        expect(one.findComponent({ name: 'ActiveGoalsChart' }).props('data')).toEqual(goals)
    })

    it('passe les objectifs au récapitulatif qui les résume', () => {
        const goals = [{ id: 1, title: 'Squat 100', progress_pct: 40 }]

        const wrapper = mountDashboard({ activeGoals: goals })

        expect(wrapper.findComponent({ name: 'GoalsSummary' }).props('activeGoals')).toEqual(goals)
    })

    it('passe les séances récentes à la liste d’activité', () => {
        const workouts = [{ id: 3, started_at: '2026-02-10T14:30:00Z' }]

        const wrapper = mountDashboard({ recentWorkouts: workouts })

        expect(wrapper.findComponent({ name: 'RecentActivity' }).props('recentWorkouts')).toEqual(workouts)
    })
})

describe('Stats/Index', () => {
    const cards = {
        WeightEvolutionCard: recording('WeightEvolutionCard', ['latestWeight', 'weightChange', 'weightHistory']),
        VolumeTrendCard: recording('VolumeTrendCard', ['volumeTrend', 'currentPeriod']),
    }

    const mountStats = (props = {}) =>
        mount(StatsIndex, {
            props: { exercises: [], ...props },
            global: {
                directives: { press: {} },
                mocks: { route: globalThis.route },
                stubs: { AuthenticatedLayout: layoutStub, ...cards },
            },
        })

    const periodButton = (wrapper, label) => wrapper.findAll('button').find((button) => button.text() === label)

    const pressedPeriod = (wrapper) =>
        wrapper
            .findAll('button')
            .filter((button) => button.attributes('aria-pressed') === 'true')
            .map((button) => button.text())

    it('retient la période choisie sur le serveur', () => {
        expect(pressedPeriod(mountStats({ selectedPeriod: '90j' }))).toEqual(['3 MOIS'])
    })

    it('retombe sur trente jours quand le serveur n’a rien choisi', () => {
        // Not just "something is selected": with no default the whole strip
        // renders unpressed and the page silently shows an unlabelled period.
        expect(pressedPeriod(mountStats())).toEqual(['30 JOURS'])
    })

    it('recharge la page sur la période demandée sans la faire sauter', async () => {
        const wrapper = mountStats({ selectedPeriod: '30j' })

        await periodButton(wrapper, '1 AN').trigger('click')

        expect(routerVisit.mock.calls[0][0]).toBe('/stats.index')
        expect(routerVisit.mock.calls[0][1]).toMatchObject({
            data: { period: '1a' },
            preserveScroll: true,
            preserveState: true,
        })
    })

    it('déplace la sélection tout de suite, sans attendre le serveur', async () => {
        const wrapper = mountStats({ selectedPeriod: '30j' })

        await periodButton(wrapper, '7 JOURS').trigger('click')

        // preserveState keeps the component alive across the visit, so a page
        // that only read the prop would leave the old button pressed until the
        // response came back.
        expect(pressedPeriod(wrapper)).toEqual(['7 JOURS'])
        expect(wrapper.findComponent({ name: 'VolumeTrendCard' }).props('currentPeriod')).toBe('7j')
    })

    it('survit à des données différées encore absentes', () => {
        const wrapper = mountStats({ latestWeight: '75.50', weightChange: -1.2 })

        // Every card is fed through `deferredData?.…`; one missing guard and the
        // first render of the page throws before anything is drawn.
        expect(wrapper.findComponent({ name: 'WeightEvolutionCard' }).props('weightHistory')).toBeUndefined()
        expect(wrapper.findComponent({ name: 'WeightEvolutionCard' }).props('latestWeight')).toBe('75.50')
    })

    it('va chercher chaque série dans la bonne branche des données différées', () => {
        const wrapper = mountStats({
            deferredData: {
                body: { weightHistory: [{ date: '2026-02-01', weight: 75 }] },
                performance: { volumeTrend: [{ date: '2026-02-01', volume: 1000 }] },
            },
        })

        // Paired card by card: reading both series out of the same branch would
        // hand one card the other's data and still look populated.
        expect(wrapper.findComponent({ name: 'WeightEvolutionCard' }).props('weightHistory')).toEqual([
            { date: '2026-02-01', weight: 75 },
        ])
        expect(wrapper.findComponent({ name: 'VolumeTrendCard' }).props('volumeTrend')).toEqual([
            { date: '2026-02-01', volume: 1000 },
        ])
    })
})

describe('Goals/Edit', () => {
    const goal = {
        id: 12,
        title: 'Squat 100 kg',
        type: 'exercise',
        target_value: 100,
        exercise_id: 4,
        measurement_type: null,
        deadline: '2026-12-31',
        start_value: 80,
    }

    const goalForm = recording('GoalForm', ['form', 'exercises', 'measurementTypes', 'submitLabel'])

    const exercises = [{ id: 4, name: 'Squat' }]
    const measurementTypes = [{ value: 'weight', label: 'Poids' }]

    const mountEdit = (overrides = {}) =>
        mount(GoalsEdit, {
            props: { goal: { ...goal, ...overrides }, exercises, measurementTypes },
            global: {
                mocks: { route: globalThis.route },
                stubs: { AuthenticatedLayout: layoutStub, GlassCard: passesSlot, GoalForm: goalForm },
            },
        })

    it('ouvre le formulaire sur l’objectif existant, tous champs compris', () => {
        const wrapper = mountEdit()

        // Field by field: seeding only the title would still show a form that
        // looks filled, and silently blank the target on the first save.
        expect(wrapper.findComponent({ name: 'GoalForm' }).props('form')).toMatchObject({
            title: 'Squat 100 kg',
            type: 'exercise',
            target_value: 100,
            exercise_id: 4,
            measurement_type: null,
            deadline: '2026-12-31',
            start_value: 80,
        })
    })

    it('passe au formulaire les listes dans lesquelles il fait choisir', () => {
        const form = mountEdit().findComponent({ name: 'GoalForm' })

        // Not decoration: the form picks the exercise and the measurement out of
        // these two lists, and an empty one leaves a select the user cannot use
        // — on a page that otherwise looks perfectly filled in.
        expect(form.props('exercises')).toEqual(exercises)
        expect(form.props('measurementTypes')).toEqual(measurementTypes)
    })

    it('annonce qu’on modifie, pas qu’on crée', () => {
        // The same GoalForm serves the create screen; the label is the only
        // thing telling the two apart on screen.
        expect(mountEdit().findComponent({ name: 'GoalForm' }).props('submitLabel')).toBe(
            'Enregistrer les modifications',
        )
    })

    it('enregistre par PUT sur l’objectif édité, pas sur la collection', () => {
        const wrapper = mountEdit()

        wrapper.findComponent({ name: 'GoalForm' }).vm.$emit('submit')

        expect(formPut.mock.calls[0][0]).toBe('/goals.update/{"goal":12}')
    })

    it('renvoie à la liste quand on annule', () => {
        const wrapper = mountEdit()

        wrapper.findComponent({ name: 'GoalForm' }).vm.$emit('cancel')

        expect(routerGet.mock.calls[0][0]).toBe('/goals.index')
    })

    it('rappelle de quel objectif il s’agit', () => {
        expect(mountEdit({ title: 'Tractions x 10' }).text()).toContain('Tractions x 10')
    })
})
