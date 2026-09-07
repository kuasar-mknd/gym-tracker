import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

// See durationSection.test.js: the async chart must already be in the module
// cache, otherwise it stays a comment node for the whole test.
await import('@/Components/Stats/RecentPRsChart.vue')

const RecentPRs = (await import('@/Components/Dashboard/RecentPRs.vue')).default

const mountSection = async (recentPRs) => {
    const wrapper = mount(RecentPRs, { props: { recentPRs }, global: { directives: { press: {} } } })

    await flushPromises()

    return wrapper
}

/** One card per record, in the order the dashboard sent them. */
const cards = (wrapper) => wrapper.findAllComponents({ name: 'GlassCard' })

describe('RecentPRs', () => {
    it('lists every record with its exercise, its kind and its value', async () => {
        const wrapper = await mountSection([
            { id: 1, type: 'max_weight', value: 120, exercise: { name: 'Développé couché' } },
            { id: 2, type: 'max_1rm', value: 137.5, exercise: { name: 'Squat' } },
        ])

        expect(cards(wrapper)).toHaveLength(2)
        expect(cards(wrapper)[0].text()).toContain('Développé couché')
        expect(cards(wrapper)[0].text()).toContain('Poids Max')
        expect(cards(wrapper)[0].text()).toContain('120 kg')
        expect(cards(wrapper)[1].text()).toContain('Squat')
        expect(cards(wrapper)[1].text()).toContain('1RM Estimé')
        expect(cards(wrapper)[1].text()).toContain('137,5 kg')
    })

    it('dit en kilos le volume d’une série, qui est un poids soulevé et non un compte', async () => {
        /*
         * `max_volume_set` vaut poids × répétitions (PersonalRecordService, la
         * requête des trois classements) : c'est bien une charge, en kilos. La
         * carte n'affichait aucune unité, et le graphique voisin l'annonçait en
         * répétitions — deux lectures fausses de la même donnée.
         */
        const wrapper = await mountSection([
            { id: 3, type: 'max_volume_set', value: 1200, exercise: { name: 'Rowing' } },
        ])

        expect(cards(wrapper)[0].text()).toContain('Volume')
        expect(cards(wrapper)[0].text()).toContain("1'200 kg")
    })

    it('disappears entirely rather than showing an empty podium', async () => {
        const wrapper = await mountSection([])

        expect(wrapper.find('section').exists()).toBe(false)
        expect(cards(wrapper)).toHaveLength(0)
    })

    it('survives a record whose exercise was deleted under it', async () => {
        const wrapper = await mountSection([{ id: 4, type: 'max_weight', value: 60, exercise: null }])

        expect(cards(wrapper)).toHaveLength(1)
        expect(cards(wrapper)[0].text()).toContain('60 kg')
    })

    it('feeds the same records to the chart it lists below it', async () => {
        const recentPRs = [{ id: 1, type: 'max_weight', value: 120, exercise: { name: 'Développé couché' } }]

        const wrapper = await mountSection(recentPRs)

        const chart = wrapper.findComponent({ name: 'RecentPRsChart' })

        expect(chart.exists()).toBe(true)
        expect(chart.props('data')).toEqual(recentPRs)
    })
})
