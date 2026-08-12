import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('@inertiajs/vue3', () => ({
    Deferred: {
        name: 'Deferred',
        props: { data: { type: [String, Array], default: null } },
        template: '<div><slot /></div>',
    },
}))

vi.mock('vue-chartjs', () => ({
    Bar: { name: 'Bar', props: { data: null, options: null }, template: '<div />' },
}))

/*
 * The chart is a defineAsyncComponent behind a dynamic import. Loaded here
 * first, the card's own import resolves from the module cache within the tick
 * flushPromises awaits — otherwise the slot is still a comment node and every
 * assertion about the chart would silently pass on nothing.
 */
await import('@/Components/Stats/VolumeTrendChart.vue')

const VolumeTrendCard = (await import('@/Components/Stats/VolumeTrendCard.vue')).default

const mountCard = async (props = {}) => {
    const wrapper = mount(VolumeTrendCard, { props })

    await flushPromises()

    return wrapper
}

/** Le total en gros, en haut à droite de la carte. */
const total = (wrapper) => wrapper.find('div.text-electric-orange').text().replace(/\s+/g, ' ')

const digitsOf = (text) => text.replace(/\D/g, '')

/** Le graphique lui-même, pas le trou qu'il laisse dans le DOM. */
const chart = (wrapper) => wrapper.findComponent({ name: 'VolumeTrendChart' })

const trend = [
    { date: '01/07', volume: 4000 },
    { date: '08/07', volume: 3500 },
    { date: '15/07', volume: 5000 },
]

describe('VolumeTrendCard', () => {
    it('totalise le volume de la période affichée', async () => {
        const wrapper = await mountCard({ volumeTrend: trend, currentPeriod: '30j' })

        // Le séparateur de milliers suit la locale du poste ; le total, lui,
        // doit être la somme des trois points et pas le dernier.
        expect(digitsOf(total(wrapper))).toBe('12500')
        expect(total(wrapper)).toContain('kg')
    })

    it('affiche zéro tant qu’aucune période n’est chargée', async () => {
        expect(total(await mountCard({}))).toContain('0 kg')
    })

    it('affiche zéro plutôt que « NaN » sur une période vide', async () => {
        expect(total(await mountCard({ volumeTrend: [] }))).toContain('0 kg')
    })

    it('annonce la longueur de la période sélectionnée', async () => {
        const daysFor = async (period) => (await mountCard({ currentPeriod: period, volumeTrend: trend })).text()

        expect(await daysFor('7j')).toContain('7 derniers jours')
        expect(await daysFor('30j')).toContain('30 derniers jours')
        expect(await daysFor('90j')).toContain('90 derniers jours')
    })

    it('retombe sur l’année pour toute autre période', async () => {
        expect((await mountCard({ currentPeriod: '1an', volumeTrend: trend })).text()).toContain('365 derniers jours')
        expect((await mountCard({ volumeTrend: trend })).text()).toContain('365 derniers jours')
    })

    it('remplace le graphique par un message quand la période est vide', async () => {
        const empty = await mountCard({ volumeTrend: [] })
        const missing = await mountCard({})

        // Le message seul ne prouve rien : un graphique tracé à côté de lui
        // laisserait le texte intact. C'est son absence qui est la promesse.
        expect(chart(empty).exists()).toBe(false)
        expect(empty.text()).toContain('Pas encore de données de volume')
        expect(chart(missing).exists()).toBe(false)
        expect(missing.text()).toContain('Pas encore de données de volume')
    })

    it('trace le graphique dès le premier point, avec la série reçue', async () => {
        const onePoint = [{ date: '01/07', volume: 4000 }]
        const wrapper = await mountCard({ volumeTrend: onePoint })

        // Le graphique doit être monté — pas seulement l'état vide absent — et
        // recevoir la série telle quelle : c'est la carte qui la lui transmet.
        expect(chart(wrapper).exists()).toBe(true)
        expect(chart(wrapper).props('data')).toEqual(onePoint)
        expect(wrapper.text()).not.toContain('Pas encore de données de volume')
    })

    it('transmet toute la période au graphique, sans la tronquer', async () => {
        const wrapper = await mountCard({ volumeTrend: trend, currentPeriod: '30j' })

        expect(chart(wrapper).props('data')).toEqual(trend)
    })

    it('attend la clé différée de la page', async () => {
        expect((await mountCard({})).findComponent({ name: 'Deferred' }).props('data')).toBe('performanceStats')
    })

    it('attend la clé groupée quand la page en fournit une', async () => {
        expect((await mountCard({ deferredData: {} })).findComponent({ name: 'Deferred' }).props('data')).toBe(
            'deferredData',
        )
    })
})
