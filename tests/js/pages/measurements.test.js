import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

const formPost = vi.fn()
const routerDelete = vi.fn()

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await vi.importActual('vue')

    return {
        Head: { template: '<div />' },
        Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
        // Inertia swaps the fallback for the real slot once the deferred prop
        // lands. Rendering both at once lets one mount cover the two branches,
        // and proves the placeholder markup survives having no data at all.
        Deferred: { template: '<div><slot name="fallback" /><slot /></div>' },
        router: { delete: (...args) => routerDelete(...args) },
        /**
         * The real form is reactive: the pages read `form.part` back out of it
         * to highlight a chip. A plain object would leave every computed and
         * every class binding frozen on its first value.
         */
        useForm: (fields) => {
            const initial = { ...fields }
            const form = reactive({
                ...fields,
                errors: {},
                processing: false,
                post: (url, options = {}) => {
                    formPost(url, Object.fromEntries(Object.keys(initial).map((name) => [name, form[name]])))
                    options.onSuccess?.()
                },
                // Inertia's reset() puts back the fields it is named, or every
                // field when it is named none. A page that resets too much is
                // the bug these tests are watching for, so the mock has to be
                // able to reproduce it.
                reset: (...names) => {
                    const targets = names.length > 0 ? names : Object.keys(initial)

                    targets.forEach((name) => {
                        form[name] = initial[name]
                    })
                },
            })

            return form
        },
    }
})

// Stand-ins for the three charts, which arrive through defineAsyncComponent.
// What matters here is the series each one is handed, not how a canvas draws it.
// `__esModule` is what defineAsyncComponent reads to know it was handed a
// module rather than a component; without it the namespace itself is mounted.
vi.mock('@/Components/Stats/WeightHistoryChart.vue', () => ({
    __esModule: true,
    default: { props: ['data'], template: '<div class="weight-chart" />' },
}))
vi.mock('@/Components/Stats/BodyFatLineChart.vue', () => ({
    __esModule: true,
    default: { props: ['data'], template: '<div class="body-fat-chart" />' },
}))
vi.mock('@/Components/Stats/BodyPartDiffChart.vue', () => ({
    __esModule: true,
    default: { props: ['data'], template: '<div class="part-diff-chart" />' },
}))

import WeightHistoryChart from '@/Components/Stats/WeightHistoryChart.vue'
import BodyFatLineChart from '@/Components/Stats/BodyFatLineChart.vue'
import BodyPartDiffChart from '@/Components/Stats/BodyPartDiffChart.vue'
import MeasurementsIndex from '@/Pages/Measurements/Index.vue'
import BodyPartsIndex from '@/Pages/Measurements/Parts/Index.vue'

beforeAll(() => {
    globalThis.route = (name, params) => {
        if (name === 'body-measurements.destroy') return `/body-measurements/${params.body_measurement}`
        if (name === 'body-parts.show') return `/body-parts/${encodeURIComponent(params.part)}`

        return `/${name}`
    }
})

const passesSlot = { template: '<div><slot /></div>' }

const stubs = {
    // The layout renders its named slots too, so the "add" button that opens
    // the form is reachable from a test the way it is for the user.
    AuthenticatedLayout: { template: '<div><slot name="header-actions" /><slot name="header" /><slot /></div>' },
    GlassCard: passesSlot,
    GlassButton: { template: '<button><slot /></button>' },
    GlassInput: passesSlot,
    GlassSelect: passesSlot,
    GlassSkeleton: { template: '<div />' },
}

const mountPage = async (page, props) => {
    const wrapper = mount(page, {
        props: structuredClone(props),
        global: {
            // The Parts template calls route() straight from a :href, so it has
            // to resolve through the component instance, not just on globalThis.
            mocks: { route: globalThis.route },
            directives: { press: {} },
            stubs,
        },
    })

    await flushPromises()

    return wrapper
}

/** Clicks a button by the label the user reads on it. */
const click = async (wrapper, label) => {
    const button = wrapper.findAll('button').find((candidate) => candidate.text().trim() === label)

    expect(button, `no button labelled "${label}"`).toBeTruthy()

    await button.trigger('click')
}

const occurrences = (wrapper, needle) => wrapper.text().split(needle).length - 1

beforeEach(() => {
    formPost.mockReset()
    routerDelete.mockReset()
    vi.spyOn(window, 'confirm').mockReturnValue(true)
})

