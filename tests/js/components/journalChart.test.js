import { describe, it, expect, vi, beforeAll, afterAll } from 'vitest'
import { mount } from '@vue/test-utils'

/**
 * Same substitution as chartAxes.test.js: the real vue-chartjs reaches for a
 * canvas jsdom never draws, and what matters here is the series handed to the
 * chart — the labels, the values, and the pairing between the two.
 */
vi.mock('vue-chartjs', () => ({
    Line: {
        name: 'Line',
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    },
}))

const JournalChart = (await import('@/Components/Stats/JournalChart.vue')).default

const line = (wrapper) => wrapper.findComponent({ name: 'Line' })

/** Deliberately out of order: the component is what must put them back in order. */
const entries = [
    { date: '2026-03-05', mood_score: 2, energy_level: 9 },
    { date: '2026-01-12', mood_score: 5, energy_level: 3 },
    { date: '2026-02-20', mood_score: 4, energy_level: 7 },
]

const buttonLabelled = (wrapper, label) => wrapper.findAll('button').find((b) => b.text() === label)

/**
 * Une entrée de journal est un jour saisi, pas un instant. Lu dans un fuseau en
 * retard sur UTC — tout le continent américain — `new Date('2026-03-05')` est
 * le 4 mars à 19 h, et l’axe date chaque entrée de la veille. Le fuseau est
 * forcé pour ce fichier, sans quoi la garde ci-dessous serait aveugle depuis
 * l’Europe, où le reste de la suite tourne.
 */
const originalTimezone = process.env.TZ

beforeAll(() => {
    process.env.TZ = 'America/New_York'
})

afterAll(() => {
    if (originalTimezone === undefined) {
        delete process.env.TZ
    } else {
        process.env.TZ = originalTimezone
    }
})

describe('JournalChart', () => {
    it('remet les entrées dans l’ordre chronologique', () => {
        const wrapper = mount(JournalChart, { props: { data: entries } })

        expect(line(wrapper).props('data').labels).toEqual(['12 janv.', '20 févr.', '5 mars'])
    })

    it('garde chaque valeur en face de sa date', () => {
        const wrapper = mount(JournalChart, { props: { data: entries } })

        // Le tri porte sur les labels ET sur les valeurs : trier l’un sans
        // l’autre trace la courbe de janvier au-dessus du 5 mars.
        expect(line(wrapper).props('data').datasets[0].data).toEqual([5, 4, 2])
    })

    it('lit la date comme un jour calendaire, pas comme un instant UTC', () => {
        // `new Date('2026-03-05')` est minuit UTC : rendu par toLocaleDateString
        // il affiche le 4 mars partout où le navigateur est en retard sur UTC.
        const wrapper = mount(JournalChart, { props: { data: [{ date: '2026-03-05', mood_score: 3 }] } })

        expect(line(wrapper).props('data').labels).toEqual(['5 mars'])
    })

    it('ne réordonne pas le tableau reçu du parent', () => {
        const data = [...entries]

        mount(JournalChart, { props: { data } })

        expect(data.map((e) => e.date)).toEqual(['2026-03-05', '2026-01-12', '2026-02-20'])
    })

    it('trace l’humeur par défaut', () => {
        const wrapper = mount(JournalChart, { props: { data: entries } })

        expect(line(wrapper).props('data').datasets[0].label).toBe('Humeur')
        expect(buttonLabelled(wrapper, 'Humeur').attributes('aria-pressed')).toBe('true')
        expect(buttonLabelled(wrapper, 'Énergie').attributes('aria-pressed')).toBe('false')
    })

    it('change de série quand on sélectionne une autre métrique', async () => {
        const wrapper = mount(JournalChart, { props: { data: entries } })

        await buttonLabelled(wrapper, 'Énergie').trigger('click')

        const dataset = line(wrapper).props('data').datasets[0]
        expect(dataset.label).toBe('Énergie')
        expect(dataset.data).toEqual([3, 7, 9])
        expect(dataset.borderColor).toBe('#FACC15')
        expect(buttonLabelled(wrapper, 'Énergie').attributes('aria-pressed')).toBe('true')
        expect(buttonLabelled(wrapper, 'Humeur').attributes('aria-pressed')).toBe('false')
    })

    it('cadre l’axe sur l’échelle de la métrique', async () => {
        const wrapper = mount(JournalChart, { props: { data: entries } })

        // Humeur est notée sur 5 : monter l’axe à 10 écrase la courbe sur la
        // moitié basse du graphique.
        expect(line(wrapper).props('options').scales.y.suggestedMax).toBe(5)

        await buttonLabelled(wrapper, 'Énergie').trigger('click')

        expect(line(wrapper).props('options').scales.y.suggestedMax).toBe(10)
    })

    it('affiche l’unité de la métrique dans l’infobulle', async () => {
        const wrapper = mount(JournalChart, { props: { data: entries } })
        const label = () =>
            line(wrapper)
                .props('options')
                .plugins.tooltip.callbacks.label({ parsed: { y: 4 } })

        expect(label()).toBe('4 /5')

        await buttonLabelled(wrapper, 'Stress').trigger('click')

        expect(label()).toBe('4 /10')
    })

    it('remplace le graphique par un message quand il n’y a rien à tracer', () => {
        const wrapper = mount(JournalChart, { props: { data: [] } })

        expect(line(wrapper).exists()).toBe(false)
        expect(wrapper.text()).toContain('Pas assez de données pour afficher le graphique')
    })

    it('trace la courbe dès la première entrée', () => {
        const wrapper = mount(JournalChart, { props: { data: [{ date: '2026-01-12', mood_score: 5 }] } })

        expect(line(wrapper).exists()).toBe(true)
        expect(line(wrapper).props('data').datasets[0].data).toEqual([5])
    })
})
