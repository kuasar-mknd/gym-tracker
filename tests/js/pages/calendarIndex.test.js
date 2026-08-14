import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'

const visit = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    // The href is forwarded: each session in the day panel links to its own
    // page, and a stub that drops it turns "the right link" into "a link".
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { visit: (...args) => visit(...args) },
}))

import CalendarIndex from '@/Pages/Calendar/Index.vue'
import { layoutStub, passesSlot } from './pageStubs'

beforeAll(() => {
    globalThis.route = (name, params) => `/${name}/${JSON.stringify(params ?? '')}`
})

beforeEach(() => {
    vi.clearAllMocks()
})

afterEach(() => {
    vi.useRealTimers()
})

const mountPage = ({ year = 2026, month = 2, workouts = [], journals = [] } = {}) =>
    mount(CalendarIndex, {
        props: { year, month, workouts, journals },
        global: {
            mocks: { route: globalThis.route },
            // The "Aujourd'hui" button lives in #header-actions; a stub that
            // forwards only the default slot drops it, and every assertion
            // about it then passes for the wrong reason.
            stubs: { AuthenticatedLayout: layoutStub, GlassCard: passesSlot },
        },
    })

/** The cells that stand for a real day, in grid order. */
const realDays = (wrapper) => wrapper.vm.calendarGrid.filter((cell) => cell.day !== null)

/** How many blank cells the grid opens with. */
const leadingBlanks = (wrapper) => wrapper.vm.calendarGrid.findIndex((cell) => cell.day !== null)

describe('laying out the month', () => {
    it('starts the week on Monday, not Sunday', () => {
        // 1 February 2026 falls on a Sunday. With JavaScript's Sunday-first
        // week that is index 0 and the month would open flush against the
        // Monday column — every date in the grid then sits a column early.
        const wrapper = mountPage({ year: 2026, month: 2 })

        expect(leadingBlanks(wrapper)).toBe(6)
    })

    it('opens flush when the month begins on a Monday', () => {
        // 1 June 2026 is a Monday.
        const wrapper = mountPage({ year: 2026, month: 6 })

        expect(leadingBlanks(wrapper)).toBe(0)
    })

    it('gives February its extra day in a leap year', () => {
        expect(realDays(mountPage({ year: 2024, month: 2 }))).toHaveLength(29)
        expect(realDays(mountPage({ year: 2026, month: 2 }))).toHaveLength(28)
    })

    it('pads the date string so it matches what the server sends', () => {
        const wrapper = mountPage({ year: 2026, month: 3 })

        // The server sends 2026-03-05; an unpadded 2026-3-5 matches nothing,
        // and every dot on the calendar quietly disappears.
        expect(realDays(wrapper)[4].dateStr).toBe('2026-03-05')
    })
})

describe('marking the days that have something on them', () => {
    it('puts the dots on the right dates', () => {
        const wrapper = mountPage({
            year: 2026,
            month: 2,
            workouts: [{ date: '2026-02-10', id: 1 }],
            journals: [{ date: '2026-02-11', id: 2 }],
        })

        const tenth = realDays(wrapper)[9]
        const eleventh = realDays(wrapper)[10]

        expect([tenth.day, tenth.hasWorkout, tenth.hasJournal]).toEqual([10, true, false])
        expect([eleventh.day, eleventh.hasWorkout, eleventh.hasJournal]).toEqual([11, false, true])
    })

    it('keeps every session of a day, not just the first', () => {
        const wrapper = mountPage({
            year: 2026,
            month: 2,
            workouts: [
                { date: '2026-02-10', id: 1 },
                { date: '2026-02-10', id: 2 },
            ],
        })

        expect(realDays(wrapper)[9].workouts).toHaveLength(2)
    })

    it('says what a day holds in its name, not only in colour', () => {
        const wrapper = mountPage({
            year: 2026,
            month: 2,
            workouts: [{ date: '2026-02-10', id: 1 }],
            journals: [{ date: '2026-02-10', id: 2 }],
        })

        // The dots carry their meaning through colour alone, which a screen
        // reader cannot read out.
        expect(wrapper.vm.dayLabel(realDays(wrapper)[9])).toBe('10 Février 2026, séance, journal')
    })

    it('marks today, and only today', () => {
        vi.useFakeTimers()
        vi.setSystemTime(new Date(2026, 1, 10))

        const wrapper = mountPage({ year: 2026, month: 2 })

        expect(
            realDays(wrapper)
                .filter((day) => day.isToday)
                .map((day) => day.day),
        ).toEqual([10])
    })
})

