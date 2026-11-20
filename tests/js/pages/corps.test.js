import { describe, it, expect, vi, beforeAll } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

vi.mock('@inertiajs/vue3', () => ({
    Head: { template: '<div />' },
    Link: { props: ['href'], template: '<a :href="href"><slot /></a>' },
    Deferred: { template: '<div><slot name="fallback" /><slot /></div>' },
}))

vi.mock('@/Components/Stats/WeightHistoryChart.vue', () => ({
    __esModule: true,
    default: { props: ['data'], template: '<div class="weight-chart" />' },
}))
vi.mock('@/Components/Stats/BodyFatChart.vue', () => ({
    __esModule: true,
    default: { props: ['data'], template: '<div class="body-fat-chart" />' },
}))

import { layoutStub } from './pageStubs'

const Corps = (await import('@/Pages/Corps/Index.vue')).default

beforeAll(() => {
    globalThis.route = (name, params) =>
        name === 'body-parts.show' ? `/body-parts/${encodeURIComponent(params.part)}` : `/${name}`
})

const mensuration = (part, current, diff = 0) => ({
    part,
    label: part,
    current,
    unit: 'cm',
    date: '2026-11-10',
    diff,
})

const monter = async (props = {}) => {
    const wrapper = mount(Corps, {
        props: { latestWeight: null, weightChange: 0, bodyFat: null, latestMeasurements: [], ...props },
        global: {
            directives: { press: {} },
            mocks: { route: globalThis.route },
            stubs: { AuthenticatedLayout: layoutStub },
        },
    })

    await flushPromises()

    return wrapper
}

const liens = (wrapper) => wrapper.findAll('a').map((lien) => lien.attributes('href'))

describe('Corps/Index', () => {
    it('réunit le poids, la masse grasse et les mensurations', async () => {
        const wrapper = await monter({
            latestWeight: '80.40',
            weightChange: -0.6,
            bodyFat: 18.5,
            latestMeasurements: [mensuration('Taille', 81, -1)],
            bodyStats: {
                weightHistory: [{ date: '01/11', weight: 81 }],
                bodyFatHistory: [{ date: '01/11', body_fat: 19 }],
            },
        })

        expect(wrapper.text()).toContain('80,4')
        expect(wrapper.text()).toContain('18,5')
        expect(wrapper.text()).toContain('Taille')
        expect(wrapper.text()).toContain('81')
        expect(wrapper.find('.weight-chart').exists()).toBe(true)
        expect(wrapper.find('.body-fat-chart').exists()).toBe(true)
    })

    it('mène à l’historique du poids et aux mensurations, où elles se saisissent', async () => {
        const wrapper = await monter({ latestMeasurements: [mensuration('Taille', 81)] })

        expect(liens(wrapper)).toEqual(
            expect.arrayContaining(['/body-measurements.index', '/body-parts/Taille', '/body-parts.index']),
        )
        expect(wrapper.text()).toContain('Toutes les mensurations')
    })

    it('propose d’ajouter une première mensuration quand il n’y en a aucune', async () => {
        const wrapper = await monter()

        expect(wrapper.text()).toContain('Aucune mensuration enregistrée')
        expect(wrapper.text()).toContain('Ajouter une mensuration')
        expect(liens(wrapper)).toContain('/body-parts.index')
    })

    it('ne liste que les six premières mensurations, et renvoie vers les autres', async () => {
        const toutes = [1, 2, 3, 4, 5, 6, 7, 8].map((rang) => mensuration(`Partie${rang}`, 30))
        const wrapper = await monter({ latestMeasurements: toutes })

        expect(wrapper.findAll('li')).toHaveLength(6)
        expect(wrapper.text()).toContain('Partie6')
        expect(wrapper.text()).not.toContain('Partie7')
        expect(liens(wrapper)).toContain('/body-parts.index')
    })

    it('choisit les six mensurations par pertinence, pas par ordre alphabétique', async () => {
        const noms = [
            'Biceps L',
            'Biceps R',
            'Calf L',
            'Calf R',
            'Chest',
            'Forearm L',
            'Hips',
            'Neck',
            'Thigh L',
            'Waist',
        ]
        const wrapper = await monter({ latestMeasurements: noms.map((nom) => mensuration(nom, 30)) })
        const parties = wrapper.findAll('li').map((ligne) => ligne.get('.titre-carte').text())

        expect(parties).toEqual(['Waist', 'Chest', 'Hips', 'Thigh L', 'Biceps L', 'Biceps R'])
        expect(wrapper.text()).not.toContain('Neck')
        expect(wrapper.text()).not.toContain('Forearm')
    })

    it('ne signale pas d’écart quand la mensuration n’a pas bougé, et colore les autres', async () => {
        const wrapper = await monter({
            latestMeasurements: [
                mensuration('Taille', 81, -1),
                mensuration('Cuisse', 60, 2),
                mensuration('Bras', 40, 0),
            ],
        })
        const lignes = wrapper.findAll('li')

        expect(lignes[0].find('.text-trend-down').exists()).toBe(true)
        expect(lignes[1].find('.text-trend-up').exists()).toBe(true)
        expect(lignes[2].find('.text-trend-up, .text-trend-down').exists()).toBe(false)
    })

    it('survit à des courbes encore absentes', async () => {
        const wrapper = await monter({ latestWeight: '80.40' })

        expect(wrapper.find('.weight-chart').exists()).toBe(false)
        expect(wrapper.text()).toContain('Aucune pesée sur les 90 derniers jours')
        expect(wrapper.text()).not.toContain('cette période')
        expect(wrapper.text()).toContain('Pas de données historiques')
    })

    it('propose d’ajouter une pesée, et non l’historique, quand rien n’a été pesé', async () => {
        const wrapper = await monter()

        expect(wrapper.text()).toContain('Ajouter une pesée')
        expect(wrapper.text()).not.toContain("Voir tout l'historique")
        expect(liens(wrapper)).toContain('/body-measurements.index')
    })

    it('ne pose pas de titre de niveau 1 de plus que celui de l’en-tête', async () => {
        const wrapper = await monter()

        expect(wrapper.findAll('h1')).toHaveLength(1)
        expect(wrapper.get('h1').text()).toBe('Corps')
    })
})
