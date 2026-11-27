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

const libelleDuCote = (part, cote) =>
    part.startsWith('Biceps') ? `Biceps ${cote === 'gauche' ? 'gauche' : 'droit'}` : part

const paire = (famille, label, gauche, droite, surGauche = {}, surDroite = {}) => ({
    famille,
    label,
    cotes: [
        surGauche === null
            ? null
            : { cote: 'gauche', ...mensuration(gauche, 30), label: libelleDuCote(gauche, 'gauche'), ...surGauche },
        surDroite === null
            ? null
            : { cote: 'droite', ...mensuration(droite, 30), label: libelleDuCote(droite, 'droite'), ...surDroite },
    ].filter(Boolean),
})

const monter = async (props = {}, config = {}) => {
    const wrapper = mount(Corps, {
        props: {
            joursDesCourbes: 90,
            latestWeight: null,
            weightChange: 0,
            bodyFat: null,
            latestMeasurements: [],
            ...props,
        },
        global: {
            config,
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

    it('montre les lignes que le serveur a retenues, une `li` chacune, sans en recouper', async () => {
        const wrapper = await monter({
            latestMeasurements: [1, 2, 3, 4, 5, 6].map((rang) => mensuration(`Partie${rang}`, 30)),
        })

        expect(wrapper.findAll('li')).toHaveLength(6)
        expect(wrapper.findAll('li').map((ligne) => ligne.get('.titre-plat').text())).toEqual([
            'Partie1',
            'Partie2',
            'Partie3',
            'Partie4',
            'Partie5',
            'Partie6',
        ])
        expect(liens(wrapper)).toContain('/body-parts.index')
    })

    it('rend une ligne par famille à deux côtés, avec deux pastilles qui mènent à la partie exacte', async () => {
        const wrapper = await monter({
            latestMeasurements: [mensuration('Waist', 81), paire('Biceps', 'Biceps', 'Biceps L', 'Biceps R')],
        })
        const lignes = wrapper.findAll('li')
        const pastilles = lignes[1].findAll('a')

        expect(lignes).toHaveLength(2)
        expect(lignes[1].get('.titre-plat').text()).toBe('Biceps')
        expect(pastilles.map((lien) => lien.attributes('href'))).toEqual([
            '/body-parts/Biceps%20L',
            '/body-parts/Biceps%20R',
        ])
        expect(pastilles[0].text()).toContain('Gauche')
        expect(pastilles[1].text()).toContain('Droit')
        expect(lignes[1].find('a a').exists()).toBe(false)
        expect(lignes[0].findAll('a')).toHaveLength(1)
    })

    it('nomme chaque pastille par son libellé, sa valeur, son unité et son écart en toutes lettres', async () => {
        const wrapper = await monter({
            latestMeasurements: [
                paire('Biceps', 'Biceps', 'Biceps L', 'Biceps R', { diff: 0.5 }, { diff: -1.5, unit: 'in' }),
            ],
        })
        const pastilles = wrapper.findAll('li a')

        expect(pastilles[0].attributes('aria-label')).toBe('Biceps gauche, 30 cm, plus 0,5')
        expect(pastilles[1].attributes('aria-label')).toBe('Biceps droit, 30 in, moins 1,5')
    })

    it('ne dit pas d’écart quand un côté n’a pas bougé, et le nomme sans écart', async () => {
        const wrapper = await monter({
            latestMeasurements: [paire('Calf', 'Mollet', 'Calf L', 'Calf R')],
        })

        expect(wrapper.findAll('li a')[0].attributes('aria-label')).toBe('Calf L, 30 cm')
        expect(wrapper.find('li .text-trend-up, li .text-trend-down').exists()).toBe(false)
    })

    it('garde l’unité de chaque côté, qui peuvent différer', async () => {
        const wrapper = await monter({
            latestMeasurements: [paire('Thigh', 'Cuisse', 'Thigh L', 'Thigh R', { unit: 'cm' }, { unit: 'in' })],
        })
        const pastilles = wrapper.findAll('li a')

        expect(pastilles[0].text()).toContain('cm')
        expect(pastilles[0].text()).not.toContain('in')
        expect(pastilles[1].text()).toContain('in')
    })

    it('met la date au titre quand les deux côtés la partagent, sous chaque valeur sinon', async () => {
        const commune = await monter({ latestMeasurements: [paire('Thigh', 'Cuisse', 'Thigh L', 'Thigh R')] })

        expect(
            commune
                .get('li')
                .text()
                .match(/10\/11\/2026/g),
        ).toHaveLength(1)
        expect(commune.get('li > div > div').text()).toContain('10/11/2026')

        const distinctes = await monter({
            latestMeasurements: [
                paire('Thigh', 'Cuisse', 'Thigh L', 'Thigh R', { date: '2026-03-03' }, { date: '2026-11-10' }),
            ],
        })
        const pastilles = distinctes.findAll('li a')

        expect(distinctes.get('li > div > div').text()).not.toMatch(/03\/03|10\/11/)
        expect(pastilles[0].text()).toContain('03/03/2026')
        expect(pastilles[1].text()).toContain('10/11/2026')
    })

    it('ne rend qu’une pastille quand un seul côté est saisi', async () => {
        const wrapper = await monter({
            latestMeasurements: [paire('Calf', 'Mollet', 'Calf L', 'Calf R', {}, null)],
        })

        expect(wrapper.findAll('li a')).toHaveLength(1)
        expect(wrapper.get('li a').attributes('href')).toBe('/body-parts/Calf%20L')
    })

    it('distingue une partie nommée « Biceps » de la famille Biceps', async () => {
        const avertissements = []
        const biceps = paire('Biceps', 'Biceps', 'Biceps L', 'Biceps R')
        const lignes = [mensuration('Taille', 81), mensuration('Biceps', 38), biceps, mensuration('Hanches', 98)]
        const wrapper = await monter(
            { latestMeasurements: lignes },
            { warnHandler: (message) => avertissements.push(message) },
        )

        // Vue ne signale deux clefs égales que lorsqu'une mise à jour renverse l'ordre de la liste.
        await wrapper.setProps({ latestMeasurements: [...lignes].reverse() })

        expect(avertissements.join(' ')).not.toContain('Duplicate keys')
        expect(wrapper.findAll('li')).toHaveLength(4)
        expect(wrapper.findAll('li')[1].findAll('a')).toHaveLength(2)
        expect(wrapper.findAll('li')[2].get('a').attributes('href')).toBe('/body-parts/Biceps')
    })

    it('annonce la durée des courbes que le serveur donne', async () => {
        const wrapper = await monter({ joursDesCourbes: 30, latestWeight: '80.40' })

        expect(wrapper.text()).toContain('les 30 derniers jours')
        expect(wrapper.text()).not.toContain('les 90 derniers jours')
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