describe('moving between months', () => {
    it('rolls December into January of the next year', () => {
        const wrapper = mountPage({ year: 2026, month: 12 })

        wrapper.vm.changeMonth(1)

        expect(visit.mock.calls[0][0]).toContain('"year":2027')
        expect(visit.mock.calls[0][0]).toContain('"month":1')
    })

    it('rolls January back into December of the year before', () => {
        const wrapper = mountPage({ year: 2026, month: 1 })

        wrapper.vm.changeMonth(-1)

        expect(visit.mock.calls[0][0]).toContain('"year":2025')
        expect(visit.mock.calls[0][0]).toContain('"month":12')
    })

    it('stays in the same year for an ordinary step', () => {
        const wrapper = mountPage({ year: 2026, month: 5 })

        wrapper.vm.changeMonth(1)

        expect(visit.mock.calls[0][0]).toContain('"year":2026')
        expect(visit.mock.calls[0][0]).toContain('"month":6')
    })

    it('counts each tap from the last one, not from what is still on screen', () => {
        const wrapper = mountPage({ year: 2026, month: 3 })

        // Three taps with no response in between — a phone with a slow
        // connection, or an impatient thumb. The displayed month only moves in
        // onSuccess, so counting from it asked for April three times; Inertia
        // interrupts the earlier visits, and the user landed on April having
        // pressed forward three times.
        wrapper.vm.changeMonth(1)
        wrapper.vm.changeMonth(1)
        wrapper.vm.changeMonth(1)

        expect(visit.mock.calls.map((call) => call[0])).toEqual([
            expect.stringContaining('"month":4'),
            expect.stringContaining('"month":5'),
            expect.stringContaining('"month":6'),
        ])
    })

    it('carries the year over when the taps cross December', () => {
        const wrapper = mountPage({ year: 2026, month: 11 })

        // November, December, then over into the next year.
        wrapper.vm.changeMonth(1)
        wrapper.vm.changeMonth(1)

        expect(visit.mock.calls.map((call) => call[0])).toEqual([
            expect.stringContaining('"year":2026,"month":12'),
            expect.stringContaining('"year":2027,"month":1'),
        ])
    })

    it('forgets a step the server refused', () => {
        const wrapper = mountPage({ year: 2026, month: 3 })

        wrapper.vm.changeMonth(1)
        visit.mock.calls[0][1].onError()

        wrapper.vm.changeMonth(1)

        // The failed step never happened, so the next one starts from March
        // again. Without the rollback the user would be stepping from a month
        // they were never shown.
        expect(visit.mock.calls[1][0]).toContain('"month":4')
    })

    it('steps from today after jumping home', () => {
        const wrapper = mountPage({ year: 2026, month: 3 })

        wrapper.vm.changeMonth(1)
        wrapper.vm.goToToday()
        wrapper.vm.changeMonth(1)

        const today = new Date()
        const expected = today.getMonth() + 2 > 12 ? 1 : today.getMonth() + 2

        expect(visit.mock.calls.at(-1)[0]).toContain(`"month":${expected}`)
    })
})

