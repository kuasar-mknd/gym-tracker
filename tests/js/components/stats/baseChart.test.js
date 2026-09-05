import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { jeton, jetonTransparent } from '@/Utils/couleurs'

vi.mock('vue-chartjs', async () => (await import('../chartRecorder.js')).chartRecorders())

const BaseChart = (await import('@/Components/Stats/BaseChart.vue')).default

const datasets = [{ label: 'Reps', data: [12, 8] }]
const labels = ['lun.', 'mar.']

const monter = (props = {}, slots = {}) => mount(BaseChart, { props: { datasets, labels, ...props }, slots })
const trace = (wrapper, nom = 'Bar') => wrapper.findComponent({ name: nom })
const options = (props = {}, nom = 'Bar') => trace(monter(props), nom).props('options')

describe('BaseChart', () => {
    it('dessine des barres par défaut, avec les étiquettes et les séries reçues', () => {
        const barres = trace(monter())

        expect(barres.exists()).toBe(true)
        expect(barres.props('data')).toEqual({ labels, datasets })
    })

    it.each([
        ['line', 'Line'],
        ['doughnut', 'Doughnut'],
        ['scatter', 'Scatter'],
    ])('choisit le tracé %s', (type, nom) => {
        expect(trace(monter({ type }), nom).exists()).toBe(true)
    })

    it('refuse un type de tracé inconnu', () => {
        const { validator } = BaseChart.props.type

        expect(validator('bar')).toBe(true)
        expect(validator('radar')).toBe(false)
    })

    it('cache la légende sauf demande, et l’habille quand elle est demandée', () => {
        expect(options().plugins.legend).toEqual({ display: false })
        expect(options({ legende: false }).plugins.legend).toEqual({ display: false })

        const visible = options({ legende: true }).plugins.legend
        expect(visible.display).toBe(true)
        expect(visible.position).toBe('bottom')
        expect(visible.labels.color).toBe(jeton('text-muted'))

        const aDroite = options({ legende: { position: 'right', labels: { padding: 4 } } }).plugins.legend
        expect(aDroite.position).toBe('right')
        expect(aDroite.labels.padding).toBe(4)
        expect(aDroite.labels.usePointStyle).toBe(true)
    })

    it('habille l’infobulle du trait de l’accent et garde ses réglages propres', () => {
        const parDefaut = options().plugins.tooltip
        expect(parDefaut.backgroundColor).toBe(jetonTransparent('surface-card', 0.9))
        expect(parDefaut.borderColor).toBe(jetonTransparent('accent-primary', 0.2))
        expect(parDefaut.displayColors).toBe(false)

        const label = (context) => `${context.parsed.y} reps`
        const reglee = options({
            infobulle: { accent: 'accent-info', opaque: true, displayColors: true, callbacks: { label } },
        }).plugins.tooltip
        expect(reglee.backgroundColor).toBe(jetonTransparent('surface-card', 0.95))
        expect(reglee.borderColor).toBe(jetonTransparent('accent-info', 0.2))
        expect(reglee.displayColors).toBe(true)
        expect(reglee.callbacks.label({ parsed: { y: 12 } })).toBe('12 reps')
        expect(reglee).not.toHaveProperty('accent')
        expect(reglee).not.toHaveProperty('opaque')
    })

    it('pose les axes par défaut, les cache ou fusionne leurs réglages', () => {
        const { x, y } = options().scales
        expect(x.grid.display).toBe(false)
        expect(x.ticks.color).toBe(jeton('text-muted'))
        expect(y.beginAtZero).toBe(true)
        expect(y.grid.color).toBe(jetonTransparent('border', 0.6))

        const cache = options({ axeX: false, axeY: false }).scales
        expect(cache.x.display).toBe(false)
        expect(cache.y.display).toBe(false)
        expect(cache.y.beginAtZero).toBe(true)

        const gradue = options({ axeY: { ticks: { stepSize: 1 }, grid: { display: false } } }).scales.y
        expect(gradue.ticks.stepSize).toBe(1)
        expect(gradue.ticks.color).toBe(jeton('text-muted'))
        expect(gradue.grid.display).toBe(false)
        expect(gradue.beginAtZero).toBe(true)
    })

    it('ne force pas le zéro sur une courbe', () => {
        const { y } = options({ type: 'line' }, 'Line').scales

        expect(y.beginAtZero).toBeUndefined()
        expect(y.display).toBe(true)
    })

    it('ajoute un second axe, l’interaction et le sens des barres sur demande', () => {
        const sans = options()
        expect(sans.scales).not.toHaveProperty('y1')
        expect(sans).not.toHaveProperty('interaction')
        expect(sans.indexAxis).toBe('x')

        const avec = options({
            axeY1: { position: 'right', grid: { drawOnChartArea: false } },
            interaction: { mode: 'index', intersect: false },
            indexAxis: 'y',
        })
        expect(avec.scales.y1.position).toBe('right')
        expect(avec.scales.y1.grid.drawOnChartArea).toBe(false)
        expect(avec.scales.y1.ticks.color).toBe(jeton('text-muted'))
        expect(avec.interaction).toEqual({ mode: 'index', intersect: false })
        expect(avec.indexAxis).toBe('y')
    })

    it('laisse les options explicites gagner, branche par branche', () => {
        const fusionnees = options({
            options: { plugins: { legend: { display: true } }, scales: { y: { max: 10 } } },
        })

        expect(fusionnees.plugins.legend.display).toBe(true)
        expect(fusionnees.plugins.tooltip.cornerRadius).toBe(12)
        expect(fusionnees.scales.y.max).toBe(10)
        expect(fusionnees.scales.y.beginAtZero).toBe(true)
    })

    it('donne à un anneau l’habillage commun des anneaux', () => {
        const label = (context) => `${context.parsed} séances`
        const anneau = options({ type: 'doughnut', infobulle: { boxPadding: 8, callbacks: { label } } }, 'Doughnut')

        expect(anneau.cutout).toBe('65%')
        expect(anneau.plugins.legend.display).toBe(false)
        expect(anneau.plugins.tooltip.callbacks.label({ parsed: 3 })).toBe('3 séances')
        expect(anneau.plugins.tooltip.boxPadding).toBe(8)
        expect(anneau).not.toHaveProperty('scales')

        expect(options({ type: 'doughnut', legende: false }, 'Doughnut').plugins.legend.display).toBe(false)
        expect(monter({ type: 'doughnut', legende: false }).find('ul').exists()).toBe(false)
        const ajustee = options({ type: 'doughnut', legende: { position: 'right' } }, 'Doughnut').plugins.legend
        expect(ajustee.position).toBe('right')
        expect(ajustee.labels.usePointStyle).toBe(true)
    })

    it('transmet les greffons au tracé', () => {
        const greffon = { id: 'centre', afterDraw: () => {} }

        expect(trace(monter({ plugins: [greffon] })).props('plugins')).toEqual([greffon])
    })

    it('montre l’état vide à la place du tracé', () => {
        const wrapper = monter({ vide: true }, { vide: '<p>Rien à tracer</p>' })

        expect(wrapper.text()).toContain('Rien à tracer')
        expect(trace(wrapper).exists()).toBe(false)
    })

    it('garde à l’ombre la densité que la carte demande', () => {
        // Six cartes portaient une ombre plus dense ou plus pâle que 0,2 ; une
        // valeur figée dans le composant les aurait toutes alignées en silence.
        expect(monter({ lueur: 'accent-danger', lueurOpacite: 0.25 }).find('.relative').attributes('style')).toContain(
            '--lueur-opacite: 0.25',
        )
        expect(monter({ lueur: 'accent-danger' }).find('.relative').attributes('style')).toContain(
            '--lueur-opacite: 0.2',
        )
    })

    it('pose la hauteur, la lueur et la surcouche', () => {
        const wrapper = monter({ hauteur: 'h-64', lueur: 'accent-info' }, { surcouche: '<span>centre</span>' })
        const cadre = wrapper.find('.relative')

        expect(cadre.classes()).toContain('h-64')
        expect(cadre.classes()).toContain('avec-lueur')
        expect(cadre.attributes('style')).toContain('--lueur: var(--color-accent-info)')
        expect(wrapper.text()).toContain('centre')

        const sansLueur = monter().find('.relative')
        expect(sansLueur.classes()).not.toContain('avec-lueur')
        expect(sansLueur.attributes('style')).toBeUndefined()
    })

    it('dessine la légende d’un anneau sous le canevas, et habille ses parts lui-même', () => {
        const wrapper = monter({
            type: 'doughnut',
            labels: ['Matin', 'Soir'],
            datasets: [{ data: [3, 1], backgroundColor: ['red', 'blue'], borderWidth: 2, hoverOffset: 4 }],
        })

        const entrees = wrapper.findAll('li')
        expect(entrees.map((li) => li.text())).toEqual(['Matin', 'Soir'])
        expect(entrees[1].find('span').attributes('style')).toContain('blue')

        const dataset = trace(wrapper, 'Doughnut').props('data').datasets[0]
        expect(dataset.borderWidth).toBe(0)
        expect(dataset.hoverOffset).toBe(8)
        expect(trace(monter({ datasets: [{ data: [1], borderWidth: 2 }] })).props('data').datasets[0].borderWidth).toBe(
            2,
        )
    })
})
