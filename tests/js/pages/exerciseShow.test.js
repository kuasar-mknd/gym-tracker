import { describe, it, expect, vi, beforeAll } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { template: '<a><slot /></a>' },
}))

import ExerciseShow from '@/Pages/Exercises/Show.vue'
import { passesSlot } from './pageStubs'

beforeAll(() => {
    globalThis.route = (name, params) => `/${name}/${JSON.stringify(params ?? '')}`
})

const session = (date, sets, best1rm = 0) => ({
    formatted_date: date,
    sets,
    best_1rm: best1rm,
})

/**
 * Every chart on this page reads the same `history` prop, which the server
 * sends newest first, and turns it into a series. Two things are load-bearing
 * across all of them: the reversal, because a progression drawn backwards tells
 * the opposite story; and the guards, because a session with no sets or a set
 * with no weight is ordinary data here, not a corner case.
 */
const mountPage = (history = []) =>
    mount(ExerciseShow, {
        props: {
            exercise: { id: 1, name: 'Développé Couché', type: 'strength', category: 'Pectoraux' },
            progress: [],
            history,
        },
        global: {
            mocks: { route: globalThis.route },
            stubs: { AuthenticatedLayout: passesSlot, GlassCard: passesSlot },
        },
    })

describe('the series feeding the charts', () => {
    it('reads oldest first, whichever way the server sent it', () => {
        const wrapper = mountPage([
            session('03/02/2026', [{ weight: 100, reps: 5 }]),
            session('01/02/2026', [{ weight: 80, reps: 5 }]),
        ])

        // The server sends newest first. Drawn in that order a progression
        // reads as a decline — the one mistake nobody would notice from the
        // shape of the curve alone.
        expect(wrapper.vm.volumeData.map((point) => point.volume)).toEqual([400, 500])
    })

    it('drops the year from the axis labels', () => {
        const wrapper = mountPage([session('03/02/2026', [{ weight: 100, reps: 5 }])])

        expect(wrapper.vm.volumeData[0].date).toBe('03/02')
    })

    it('counts a set with no weight as nothing rather than as NaN', () => {
        const wrapper = mountPage([
            session('01/02/2026', [
                // No `weight` key at all, which is what the API sends for a
                // bodyweight set. `null` would coerce to 0 on its own; only a
                // missing key reaches the fallback, and without it the
                // multiplication yields NaN and takes the whole session's
                // volume — and its point on the chart — with it.
                { reps: 12 },
                { weight: 60, reps: 10 },
            ]),
        ])

        expect(wrapper.vm.volumeData[0].volume).toBe(600)
    })

    it('reports zero, not minus infinity, for a session with no sets', () => {
        const wrapper = mountPage([session('01/02/2026', [])])

        // Math.max of nothing is -Infinity, which drags every axis on the page
        // down with it.
        expect(wrapper.vm.maxRepsData[0].reps).toBe(0)
        expect(wrapper.vm.maxWeightData[0].weight).toBe(0)
        expect(wrapper.vm.setsPerSessionData[0].sets).toBe(0)
    })

    it('leaves bodyweight sets out of the average load', () => {
        const wrapper = mountPage([
            session('01/02/2026', [
                { weight: 0, reps: 15 },
                { weight: 80, reps: 5 },
                { weight: 100, reps: 3 },
            ]),
        ])

        // Averaging the zeros in would report 60 kg for a session whose real
        // loaded average was 90.
        expect(parseFloat(wrapper.vm.averageWeightData[0].weight)).toBe(90)
    })

    it('adds up every rep of the session, not just the best set', () => {
        const wrapper = mountPage([
            session('01/02/2026', [
                { weight: 60, reps: 10 },
                { weight: 60, reps: 8 },
            ]),
        ])

        expect(wrapper.vm.totalRepsData[0].reps).toBe(18)
    })
})

describe('the weight distribution', () => {
    it('bins by five kilos and keeps the empty bins between', () => {
        const wrapper = mountPage([
            session('01/02/2026', [
                { weight: 60, reps: 5 },
                { weight: 62, reps: 5 },
                { weight: 75, reps: 5 },
            ]),
        ])

        // The gap matters: dropping the empty bins would draw 60 and 75 side by
        // side and hide the fact that nothing was ever lifted in between.
        expect(wrapper.vm.weightDistributionData).toEqual([
            { label: '60', count: 2 },
            { label: '65', count: 0 },
            { label: '70', count: 0 },
            { label: '75', count: 1 },
        ])
    })

    it('draws nothing when no set carries a weight', () => {
        const wrapper = mountPage([])

        expect(wrapper.vm.weightDistributionData).toEqual([])
    })
})

describe('the weight-against-reps scatter', () => {
    it('keeps only the sets that have both numbers', () => {
        const wrapper = mountPage([
            session('01/02/2026', [
                { weight: 100, reps: 5 },
                { weight: 0, reps: 15 },
                { weight: 80, reps: null },
            ]),
        ])

        // A point at x=0 or y=0 is not a lift; plotted it pulls the trend line
        // towards an origin no one trained at.
        expect(wrapper.vm.scatterData).toEqual([{ x: 100, y: 5 }])
    })
})
