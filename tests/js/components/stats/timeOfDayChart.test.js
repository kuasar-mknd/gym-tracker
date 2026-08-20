import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'

vi.mock('vue-chartjs', () => {
    const recorder = (name) => ({
        name,
        props: { data: { type: Object, default: null }, options: { type: Object, default: null } },
        template: '<div />',
    })

    return { Line: recorder('Line'), Bar: recorder('Bar'), Doughnut: recorder('Doughnut') }
})

const TimeOfDayChart = (await import('@/Components/Stats/TimeOfDayChart.vue')).default

const mountChart = (data) => mount(TimeOfDayChart, { props: { data } })
const chartOf = (data) => mountChart(data).findComponent({ name: 'Doughnut' })

const arc = (value, total) => ({ raw: value, datasetIndex: 0, chart: { _metasets: [{ total }] } })

const slots = [
    { label: 'Matin', count: 8 },
    { label: 'Après-midi', count: 4 },
    { label: 'Soir', count: 12 },
    { label: 'Nuit', count: 0 },
]

describe('TimeOfDayChart', () => {
    it('compte les séances par moment de la journée, dans l’ordre reçu', () => {
        const chart = chartOf(slots)

        expect(chart.props('data').labels).toEqual(['Matin', 'Après-midi', 'Soir', 'Nuit'])
        expect(chart.props('data').datasets[0].data).toEqual([8, 4, 12, 0])
    })

    it('ne découpe rien sans séance', () => {
        const data = chartOf([]).props('data')

        expect(data.labels).toEqual([])
        expect(data.datasets[0].data).toEqual([])
    })

    /**
     * The palette is positional — sky, amber, violet, indigo for morning to
     * night — so it only reads correctly while the four slots arrive in
     * chronological order, and it must stay long enough to cover all four.
     */
    it('garde une couleur par créneau, du matin à la nuit', () => {
        const dataset = chartOf(slots).props('data').datasets[0]

        expect(dataset.backgroundColor).toHaveLength(4)
        expect(dataset.backgroundColor[0]).toBe('#38BDF8')
        expect(dataset.backgroundColor[3]).toBe('#312E81')
        expect(dataset.hoverBackgroundColor).toHaveLength(4)
    })

    describe('survol', () => {
        const tooltip = () => chartOf(slots).props('options').plugins.tooltip.callbacks.label

        it('annonce le nombre de séances et sa part du total', () => {
            expect(tooltip()(arc(8, 32))).toBe(' 8 séances (25%)')
        })

        it('arrondit la part au point le plus proche', () => {
            // 2 séances sur 3 se lisent 67 %, pas 66 % : tronquer perd le point
            // qui manque, et les quatre créneaux n'additionnent plus 100.
            expect(tooltip()(arc(1, 3))).toBe(' 1 séances (33%)')
            expect(tooltip()(arc(2, 3))).toBe(' 2 séances (67%)')
        })

        it('annonce la totalité pour un créneau seul', () => {
            expect(tooltip()(arc(5, 5))).toBe(' 5 séances (100%)')
        })
    })

    it('pose l’horloge au centre de l’anneau, hors du champ de la souris', () => {
        // L'icône est décorative : elle est posée par-dessus le canvas, donc
        // elle doit rester invisible aux lecteurs d'écran comme au survol,
        // sans quoi elle avale les infobulles du quart gauche de l'anneau.
        const icon = mountChart(slots).find('.material-symbols-outlined')

        expect(icon.text()).toBe('schedule')
        expect(icon.attributes('aria-hidden')).toBe('true')
        expect(icon.element.parentElement.className).toContain('pointer-events-none')
    })

    /*
     * L'icône était posée en `absolute inset-0` avec un `-ml-[120px]` codé en
     * dur, pour compenser une légende de droite dont la largeur dépendait des
     * étiquettes. Mesuré dans le navigateur, elle tombait à 41 px du vrai
     * centre ; elle y est maintenant à 3 px près (#1316).
     */
    it('ne compense plus la légende avec une marge codée en dur', () => {
        const overlay = mountChart(slots).find('.material-symbols-outlined').element.parentElement

        expect(overlay.className).not.toMatch(/-?ml-\[/)
        expect(overlay.className).not.toContain('inset-0')
    })

    /*
     * Tant que Chart.js n'a pas tracé, il n'y a pas de centre à demander. Le
     * repli au milieu du conteneur evite que l'icone n'existe pas du tout —
     * ce qui serait pire que legerement decalee.
     */
    it('retombe au centre du conteneur tant que le graphique n’a pas tracé', () => {
        const overlay = mountChart(slots).find('.material-symbols-outlined').element.parentElement

        expect(overlay.style.left).toBe('50%')
        expect(overlay.style.top).toBe('50%')
        expect(overlay.className).toContain('-translate-x-1/2')
    })

    it('prend les options communes aux anneaux du tableau de bord', () => {
        // La légende à droite était la cause de l'inégalité des deux cercles.
        expect(chartOf(slots).props('options').plugins.legend.position).toBe('bottom')
    })
})
