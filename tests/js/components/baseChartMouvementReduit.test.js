import { describe, it, expect, vi, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { chartOptionsOf } from './chartRecorder.js'

vi.mock('vue-chartjs', async () => (await import('./chartRecorder.js')).chartRecorders())

const BaseChart = (await import('@/Components/Stats/BaseChart.vue')).default

const monter = (type) =>
    mount(BaseChart, {
        props: { type, datasets: [{ label: 'Volume', data: [1, 2] }], labels: ['a', 'b'] },
    })

const reduire = (reduit) => {
    window.matchMedia = vi.fn().mockReturnValue({ matches: reduit })
}

afterEach(() => {
    delete window.matchMedia
})

describe('BaseChart sous « réduire les animations »', () => {
    it.each([
        ['bar', 'Bar'],
        ['line', 'Line'],
        ['doughnut', 'Doughnut'],
    ])('ne dessine pas de transition pour un graphique %s', (type, composant) => {
        reduire(true)

        expect(chartOptionsOf(monter(type), composant).animation).toBe(false)
    })

    it('garde les animations de Chart.js sinon', () => {
        reduire(false)

        expect(chartOptionsOf(monter('bar'), 'Bar').animation).toBeUndefined()
    })
})
