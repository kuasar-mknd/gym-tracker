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

vi.mock('vue-chartjs', () => ({
    Line: { name: 'Line', props: { data: null, options: null }, template: '<div />' },
}))

/*
 * Le graphique est un defineAsyncComponent derrière un import dynamique.
 * Chargé ici d’abord, l’import de la grille se résout depuis le cache de
 * modules dans le tick qu’attend flushPromises — sinon le slot reste un nœud
 * commentaire et toute assertion sur le graphique porterait sur du vide.
 */
await import('@/Components/Stats/BodyFatChart.vue')

const BodyMetricsGrid = (await import('@/Components/Stats/BodyMetricsGrid.vue')).default

const mountGrid = async (props = {}) => {
    const wrapper = mount(BodyMetricsGrid, { props })

    await flushPromises()

    return wrapper
}

/** Le badge de tendance, seul élément à la fois coloré et fléché. */
const trendBadge = (wrapper) => wrapper.find('div.rounded-lg.px-2')

/** Les deux grands nombres : la masse grasse à gauche, le volume à droite. */
const readings = (wrapper) => wrapper.findAll('p.font-display').map((p) => p.text().replace(/\s+/g, ' '))

const digitsOf = (text) => text.replace(/\D/g, '')

/** Le graphique lui-même, pas le trou qu’il laisse dans le DOM. */
const chart = (wrapper) => wrapper.findComponent({ name: 'BodyFatChart' })

describe('BodyMetricsGrid', () => {
    it('affiche un tiret plutôt qu’un zéro quand la masse grasse est inconnue', async () => {
        expect(readings(await mountGrid({ bodyFat: null }))[0]).toBe('— %')
        // 0 % de masse grasse n’existe pas : c’est une absence de mesure.
        expect(readings(await mountGrid({ bodyFat: 0 }))[0]).toBe('— %')
    })

    it('affiche la masse grasse mesurée', async () => {
        expect(readings(await mountGrid({ bodyFat: 18.5 }))[0]).toBe('18.5 %')
    })

    it('arrondit le volume du mois au kilo', async () => {
        const wrapper = await mountGrid({ monthlyComparison: { current_volume: 12499.6, percentage: 0 } })

        // Le séparateur de milliers dépend de la locale du poste ; le nombre,
        // lui, doit être 12 500 et pas 12 499,6.
        expect(digitsOf(readings(wrapper)[1])).toBe('12500')
        expect(readings(wrapper)[1]).toContain('kg')
    })

    it('affiche zéro kilo quand le mois n’a pas encore de volume', async () => {
        expect(readings(await mountGrid({}))[1]).toBe('0 kg')
    })

    it('pointe la flèche vers le haut sur une progression', async () => {
        const wrapper = await mountGrid({ monthlyComparison: { current_volume: 1000, percentage: 12 } })

        expect(wrapper.findAll('span.material-symbols-outlined').at(-1).text()).toBe('trending_up')
        expect(trendBadge(wrapper).text().replace(/\s+/g, ' ')).toContain('+12%')
        expect(trendBadge(wrapper).classes()).toContain('text-trend-up')
    })

    it('pointe la flèche vers le bas sur un recul, sans signe plus', async () => {
        const wrapper = await mountGrid({ monthlyComparison: { current_volume: 1000, percentage: -8 } })

        expect(wrapper.findAll('span.material-symbols-outlined').at(-1).text()).toBe('trending_down')
        const badge = trendBadge(wrapper).text().replace(/\s+/g, ' ')
        expect(badge).toContain('-8%')
        expect(badge).not.toContain('+')
        expect(trendBadge(wrapper).classes()).toContain('text-trend-down')
    })

    it('traite un mois identique comme une progression nulle, pas comme un recul', async () => {
        // La borne : `> 0` au lieu de `>= 0` peindrait « 0 % » en rouge avec une
        // flèche descendante.
        const wrapper = await mountGrid({ monthlyComparison: { current_volume: 1000, percentage: 0 } })

        expect(wrapper.findAll('span.material-symbols-outlined').at(-1).text()).toBe('trending_up')
        expect(trendBadge(wrapper).text().replace(/\s+/g, ' ')).toContain('+0%')
        expect(trendBadge(wrapper).classes()).toContain('text-trend-up')
    })

    it('annonce l’absence d’historique plutôt qu’un graphique vide', async () => {
        const empty = await mountGrid({ bodyFatHistory: [] })
        const missing = await mountGrid({})

        // Le message seul ne prouve rien : un graphique tracé à côté de lui
        // laisserait le texte intact. C’est son absence qui est la promesse.
        expect(chart(empty).exists()).toBe(false)
        expect(empty.text()).toContain('Pas de données historiques')
        expect(chart(missing).exists()).toBe(false)
        expect(missing.text()).toContain('Pas de données historiques')
    })

    it('trace le graphique dès le premier point, avec l’historique reçu', async () => {
        const history = [{ date: '2026-03-01', body_fat: 18 }]
        const wrapper = await mountGrid({ bodyFatHistory: history })

        // Le graphique doit être monté — pas seulement l’état vide absent — et
        // recevoir l’historique tel quel : c’est la grille qui le lui transmet.
        expect(chart(wrapper).exists()).toBe(true)
        expect(chart(wrapper).props('data')).toEqual(history)
        expect(wrapper.text()).not.toContain('Pas de données historiques')
    })

    it('transmet tout l’historique au graphique, sans le tronquer', async () => {
        const history = [
            { date: '2026-01-01', body_fat: 21 },
            { date: '2026-02-01', body_fat: 19.5 },
            { date: '2026-03-01', body_fat: 18 },
        ]
        const wrapper = await mountGrid({ bodyFatHistory: history })

        expect(chart(wrapper).props('data')).toEqual(history)
    })

    it('attend les clés différées de la page', async () => {
        const keys = (await mountGrid({})).findAllComponents({ name: 'Deferred' }).map((d) => d.props('data'))

        expect(keys).toEqual(['bodyStats', 'performanceStats'])
    })

    it('attend la clé groupée quand la page en fournit une', async () => {
        // Le dashboard envoie tout sous `deferredData` : garder les clés de la
        // page Stats laisserait les deux cartes sur leur squelette pour
        // toujours.
        const keys = (await mountGrid({ deferredData: {} }))
            .findAllComponents({ name: 'Deferred' })
            .map((d) => d.props('data'))

        expect(keys).toEqual(['deferredData', 'deferredData'])
    })
})
