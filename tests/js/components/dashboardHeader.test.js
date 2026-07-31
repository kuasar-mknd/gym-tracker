import { describe, it, expect, beforeAll } from 'vitest'
import { mount } from '@vue/test-utils'

import DashboardHeader from '@/Components/Dashboard/DashboardHeader.vue'

beforeAll(() => {
    globalThis.route = (name) => `/${name}`
})

const mountHeader = (latestWeight) =>
    mount(DashboardHeader, {
        props: {
            user: { name: 'Samuel Dulex', current_streak: 4 },
            latestWeight,
        },
        global: {
            // The template calls route() directly, so it has to resolve through
            // the component instance, not just on globalThis.
            mocks: { route: globalThis.route },
            stubs: {
                Link: { template: '<a :href="href"><slot /></a>', props: ['href'] },
            },
        },
    })

/**
 * The controller has always sent latestWeight and the dashboard never rendered
 * it: the body-metrics query ran on every page load to fill a prop nothing
 * read. These follow the figure the user actually sees.
 */
describe('DashboardHeader body weight', () => {
    it('shows the latest weight it was given', () => {
        expect(mountHeader('75.50').text()).toContain('75,5 kg')
    })

    it('drops the trailing zeros the decimal column carries', () => {
        // The column is a decimal cast, so it arrives as "80.00". Rendered raw
        // that reads as a precision the scale never measured.
        expect(mountHeader('80.00').text()).toContain('80 kg')
    })

    it('uses the separator every other figure on the page uses', () => {
        expect(mountHeader('72.4').text()).not.toContain('72.4')
    })

    it('says nothing at all when no weight has been recorded', () => {
        const header = mountHeader(null)

        expect(header.find('[dusk="dashboard-latest-weight"]').exists()).toBe(false)
        expect(header.text()).not.toContain('kg')
    })

    it('leads to the measurements page, where the figure can be changed', () => {
        expect(mountHeader('75.50').find('[dusk="dashboard-latest-weight"]').attributes('href')).toBe(
            '/body-measurements.index',
        )
    })
})
