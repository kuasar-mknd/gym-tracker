import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

// See durationSection.test.js: the async chart has to be in the module cache
// before the section is imported, or it never renders and the assertions lie.
await import('@/Components/Stats/WeeklyVolumeChart.vue')

const WeeklyVolumeSection = (await import('@/Components/Dashboard/WeeklyVolumeSection.vue')).default

const trend = [
    { week: '2026-W30', volume: 18000 },
    { week: '2026-W31', volume: 21500 },
]

const mountSection = async (props) => {
    const wrapper = mount(WeeklyVolumeSection, {
        props: { weeklyVolumeStats: {}, weeklyVolumeTrend: trend, ...props },
    })

    await flushPromises()

    return wrapper
}

const chartOf = (wrapper) => wrapper.findComponent({ name: 'WeeklyVolumeChart' })

/** The big number, read the way the user reads it. */
const volumeText = (wrapper) => wrapper.get('p.text-4xl').text()

/** The "+12% vs sem. passée" line, absent altogether when the weeks tie. */
const comparisonOf = (wrapper) => wrapper.get('p.justify-end')

describe('WeeklyVolumeSection', () => {
    it('groups the thousands in the week volume instead of printing a raw integer', async () => {
        const wrapper = await mountSection({ weeklyVolumeStats: { current_week_volume: 21500, percentage: 0 } })

        expect(volumeText(wrapper)).toBe((21500).toLocaleString())
        expect(volumeText(wrapper)).not.toBe('21500')
    })

    it('falls back to zero when the week has no volume yet', async () => {
        const wrapper = await mountSection({ weeklyVolumeStats: {} })

        expect(volumeText(wrapper)).toBe('0')
    })

    it('reads a gain as a rise, signed and pointing up', async () => {
        const wrapper = await mountSection({ weeklyVolumeStats: { current_week_volume: 21500, percentage: 12 } })

        const comparison = comparisonOf(wrapper)

        expect(comparison.text()).toContain('trending_up')
        expect(comparison.text()).toContain('+12% vs sem. passée')
        expect(comparison.classes()).toContain('text-trend-up')
    })

    it('reads a drop as a fall, unsigned by us and pointing down', async () => {
        // The minus already comes with the number; prefixing a '+' here would
        // print '+-8%'.
        const wrapper = await mountSection({ weeklyVolumeStats: { current_week_volume: 9000, percentage: -8 } })

        const comparison = comparisonOf(wrapper)

        expect(comparison.text()).toContain('trending_down')
        expect(comparison.text()).toContain('-8% vs sem. passée')
        expect(comparison.text()).not.toContain('+')
        expect(comparison.classes()).toContain('text-trend-down')
    })

    /**
     * Trois situations, et la condition n'en distinguait que deux — dans le
     * mauvais sens. Elle n'ecartait que la valeur 0, donc :
     *
     * - deux semaines identiques ne disaient rien, alors qu'elles ont quelque
     *   chose a dire : le volume n'a pas bouge ;
     * - une comparaison absente passait le test et affichait la ligne avec un
     *   pourcentage vide.
     *
     * Le serveur envoie desormais null quand il n'y a pas de semaine precedente,
     * ce qui separe « pas de base » de « variation nulle » (#1388).
     */
    it('dit que le volume est stable quand les deux semaines se valent', async () => {
        const wrapper = await mountSection({ weeklyVolumeStats: { current_week_volume: 21500, percentage: 0 } })

        const comparison = comparisonOf(wrapper)

        expect(comparison.text()).toContain('Stable vs sem. passée')
        expect(comparison.text()).toContain('trending_flat')
        expect(comparison.classes()).not.toContain('text-trend-down')
        expect(comparison.classes()).not.toContain('text-trend-up')
    })

    it("ne compare rien quand il n'y a pas de semaine precedente", async () => {
        const wrapper = await mountSection({
            weeklyVolumeStats: { current_week_volume: 21500, percentage: null },
        })

        expect(wrapper.find('p.justify-end').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('vs sem. passée')
        // Le volume de la semaine, lui, reste affiche : il est mesure, pas compare.
        expect(volumeText(wrapper)).toBe((21500).toLocaleString())
    })

    it('ne compare rien non plus quand la cle manque tout court', async () => {
        const wrapper = await mountSection({ weeklyVolumeStats: { current_week_volume: 21500 } })

        expect(wrapper.find('p.justify-end').exists()).toBe(false)
        expect(wrapper.text()).not.toContain('%')
    })

    it('passes the trend on to the chart', async () => {
        const wrapper = await mountSection({ weeklyVolumeTrend: trend })

        expect(chartOf(wrapper).exists()).toBe(true)
        expect(chartOf(wrapper).props('data')).toEqual(trend)
    })

    it('announces the empty week rather than an axis with no points', async () => {
        const wrapper = await mountSection({ weeklyVolumeTrend: [] })

        expect(chartOf(wrapper).exists()).toBe(false)
        expect(wrapper.text()).toContain('Pas de données cette semaine')
    })
})
