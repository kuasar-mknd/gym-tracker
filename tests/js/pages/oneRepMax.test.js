import { describe, it, expect, vi, beforeAll } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    router: { get: vi.fn(), post: vi.fn(), delete: vi.fn() },
    useForm: (data) => ({ ...data, processing: false, errors: {}, post: vi.fn(), reset: vi.fn() }),
}))

import OneRepMax from '@/Pages/Tools/OneRepMax.vue'
import { passesSlot } from './pageStubs'

beforeAll(() => {
    globalThis.route = (name) => `/${name}`
})

/**
 * The Epley formula lives in Utils/formulas and is tested there. What this page
 * adds on top is a percentage table and a rounding rule, and both are the parts
 * a user reads off the screen and loads onto a bar — a row that is one notch
 * out is a real set at the wrong weight.
 */
const mountPage = () =>
    mount(OneRepMax, {
        global: {
            mocks: { route: globalThis.route },
            stubs: { AuthenticatedLayout: passesSlot, GlassCard: passesSlot },
        },
    })

const enter = async (wrapper, weight, reps) => {
    await wrapper.find('#orm-weight').setValue(weight)
    await wrapper.find('#orm-reps').setValue(reps)
}

/** The percentage table, as three columns of text per row. */
const rows = (wrapper) => wrapper.findAll('tbody tr').map((row) => row.findAll('td').map((cell) => cell.text()))

describe('the one-rep-max table', () => {
    it('draws nothing at all until both fields are filled', async () => {
        const wrapper = mountPage()

        expect(rows(wrapper)).toHaveLength(0)

        await enter(wrapper, 100, '')

        // A weight on its own is not an estimate, and a table of zeroes would
        // read as one.
        expect(rows(wrapper)).toHaveLength(0)
    })

    it('puts the estimate itself on the 100 % row', async () => {
        const wrapper = mountPage()

        await enter(wrapper, 100, 5)

        // Epley: 100 × (1 + 5/30) = 116.66…, rounded to one decimal for display.
        expect(rows(wrapper)[0]).toEqual(['100%', '116.7 kg', '1'])
    })

    it('drops the decimal when there is nothing after it', async () => {
        const wrapper = mountPage()

        // A single rep is the lift itself, so the row is a round number and
        // "100.0 kg" would be noise.
        await enter(wrapper, 100, 1)

        expect(rows(wrapper)[0]).toEqual(['100%', '100 kg', '1'])
    })

    it('scales every row off the same estimate', async () => {
        const wrapper = mountPage()

        await enter(wrapper, 120, 1)

        const table = rows(wrapper)

        expect(table).toHaveLength(11)
        expect(table[0][1]).toBe('120 kg')
        expect(table.at(-1)).toEqual(['50%', '60 kg', '30+'])
    })

    it('pairs each percentage with the reps it is worth', async () => {
        const wrapper = mountPage()

        await enter(wrapper, 100, 1)

        // The mapping is the point of the table: a lifter picks a row by the
        // rep count, not by the percentage.
        expect(rows(wrapper).map(([percent, , reps]) => [percent, reps])).toEqual([
            ['100%', '1'],
            ['95%', '2'],
            ['90%', '4'],
            ['85%', '6'],
            ['80%', '8'],
            ['75%', '10'],
            ['70%', '12'],
            ['65%', '15'],
            ['60%', '20'],
            ['55%', '25+'],
            ['50%', '30+'],
        ])
    })

    it('refuses a negative load rather than inverting the table', async () => {
        const wrapper = mountPage()

        await enter(wrapper, -100, 5)

        expect(rows(wrapper)).toHaveLength(0)
    })
})
