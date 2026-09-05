import { describe, it, expect, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'

/*
 * Les cinq graphiques arrivent par defineAsyncComponent ; leur chaîne
 * d'imports resterait à se résoudre après la fin du fichier. Les remplacer
 * dans le registre suffit : ce qui compte ici est quelle série chacun reçoit.
 * `__esModule` dit à defineAsyncComponent qu'il tient un module et doit en
 * prendre le `default`, sans quoi le composant résolu est le module lui-même.
 */
const graphique = (nom) => ({ __esModule: true, default: { name: nom, props: ['data'], template: '<div />' } })

vi.mock('@/Components/Stats/WorkoutFrequencyChart.vue', () => graphique('WorkoutFrequencyChart'))
vi.mock('@/Components/Stats/WorkoutsPerMonthChart.vue', () => graphique('WorkoutsPerMonthChart'))
vi.mock('@/Components/Stats/MonthlyVolumeChart.vue', () => graphique('MonthlyVolumeChart'))
vi.mock('@/Components/Stats/WorkoutDurationChart.vue', () => graphique('WorkoutDurationChart'))
vi.mock('@/Components/Stats/VolumePerWorkoutChart.vue', () => graphique('VolumePerWorkoutChart'))

import GraphiquesDesSeances from '@/Components/Workout/GraphiquesDesSeances.vue'

const monter = (props = {}) =>
    mount(GraphiquesDesSeances, {
        props,
        global: { stubs: { GlassCard: { template: '<div><slot /></div>' } } },
    })

const titres = (wrapper) => wrapper.findAll('h3').map((titre) => titre.text())

const toutes = {
    day_of_week_frequency: [{ day: 'Lundi', count: 4 }],
    monthly_frequency: [{ month: '2026-07', count: 8 }],
    monthly_volume: [{ month: '2026-07', volume: 1200 }],
    duration_history: [{ date: '2026-07-29', duration: 45 }],
    volume_history: [{ date: '2026-07-29', volume: 900 }],
}

describe('GraphiquesDesSeances', () => {
    it('ne dessine que les séries qui portent des points', () => {
        const wrapper = monter({ charts: { ...toutes, monthly_volume: [], duration_history: [], volume_history: [] } })

        expect(titres(wrapper)).toEqual(['Fréquence par Jour', 'Fréquence Mensuelle'])
    })

    it('tend à chaque graphique sa propre série, dans l’ordre des cartes', async () => {
        const wrapper = monter({ charts: toutes })
        await flushPromises()

        expect(titres(wrapper)).toEqual([
            'Fréquence par Jour',
            'Fréquence Mensuelle',
            'Volume Mensuel',
            'Durée',
            'Volume par Séance',
        ])
        expect(wrapper.findComponent({ name: 'WorkoutFrequencyChart' }).props('data')).toEqual(
            toutes.day_of_week_frequency,
        )
        expect(wrapper.findComponent({ name: 'MonthlyVolumeChart' }).props('data')).toEqual(toutes.monthly_volume)
        expect(wrapper.findComponent({ name: 'VolumePerWorkoutChart' }).props('data')).toEqual(toutes.volume_history)
    })

    it('reste vide, sans se plaindre, quand la charge différée ne porte aucun graphique', () => {
        expect(titres(monter())).toEqual([])
        expect(titres(monter({ charts: undefined }))).toEqual([])
    })
})