describe('picking a day', () => {
    it('ignores the blank cells before the first', () => {
        const wrapper = mountPage({ year: 2026, month: 2 })

        wrapper.vm.selectDate(wrapper.vm.calendarGrid[0])

        // A padding cell has no date; opening a detail panel on it would show
        // an empty day the user never clicked.
        expect(wrapper.vm.selectedDayDetails).toBeNull()
    })

    it('opens the day that was clicked', () => {
        const wrapper = mountPage({ year: 2026, month: 2 })

        wrapper.vm.selectDate(realDays(wrapper)[9])

        expect(wrapper.vm.selectedDayDetails.dateStr).toBe('2026-02-10')
    })

    it('opens the day whose cell was tapped, and marks it as the one open', async () => {
        const wrapper = mountPage({ year: 2026, month: 2 })

        const tenth = wrapper.find('[dusk="calendar-day-2026-02-10"]')
        await tenth.trigger('click')

        expect(wrapper.find('[dusk="calendar-day-details"]').exists()).toBe(true)
        expect(wrapper.find('[dusk="calendar-day-2026-02-10"]').attributes('aria-pressed')).toBe('true')
        // Exactly one: a pressed state left on the previous day would announce
        // two selected cells at once.
        expect(wrapper.findAll('[aria-pressed="true"]')).toHaveLength(1)
    })

    it('says nothing is open before a day is picked', () => {
        const wrapper = mountPage({ year: 2026, month: 2 })

        expect(wrapper.find('[dusk="calendar-day-details"]').exists()).toBe(false)
        expect(wrapper.findAll('[aria-pressed="true"]')).toHaveLength(0)
    })
})

describe('the panel under the grid', () => {
    const workout = (overrides = {}) => ({
        id: 1,
        date: '2026-02-10',
        name: 'Push A',
        exercises_count: 4,
        preview_exercises: ['Développé', 'Dips'],
        ...overrides,
    })

    const openTenth = async (props) => {
        const wrapper = mountPage({ year: 2026, month: 2, ...props })
        await wrapper.find('[dusk="calendar-day-2026-02-10"]').trigger('click')

        return wrapper
    }

    it('writes the date out in full, in French', async () => {
        const wrapper = await openTenth({})

        // 10 February 2026 is a Tuesday. The locale is pinned in the component,
        // so this literal is the same on any machine.
        expect(wrapper.find('h3').text()).toBe('mardi 10 février 2026')
    })

    it('keeps each session on its own card and its own link', async () => {
        const wrapper = await openTenth({
            workouts: [
                workout({ id: 3, name: 'Push A', exercises_count: 4, preview_exercises: ['Développé', 'Dips'] }),
                workout({ id: 8, name: 'Pull B', exercises_count: 6, preview_exercises: ['Traction'] }),
            ],
        })

        const links = wrapper.find('[dusk="calendar-day-details"]').findAll('a')

        expect(links).toHaveLength(2)
        // Card by card: a page-wide check would pass just as well with the two
        // names swapped between the two links.
        expect(links[0].attributes('href')).toContain('workouts.show/3')
        expect(links[0].text()).toContain('Push A')
        expect(links[0].text()).toContain('4 exercices')
        expect(links[0].text()).toContain('Développé, Dips')
        expect(links[1].attributes('href')).toContain('workouts.show/8')
        expect(links[1].text()).toContain('Pull B')
        expect(links[1].text()).toContain('6 exercices')
        expect(links[1].text()).toContain('Traction')
    })

    it('leaves the bullet off a session with nothing to preview', async () => {
        const wrapper = await openTenth({ workouts: [workout({ preview_exercises: [] })] })

        const card = wrapper.find('[dusk="calendar-day-details"]').find('a')

        expect(card.text()).toContain('4 exercices')
        // A dangling "• " with nothing after it is what an empty list used to
        // render as.
        expect(card.text()).not.toContain('•')
    })

    it('shows the mood of the day only when one was recorded', async () => {
        const scored = await openTenth({ journals: [{ date: '2026-02-10', id: 2, mood_score: 7, has_note: true }] })
        const unscored = await openTenth({
            journals: [{ date: '2026-02-10', id: 2, mood_score: null, has_note: true }],
        })

        expect(scored.find('[dusk="calendar-day-details"]').text()).toContain('Humeur: 7/10')
        expect(unscored.find('[dusk="calendar-day-details"]').text()).not.toContain('Humeur')
    })

    it('distinguishes a journal entry with notes from one without', async () => {
        const written = await openTenth({ journals: [{ date: '2026-02-10', id: 2, has_note: true }] })
        const blank = await openTenth({ journals: [{ date: '2026-02-10', id: 2, has_note: false }] })

        expect(written.find('[dusk="calendar-day-details"]').text()).toContain('Notes ajoutées...')
        expect(blank.find('[dusk="calendar-day-details"]').text()).toContain('Aucune note écrite.')
    })

    it('says the day was empty rather than showing a bare heading', async () => {
        const empty = await openTenth({})
        const busy = await openTenth({ workouts: [workout()] })

        expect(empty.find('[dusk="calendar-day-details"]').text()).toContain('Aucune activité ce jour-là.')
        // Only then: the message above a list of sessions would contradict it.
        expect(busy.find('[dusk="calendar-day-details"]').text()).not.toContain('Aucune activité')
    })
})