/** The controller sends the history newest first (`orderBy('measured_at', 'desc')`). */
const MEASUREMENTS = [
    { id: 3, weight: '74.50', body_fat: null, measured_at: '2026-08-12T00:00:00.000000Z', notes: null },
    { id: 2, weight: '76.00', body_fat: '18.40', measured_at: '2026-08-05T00:00:00.000000Z', notes: 'Matin, à jeun' },
    { id: 1, weight: '77.20', body_fat: '19.10', measured_at: '2026-07-29T00:00:00.000000Z', notes: null },
]

/**
 * getBodyProgressOverview sends both series oldest first, and leaves the days
 * with no body fat out of the second one — so the two are not the same length.
 */
const BODY_STATS = {
    weightHistory: [
        { date: '29/07', full_date: '2026-07-29', weight: 77.2 },
        { date: '05/08', full_date: '2026-08-05', weight: 76 },
        { date: '12/08', full_date: '2026-08-12', weight: 74.5 },
    ],
    bodyFatHistory: [
        { date: '29/07', full_date: '2026-07-29', body_fat: 19.1 },
        { date: '05/08', full_date: '2026-08-05', body_fat: 18.4 },
    ],
}

/**
 * The three tiles at the top are the only figures on this page the server does
 * not send ready-made: the page picks them out of the history itself. Everything
 * the user reads about their progress at a glance rests on those four computeds.
 */
describe('Measurements/Index headline figures', () => {
    it('reads the current weight off the top of the list, which is the newest entry', async () => {
        const wrapper = await mountPage(MeasurementsIndex, { measurements: MEASUREMENTS })

        expect(wrapper.vm.latestWeight).toBe('74.50')
    })

    it('compares the last two weigh-ins in that order, so losing weight reads as a minus', async () => {
        const wrapper = await mountPage(MeasurementsIndex, { measurements: MEASUREMENTS })

        expect(wrapper.vm.previousWeight).toBe('76.00')
        expect(wrapper.vm.weightDiff).toBe('-1.5')
        // Green is the whole point of the tile: it says the trend is the one wanted.
        expect(wrapper.find('.text-accent-success').text()).toBe('-1.5')
    })

    it('signs a gain, since "1.5" on its own reads like a loss', async () => {
        const gaining = [MEASUREMENTS[1], MEASUREMENTS[0], MEASUREMENTS[2]]

        const wrapper = await mountPage(MeasurementsIndex, { measurements: gaining })

        expect(wrapper.find('.text-accent-warning').text()).toBe('+1.5')
    })

    it('has nothing to compare after a first weigh-in, and says so instead of showing a zero', async () => {
        const wrapper = await mountPage(MeasurementsIndex, { measurements: [MEASUREMENTS[0]] })

        expect(wrapper.vm.previousWeight).toBeNull()
        expect(wrapper.vm.weightDiff).toBeNull()
        // The weight tile is filled; the trend and the body fat are not.
        expect(occurrences(wrapper, '—')).toBe(2)
    })

    it('falls back to the last day body fat was measured, because most weigh-ins skip it', async () => {
        // The newest entry has no body_fat: reading measurements[0] blindly
        // would blank a figure the user did record a week ago.
        const wrapper = await mountPage(MeasurementsIndex, { measurements: MEASUREMENTS })

        expect(wrapper.vm.latestBodyFat).toBe('18.40')
    })

    it('shows a dash when body fat was never recorded at all', async () => {
        const noBodyFat = MEASUREMENTS.map((measurement) => ({ ...measurement, body_fat: null }))

        const wrapper = await mountPage(MeasurementsIndex, { measurements: noBodyFat })

        expect(wrapper.vm.latestBodyFat).toBeNull()
    })

    it('opens on three dashes and an empty history rather than crashing on a first visit', async () => {
        const wrapper = await mountPage(MeasurementsIndex, { measurements: [] })

        expect(wrapper.vm.latestWeight).toBeNull()
        expect(wrapper.vm.latestBodyFat).toBeNull()
        expect(occurrences(wrapper, '—')).toBe(3)
        expect(wrapper.text()).toContain("Aucune mesure pour l'instant")
    })
})

/**
 * bodyStats is deferred, so the page renders at least once with no series at
 * all, then twice more as the two charts fill. Each of those states has to hold.
 */
