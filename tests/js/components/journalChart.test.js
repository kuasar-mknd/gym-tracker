import { describe, it, expect, vi, beforeAll, afterAll } from 'vitest'
import { jeton } from '@/Utils/couleurs'
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

        expect(line(wrapper).props('data').labels).toEqual(['12/01', '20/02', '05/03'])
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

        expect(line(wrapper).props('data').labels).toEqual(['05/03'])
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
        expect(dataset.borderColor).toBe(jeton('palette-ambre'))
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

/**
 * Le libellé du filtre actif doit se lire sur sa propre couleur.
 *
 * Le bouton sélectionné prend la couleur de sa courbe en fond, et prenait aussi
 * `text-surface-card`. Les sept teintes sont les `-400` de Tailwind, choisies pour être
 * lisibles comme *courbes sur fond sombre* et jamais comme *fond sous du texte
 * blanc* : les sept échouaient au seuil AA, « Énergie » à 1,53:1 — du blanc sur
 * jaune, le mot disparaissait. Le seul libellé illisible des sept était celui du
 * bouton chargé de dire quelle métrique on regarde (#1400).
 *
 * Le rapport est calculé ici plutôt que la classe comparée à une valeur
 * attendue : une assertion sur `text-text-main` passerait encore si quelqu'un
 * ajoutait une huitième métrique dans une teinte trop sombre, ce qui est
 * exactement le cas que ce test existe pour attraper.
 *
 * Le texte fait 12 px en gras, ce qui n'est pas du « grand texte » au sens WCAG
 * 2.1 : le seuil est 4,5:1, pas 3:1.
 */
describe('lisibilité du filtre actif', () => {
    const CHANNEL = (value) => (value <= 0.03928 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4)

    const luminance = (hex) => {
        const [r, g, b] = [1, 3, 5].map((i) => CHANNEL(parseInt(hex.slice(i, i + 2), 16) / 255))

        return 0.2126 * r + 0.7152 * g + 0.0722 * b
    }

    const contrast = (a, b) => {
        const [x, y] = [luminance(a), luminance(b)]

        return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05)
    }

    /** Les classes Tailwind ne sont pas résolues sous jsdom ; la teinte est lue ici. */
    const TEXT_COLOURS = {
        'text-text-main': jeton('text-main'),
        'text-text-on-dark-accent': jeton('text-on-dark-accent'),
    }

    it('tient le seuil AA pour chacune des sept métriques', async () => {
        const wrapper = mount(JournalChart, { props: { data: entries } })
        const measured = []

        for (const button of wrapper.findAll('button')) {
            await button.trigger('click')

            const active = wrapper.findAll('button').find((b) => b.attributes('aria-pressed') === 'true')
            const background = active
                .attributes('style')
                .match(/background-color:\s*([^;]+)/)[1]
                .trim()
            const named = Object.keys(TEXT_COLOURS).find((name) => active.classes().includes(name))

            // Une couleur de fond posée en style inline arrive en rgb() sous jsdom.
            const [r, g, b] = background.match(/\d+/g).map(Number)
            const hex = '#' + [r, g, b].map((c) => c.toString(16).padStart(2, '0')).join('')

            measured.push({ label: active.text(), ratio: contrast(hex, TEXT_COLOURS[named]) })
        }

        expect(measured).toHaveLength(7)

        const failing = measured.filter(({ ratio }) => ratio < 4.5)

        expect(failing.map(({ label, ratio }) => `${label} : ${ratio.toFixed(2)}:1`)).toEqual([])
    })
})