describe('what the arrows and the heading do', () => {
    it('steps back on the left arrow and forward on the right', async () => {
        const wrapper = mountPage({ year: 2026, month: 5 })

        await wrapper.find('[aria-label="Mois précédent"]').trigger('click')
        expect(visit.mock.calls[0][0]).toContain('"month":4')

        await wrapper.find('[aria-label="Mois suivant"]').trigger('click')
        // Counted from the step already asked for, so back-then-forward returns
        // to May rather than skipping to June.
        expect(visit.mock.calls[1][0]).toContain('"month":5')
    })

    it('moves the heading only once the month it asked for has arrived', async () => {
        const wrapper = mountPage({ year: 2026, month: 12 })

        await wrapper.find('[aria-label="Mois suivant"]').trigger('click')

        // Still December, and still showing December's grid: moving the header
        // before the data lands flashes an empty month at the user.
        expect(wrapper.find('[dusk="calendar-heading"]').text()).toBe('Décembre 2026')

        visit.mock.calls[0][1].onSuccess()
        await wrapper.vm.$nextTick()

        expect(wrapper.find('[dusk="calendar-heading"]').text()).toBe('Janvier 2027')
    })

    it('closes the open day when the new month arrives', async () => {
        const wrapper = mountPage({
            year: 2026,
            month: 2,
            workouts: [{ date: '2026-02-10', id: 1, name: 'Push A', exercises_count: 4, preview_exercises: [] }],
        })

        await wrapper.find('[dusk="calendar-day-2026-02-10"]').trigger('click')
        expect(wrapper.find('[dusk="calendar-day-details"]').exists()).toBe(true)

        await wrapper.find('[aria-label="Mois suivant"]').trigger('click')
        visit.mock.calls[0][1].onSuccess()
        await wrapper.vm.$nextTick()

        // The panel describes a day of February; left open over March it would
        // sit under a grid that no longer contains it.
        expect(wrapper.find('[dusk="calendar-day-details"]').exists()).toBe(false)
    })

    it('offers the way home only when the user is not already there', async () => {
        vi.useFakeTimers()
        vi.setSystemTime(new Date(2026, 1, 10))

        const away = mountPage({ year: 2026, month: 3 })
        const home = mountPage({ year: 2026, month: 2 })

        expect(away.find('[dusk="calendar-today"]').exists()).toBe(true)
        expect(home.find('[dusk="calendar-today"]').exists()).toBe(false)
    })

    it('brings the heading home once the jump lands, and closes the open day', async () => {
        vi.useFakeTimers()
        vi.setSystemTime(new Date(2026, 1, 10))

        const wrapper = mountPage({
            year: 2025,
            month: 3,
            workouts: [{ date: '2025-03-10', id: 1, name: 'Push A', exercises_count: 4, preview_exercises: [] }],
        })
        await wrapper.find('[dusk="calendar-day-2025-03-10"]').trigger('click')

        await wrapper.find('[dusk="calendar-today"]').trigger('click')
        expect(wrapper.find('[dusk="calendar-heading"]').text()).toBe('Mars 2025')

        visit.mock.calls[0][1].onSuccess()
        await wrapper.vm.$nextTick()

        expect(wrapper.find('[dusk="calendar-heading"]').text()).toBe('Février 2026')
        expect(wrapper.find('[dusk="calendar-day-details"]').exists()).toBe(false)
        // Home is home: the button that took the user there is gone.
        expect(wrapper.find('[dusk="calendar-today"]').exists()).toBe(false)
    })
})