describe('Measurements/Index charts', () => {
    it('gives each chart its own series, so kilos are never drawn as percentages', async () => {
        const wrapper = await mountPage(MeasurementsIndex, { measurements: MEASUREMENTS, bodyStats: BODY_STATS })

        expect(wrapper.findComponent(WeightHistoryChart).props('data')).toEqual(BODY_STATS.weightHistory)
        expect(wrapper.findComponent(BodyFatLineChart).props('data')).toEqual(BODY_STATS.bodyFatHistory)
    })

    it('hands the points over oldest first, the order the line is drawn in', async () => {
        const wrapper = await mountPage(MeasurementsIndex, { measurements: MEASUREMENTS, bodyStats: BODY_STATS })

        expect(
            wrapper
                .findComponent(WeightHistoryChart)
                .props('data')
                .map((point) => point.full_date),
        ).toEqual(['2026-07-29', '2026-08-05', '2026-08-12'])
    })

    it('says a series is empty instead of drawing an axis with no line on it', async () => {
        // Weight is always recorded, body fat rarely: this is the everyday shape.
        const wrapper = await mountPage(MeasurementsIndex, {
            measurements: MEASUREMENTS,
            bodyStats: { weightHistory: BODY_STATS.weightHistory, bodyFatHistory: [] },
        })

        expect(wrapper.findComponent(WeightHistoryChart).exists()).toBe(true)
        expect(wrapper.findComponent(BodyFatLineChart).exists()).toBe(false)
        expect(occurrences(wrapper, 'Aucune donnée disponible')).toBe(1)
    })

    it('holds both cards while the deferred stats are still on their way', async () => {
        // No bodyStats prop at all: the page falls back to two empty series.
        const wrapper = await mountPage(MeasurementsIndex, { measurements: MEASUREMENTS })

        expect(wrapper.findComponent(WeightHistoryChart).exists()).toBe(false)
        expect(wrapper.findComponent(BodyFatLineChart).exists()).toBe(false)
        expect(occurrences(wrapper, 'Aucune donnée disponible')).toBe(2)
    })
})

describe('Measurements/Index add form', () => {
    it('stays folded away until it is asked for', async () => {
        const wrapper = await mountPage(MeasurementsIndex, { measurements: MEASUREMENTS })

        expect(wrapper.find('form').exists()).toBe(false)

        await click(wrapper, 'Ajouter')

        expect(wrapper.find('form').exists()).toBe(true)
    })

    /**
     * Seeded from `new Date().toISOString()` the field offers the *UTC* day,
     * which is already tomorrow for the whole evening anywhere behind
     * Greenwich — and the entry is then filed on a day the user has not lived.
     *
     * The clock is pinned to an instant where the two answers differ, and the
     * expected value is a literal: asserting against the helper the page itself
     * calls would hold whatever that helper does, bug included.
     */
    it('opens on the day the user is living, not on the UTC one', async () => {
        const systemTimeZone = process.env.TZ
        process.env.TZ = 'America/Los_Angeles'
        // 02:00 UTC on the 13th is still 19:00 on the 12th in Los Angeles.
        vi.useFakeTimers({ toFake: ['Date'] })
        vi.setSystemTime(new Date('2026-08-13T02:00:00Z'))

        try {
            const wrapper = await mountPage(MeasurementsIndex, { measurements: MEASUREMENTS })

            expect(wrapper.vm.form.measured_at).toBe('2026-08-12')
        } finally {
            vi.useRealTimers()

            if (systemTimeZone === undefined) {
                delete process.env.TZ
            } else {
                process.env.TZ = systemTimeZone
            }
        }
    })

    it('sends what was typed to the measurements endpoint', async () => {
        const wrapper = await mountPage(MeasurementsIndex, { measurements: MEASUREMENTS })

        await click(wrapper, 'Ajouter')
        Object.assign(wrapper.vm.form, {
            weight: '74.1',
            body_fat: '17.9',
            measured_at: '2026-08-01',
            notes: 'Après la séance',
        })
        await wrapper.find('form').trigger('submit')

        expect(formPost).toHaveBeenCalledWith('/body-measurements.store', {
            weight: '74.1',
            body_fat: '17.9',
            measured_at: '2026-08-01',
            notes: 'Après la séance',
        })
    })

    it('clears the figures but keeps the date, so a second entry that day needs only a weight', async () => {
        const wrapper = await mountPage(MeasurementsIndex, { measurements: MEASUREMENTS })

        await click(wrapper, 'Ajouter')
        Object.assign(wrapper.vm.form, {
            weight: '74.1',
            body_fat: '17.9',
            measured_at: '2026-08-01',
            notes: 'Après la séance',
        })
        await wrapper.find('form').trigger('submit')

        expect(wrapper.vm.form.weight).toBe('')
        expect(wrapper.vm.form.body_fat).toBe('')
        expect(wrapper.vm.form.notes).toBe('')
        // Resetting this one too would silently send the user back to today,
        // which is why the date typed above is deliberately not today's.
        expect(wrapper.vm.form.measured_at).toBe('2026-08-01')
    })

    it('folds itself back up once saved, uncovering the entry that was just added', async () => {
        const wrapper = await mountPage(MeasurementsIndex, { measurements: MEASUREMENTS })

        await click(wrapper, 'Ajouter')
        await wrapper.find('form').trigger('submit')
        await flushPromises()

        expect(wrapper.find('form').exists()).toBe(false)
    })
})

