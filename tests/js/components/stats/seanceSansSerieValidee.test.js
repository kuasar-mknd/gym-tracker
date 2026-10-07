import { describe, it, expect, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartDataOf } from '../chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('../chartRecorder.js')).chartRecorders())

const MaxRepsChart = (await import('@/Components/Stats/MaxRepsChart.vue')).default
const MaxWeightChart = (await import('@/Components/Stats/MaxWeightChart.vue')).default
const AverageWeightChart = (await import('@/Components/Stats/AverageWeightChart.vue')).default
const Estimated1RMHistoryChart = (await import('@/Components/Stats/Estimated1RMHistoryChart.vue')).default

/*
 * Les cartes de la fiche exercice ne lisent que les séries validées (#1956).
 * Une séance lancée depuis un modèle puis abandonnée n'a donc ni charge max,
 * ni répétitions, ni 1RM : la page lui donne null, que la courbe enjambe au
 * lieu de tomber à zéro.
 */
describe.each([
    ['MaxRepsChart', MaxRepsChart, 'reps'],
    ['MaxWeightChart', MaxWeightChart, 'weight'],
    ['AverageWeightChart', AverageWeightChart, 'weight'],
    ['Estimated1RMHistoryChart', Estimated1RMHistoryChart, 'weight'],
])('%s', (_nom, composant, cle) => {
    it('enjambe une séance sans série validée au lieu de la tracer à zéro', () => {
        const donnees = [
            { date: '11/06', [cle]: 95 },
            { date: '13/06', [cle]: null },
            { date: '15/06', [cle]: 100 },
        ]

        const courbe = chartDataOf(mount(composant, { props: { data: donnees } }), 'Line').datasets[0]

        expect(courbe.data).toEqual([95, null, 100])
        expect(courbe.spanGaps).toBe(true)
    })
})
