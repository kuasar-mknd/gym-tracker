import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import { Chart } from 'chart.js'

import BaseChart from '@/Components/Stats/BaseChart.vue'

const contexteMuet = (canevas) =>
    new Proxy(
        { canvas: canevas },
        {
            get: (cible, nom) => (nom in cible ? cible[nom] : nom === 'measureText' ? () => ({ width: 0 }) : () => {}),
            set: () => true,
        },
    )

beforeEach(() => {
    vi.stubGlobal(
        'ResizeObserver',
        class {
            observe() {}
            unobserve() {}
            disconnect() {}
        },
    )
    vi.spyOn(HTMLCanvasElement.prototype, 'getContext').mockImplementation(function () {
        return contexteMuet(this)
    })
})

afterEach(() => {
    vi.unstubAllGlobals()
    vi.restoreAllMocks()
})

const monter = () =>
    mount(BaseChart, {
        props: { description: 'Reps', datasets: [{ label: 'Reps', data: [12, 8] }], labels: ['lun.', 'mar.'] },
        attachTo: document.body,
    })

const animation = (wrapper) => Chart.getChart(wrapper.find('canvas').element).options.animation

describe('BaseChart à l’impression, sur un vrai Chart.js', () => {
    it('coupe l’animation avant l’impression, puis la rend à Chart.js après', async () => {
        const wrapper = monter()
        const avant = animation(wrapper)

        expect(avant).not.toBe(false)

        window.dispatchEvent(new Event('beforeprint'))
        await nextTick()
        expect(animation(wrapper)).toBe(false)

        window.dispatchEvent(new Event('afterprint'))
        await nextTick()
        expect(animation(wrapper)).not.toBe(false)
        expect(animation(wrapper)).toEqual(avant)

        wrapper.unmount()
    })

    it('retire les écouteurs qu’il a posés, ni plus ni moins', () => {
        const pose = vi.spyOn(window, 'addEventListener')
        const retrait = vi.spyOn(window, 'removeEventListener')
        const wrapper = monter()
        const ajoute = (type) => pose.mock.calls.find(([nom]) => nom === type)[1]

        wrapper.unmount()

        expect(retrait).toHaveBeenCalledWith('beforeprint', ajoute('beforeprint'))
        expect(retrait).toHaveBeenCalledWith('afterprint', ajoute('afterprint'))
    })
})