/**
 * Deleting a weigh-in cannot be undone from the interface, and the button sits
 * one tap away from the row it belongs to.
 */
describe('Measurements/Index deletion', () => {
    const deleteButtonFor = (wrapper, day) => wrapper.find(`[aria-label="Supprimer la mesure du ${day}"]`)

    it('asks first, and leaves the entry alone when the answer is no', async () => {
        window.confirm.mockReturnValue(false)
        const wrapper = await mountPage(MeasurementsIndex, { measurements: MEASUREMENTS })

        await deleteButtonFor(wrapper, '2026-08-05').trigger('click')

        expect(window.confirm).toHaveBeenCalled()
        expect(routerDelete).not.toHaveBeenCalled()
    })

    it('deletes the entry whose row the button sits in, not the newest one', async () => {
        const wrapper = await mountPage(MeasurementsIndex, { measurements: MEASUREMENTS })

        await deleteButtonFor(wrapper, '2026-08-05').trigger('click')

        expect(routerDelete).toHaveBeenCalledWith('/body-measurements/2')
    })

    it('names the day it would remove, since every row carries the same icon', async () => {
        const wrapper = await mountPage(MeasurementsIndex, { measurements: MEASUREMENTS })

        // The timestamp arrives with a time on it, which no one needs read aloud.
        expect(deleteButtonFor(wrapper, '2026-08-12').exists()).toBe(true)
    })
})

const COMMON_PARTS = ['Neck', 'Chest', 'Waist']

/** One row per part, the latest value and its move against the one before. */
const PARTS = [
    { part: 'Waist', current: 82.5, unit: 'cm', date: '2026-08-12', diff: -1.5 },
    { part: 'Biceps L', current: 38, unit: 'cm', date: '2026-08-10', diff: 0.75 },
    { part: 'Chest', current: 104, unit: 'cm', date: '2026-08-01', diff: 0 },
]

/**
 * The chips are a shortcut onto a free-text field: whatever they set has to be
 * the value that is saved, or a tapped "Waist" would file itself under nothing.
 */
describe('Measurements/Parts/Index part picker', () => {
    it('writes the tapped part into the field the form actually sends', async () => {
        const wrapper = await mountPage(BodyPartsIndex, { latestMeasurements: PARTS, commonParts: COMMON_PARTS })

        await click(wrapper, 'Add')
        await click(wrapper, 'Waist')

        expect(wrapper.vm.form.part).toBe('Waist')
    })

    it('highlights the one chip that matches the field, so the choice is visible', async () => {
        const wrapper = await mountPage(BodyPartsIndex, { latestMeasurements: PARTS, commonParts: COMMON_PARTS })

        await click(wrapper, 'Add')
        await click(wrapper, 'Chest')

        const highlighted = wrapper
            .findAll('button.rounded-full')
            // Le fond orange qui porte du texte est `accent-primary-deep` : le
            // vif rend 3,21:1 avec du blanc, le profond 4,52. Ce que le test
            // garde est inchangé — qu'UNE seule pastille soit marquée.
            .filter((chip) => chip.classes().includes('bg-accent-primary-deep'))

        expect(highlighted).toHaveLength(1)
        expect(highlighted[0].text()).toBe('Chest')
    })
})

