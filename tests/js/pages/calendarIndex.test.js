import { describe, it, expect, vi, beforeAll, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'

const visit = vi.fn()

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
    router: { visit: (...args) => visit(...args) },
}))

import CalendarIndex from '@/Pages/Calendar/Index.vue'
import { passesSlot } from './pageStubs'

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
            stubs: { AuthenticatedLayout: passesSlot, GlassCard: passesSlot },
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
})
