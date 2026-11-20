import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/**
 * `Deferred` est remplacé plutôt que stubé : il attend une page Inertia, et ce
 * qui compte ici est la clé qu’il reçoit — c’est elle qui décide si la carte
 * sait un jour que ses données sont arrivées.
 */
vi.mock('@inertiajs/vue3', () => ({
    Deferred: {
        name: 'Deferred',
        props: { data: { type: [String, Array], default: null } },
        template: '<div><slot /></div>',
    },
}))

const MonthlyVolumeCard = (await import('@/Components/Stats/MonthlyVolumeCard.vue')).default

const mountCard = async (props = {}) => {
    const wrapper = mount(MonthlyVolumeCard, { props })

    await flushPromises()

    return wrapper
}

/** Le badge de tendance, seul élément à la fois coloré et fléché. */
const trendBadge = (wrapper) => wrapper.find('div.rounded-lg.px-2')

/** Le grand nombre de la carte. */
const reading = (wrapper) => wrapper.find('p.font-display').text().replace(/\s+/g, ' ')

const digitsOf = (text) => text.replace(/\D/g, '')

describe('MonthlyVolumeCard', () => {
    it('arrondit le volume du mois au kilo', async () => {
        const wrapper = await mountCard({ monthlyComparison: { current_volume: 12499.6, percentage: 0 } })

        // Le séparateur de milliers dépend de la locale du poste ; le nombre,
        // lui, doit être 12 500 et pas 12 499,6.
        expect(digitsOf(reading(wrapper))).toBe('12500')
        expect(reading(wrapper)).toContain('kg')
    })

    it('affiche zéro kilo quand le mois n’a pas encore de volume', async () => {
        expect(reading(await mountCard({}))).toBe('0 kg')
    })

    it('pointe la flèche vers le haut sur une progression', async () => {
        const wrapper = await mountCard({
            monthlyComparison: { current_volume: 1000, previous_volume: 900, percentage: 12 },
        })

        expect(wrapper.findAll('span.material-symbols-outlined').at(-1).text()).toBe('trending_up')
        expect(trendBadge(wrapper).text().replace(/\s+/g, ' ')).toContain('+12 %')
        expect(trendBadge(wrapper).classes()).toContain('text-trend-up')
    })

    it('pointe la flèche vers le bas sur un recul, sans signe plus', async () => {
        const wrapper = await mountCard({
            monthlyComparison: { current_volume: 1000, previous_volume: 1100, percentage: -8 },
        })

        expect(wrapper.findAll('span.material-symbols-outlined').at(-1).text()).toBe('trending_down')
        const badge = trendBadge(wrapper).text().replace(/\s+/g, ' ')
        expect(badge).toContain('-8 %')
        expect(badge).not.toContain('+')
        expect(trendBadge(wrapper).classes()).toContain('text-trend-down')
    })

    it('traite un mois identique comme une variation nulle : sans flèche, sans couleur de tendance', async () => {
        const wrapper = await mountCard({
            monthlyComparison: { current_volume: 1000, previous_volume: 1000, percentage: 0 },
        })

        expect(trendBadge(wrapper).text().replace(/\s+/g, ' ')).toContain('0 %')
        expect(trendBadge(wrapper).find('span.material-symbols-outlined').exists()).toBe(false)
        expect(trendBadge(wrapper).classes()).not.toContain('text-trend-up')
        expect(trendBadge(wrapper).classes()).not.toContain('text-trend-down')
    })

    it('ne dit pas « 0 % » quand le mois précédent est vide : rien à comparer', async () => {
        const wrapper = await mountCard({
            monthlyComparison: { current_volume: 1000, previous_volume: 0, percentage: null },
        })

        expect(trendBadge(wrapper).exists()).toBe(false)
    })

    it('garde la pastille sur une seule ligne', async () => {
        const wrapper = await mountCard({
            monthlyComparison: { current_volume: 1000, previous_volume: 900, percentage: 12 },
        })

        expect(trendBadge(wrapper).classes()).toContain('whitespace-nowrap')
    })

    it('attend la clé groupée de la page, même quand elle n’est pas encore arrivée', async () => {
        const keys = (await mountCard({})).findAllComponents({ name: 'Deferred' }).map((d) => d.props('data'))

        expect(keys).toEqual(['deferredData'])
    })
})
