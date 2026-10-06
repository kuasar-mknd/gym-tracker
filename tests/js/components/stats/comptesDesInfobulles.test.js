import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { tooltipLabelOf } from '../chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('../chartRecorder.js')).chartRecorders())

/**
 * L'infobulle d'un graphique est un compteur comme un autre : une semaine à une
 * séance s'y lisait « 1 séances », une série unique « 1 reps » (#1980). Chaque
 * carte qui compte quelque chose dans son infobulle l'accorde par `compte()` de
 * `Utils/nombre.js` : singulier pour 1, pluriel à partir de 2.
 *
 * Chart.js appelle le rappel avec un contexte : on lui en passe un qui porte la
 * valeur survolée, sous la forme que chaque carte lit (`parsed.y` ou `raw`).
 */
const anneau = (n) => ({ raw: n, datasetIndex: 0, chart: { _metasets: [{ total: 4 }] } })

const cartes = [
    {
        nom: 'WorkoutFrequencyChart',
        trace: 'Bar',
        data: [{ day: 'Lun', count: 1 }],
        contexte: (n) => ({ parsed: { y: n } }),
        attendus: ['1 séance', '2 séances'],
    },
    {
        nom: 'SetsPerSessionChart',
        trace: 'Bar',
        data: [{ date: '01/07', sets: 1 }],
        contexte: (n) => ({ parsed: { y: n } }),
        attendus: ['1 série', '2 séries'],
    },
    {
        nom: 'RecentWorkoutsExercisesChart',
        trace: 'Bar',
        data: [{ started_at: '2026-07-05T10:00:00Z', workout_lines_count: 1 }],
        contexte: (n) => ({ parsed: { y: n } }),
        attendus: ['1 exercice', '2 exercices'],
    },
    {
        nom: 'TimeOfDayChart',
        trace: 'Doughnut',
        data: [{ label: 'Matin', count: 1 }],
        contexte: anneau,
        attendus: [' 1 séance (25%)', ' 2 séances (50%)'],
    },
    {
        nom: 'WeightDistributionChart',
        trace: 'Bar',
        data: [{ label: '60', count: 1 }],
        contexte: (n) => ({ raw: n }),
        attendus: ['1 série', '2 séries'],
    },
    {
        nom: 'HabitHistoryChart',
        trace: 'Bar',
        data: [{ date: '01/07', count: 1 }],
        contexte: (n) => ({ parsed: { y: n } }),
        attendus: ['1 habitude', '2 habitudes'],
    },
    {
        nom: 'HabitConsistencyChart',
        trace: 'Line',
        data: [{ date: '2026-07-01', count: 1 }],
        contexte: (n) => ({ parsed: { y: n } }),
        attendus: ['1 habitude complétée', '2 habitudes complétées'],
    },
    {
        nom: 'SupplementUsageChart',
        trace: 'Bar',
        data: [{ date: '01/07', count: 1 }],
        contexte: (n) => ({ raw: n }),
        attendus: ['1 dose', '2 doses'],
    },
    {
        nom: 'TotalRepsChart',
        trace: 'Bar',
        data: [{ date: '01/07', reps: 1 }],
        contexte: (n) => ({ parsed: { y: n } }),
        attendus: ['1 rep', '2 reps'],
    },
    {
        nom: 'WeightRepsScatterChart',
        trace: 'Scatter',
        data: [{ x: 100, y: 1 }],
        contexte: (n) => ({ parsed: { x: 100, y: n } }),
        attendus: ['100 kg × 1 rep', '100 kg × 2 reps'],
    },
]

const composants = import.meta.glob('@/Components/Stats/*Chart.vue', { eager: true, import: 'default' })

const carte = (nom) => composants[`/resources/js/Components/Stats/${nom}.vue`]

describe('les comptes des infobulles s’accordent', () => {
    it.each(cartes)('$nom : singulier pour 1, pluriel pour 2', ({ nom, trace, data, contexte, attendus }) => {
        const wrapper = mount(carte(nom), { props: { data } })

        expect([1, 2].map((n) => tooltipLabelOf(wrapper, trace, contexte(n)))).toEqual(attendus)

        wrapper.unmount()
    })

    it('OneRepMaxPercentagesChart : « 1 rep » à 100 % du maximum, « 2 reps » en dessous', () => {
        const wrapper = mount(carte('OneRepMaxPercentagesChart'), {
            props: {
                data: [
                    { percent: 100, value: 120, reps: 1 },
                    { percent: 95, value: 114, reps: 2 },
                ],
            },
        })

        expect(tooltipLabelOf(wrapper, 'Bar', { label: '100%', parsed: { y: 120 } })).toBe('120 kg (1 rep)')
        expect(tooltipLabelOf(wrapper, 'Bar', { label: '95%', parsed: { y: 114 } })).toBe('114 kg (2 reps)')

        wrapper.unmount()
    })
})