describe('Measurements/Parts/Index add form', () => {
    const fillAndSubmit = async (wrapper) => {
        await click(wrapper, 'Add')
        Object.assign(wrapper.vm.form, {
            part: 'Waist',
            value: '82.5',
            unit: 'in',
            measured_at: '2026-08-01',
            notes: 'Le matin',
        })
        await wrapper.find('form').trigger('submit')
        await flushPromises()
    }

    it('sends what was typed to the body-parts endpoint', async () => {
        const wrapper = await mountPage(BodyPartsIndex, { latestMeasurements: PARTS, commonParts: COMMON_PARTS })

        await fillAndSubmit(wrapper)

        expect(formPost).toHaveBeenCalledWith('/body-parts.store', {
            part: 'Waist',
            value: '82.5',
            unit: 'in',
            measured_at: '2026-08-01',
            notes: 'Le matin',
        })
    })

    it('keeps the part, the unit and the date, since measuring means going round the same list', async () => {
        const wrapper = await mountPage(BodyPartsIndex, { latestMeasurements: PARTS, commonParts: COMMON_PARTS })

        await fillAndSubmit(wrapper)

        expect(wrapper.vm.form.value).toBe('')
        expect(wrapper.vm.form.notes).toBe('')
        // Clearing these three would mean re-picking a part, a unit and a date
        // between every single tape measurement.
        expect(wrapper.vm.form.part).toBe('Waist')
        expect(wrapper.vm.form.unit).toBe('in')
        expect(wrapper.vm.form.measured_at).toBe('2026-08-01')
    })

    it('folds itself back up once saved', async () => {
        const wrapper = await mountPage(BodyPartsIndex, { latestMeasurements: PARTS, commonParts: COMMON_PARTS })

        await fillAndSubmit(wrapper)

        expect(wrapper.find('form').exists()).toBe(false)
    })
})

describe('Measurements/Parts/Index recent change', () => {
    it('draws the chart, with every part on it, as soon as one of them has moved', async () => {
        const wrapper = await mountPage(BodyPartsIndex, { latestMeasurements: PARTS, commonParts: COMMON_PARTS })

        expect(wrapper.findComponent(BodyPartDiffChart).props('data')).toEqual(PARTS)
    })

    it('leaves the chart out when nothing has moved, rather than drawing a flat row of zeros', async () => {
        const unmoved = PARTS.map((measurement) => ({ ...measurement, diff: 0 }))

        const wrapper = await mountPage(BodyPartsIndex, { latestMeasurements: unmoved, commonParts: COMMON_PARTS })

        expect(wrapper.findComponent(BodyPartDiffChart).exists()).toBe(false)
        expect(wrapper.text()).not.toContain('Évolution Récente')
    })

    it('leaves the chart out on a first visit, when there is nothing to plot at all', async () => {
        const wrapper = await mountPage(BodyPartsIndex, { latestMeasurements: [], commonParts: COMMON_PARTS })

        expect(wrapper.findComponent(BodyPartDiffChart).exists()).toBe(false)
    })
})

describe('Measurements/Parts/Index cards', () => {
    it('leads each card to that part own history', async () => {
        const wrapper = await mountPage(BodyPartsIndex, { latestMeasurements: PARTS, commonParts: COMMON_PARTS })

        expect(wrapper.findAll('a').map((link) => link.attributes('href'))).toEqual([
            '/body-parts/Waist',
            '/body-parts/Biceps%20L',
            '/body-parts/Chest',
        ])
    })

    it('signs a gain and lets the minus speak for a loss', async () => {
        const wrapper = await mountPage(BodyPartsIndex, { latestMeasurements: PARTS, commonParts: COMMON_PARTS })

        // Green up, red down: on a waist and on a biceps those mean opposite
        // things, so the colour only claims direction, never progress.
        expect(wrapper.find('.text-trend-up').text()).toBe('+0.75')
        expect(wrapper.find('.text-trend-down').text()).toBe('-1.5')
    })

    it('says nothing at all about a part that has not moved', async () => {
        const wrapper = await mountPage(BodyPartsIndex, { latestMeasurements: PARTS, commonParts: COMMON_PARTS })

        // A bare "0" beside a figure reads as a measurement, not as an absence.
        expect(wrapper.findAll('.text-trend-up, .text-trend-down')).toHaveLength(2)
    })
})

describe('Measurements/Parts/Index empty state', () => {
    it('says the list is empty and offers the way out of it', async () => {
        const wrapper = await mountPage(BodyPartsIndex, { latestMeasurements: [], commonParts: COMMON_PARTS })

        expect(wrapper.text()).toContain('No measurements recorded.')

        await click(wrapper, 'Start')

        expect(wrapper.find('form').exists()).toBe(true)
    })

    it('stops saying the list is empty while a measurement is being typed into it', async () => {
        const wrapper = await mountPage(BodyPartsIndex, { latestMeasurements: [], commonParts: COMMON_PARTS })

        await click(wrapper, 'Start')

        expect(wrapper.text()).not.toContain('No measurements recorded.')
    })
})
