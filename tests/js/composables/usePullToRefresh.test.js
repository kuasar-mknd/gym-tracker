import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { ref, nextTick } from 'vue'
import { mount } from '@vue/test-utils'
import { usePullToRefresh } from '@/composables/usePullToRefresh'

vi.mock('@inertiajs/vue3', () => ({
    router: { reload: vi.fn() },
}))

const emitTouch = (target, type, clientY) => {
    const event = new Event(type, { bubbles: true })
    event.touches = [{ clientY }]
    target.dispatchEvent(event)
}

const setScrollTop = (value) => {
    Object.defineProperty(window, 'scrollY', {
        value,
        configurable: true,
        writable: true,
    })
}

/** Mounts a throwaway component so onMounted/onUnmounted hooks actually run. */
const mountWithPullToRefresh = (options = {}) => {
    let state

    const wrapper = mount({
        setup() {
            state = usePullToRefresh(options)

            return () => null
        },
    })

    return { wrapper, state }
}

describe('usePullToRefresh composable', () => {
    beforeEach(() => {
        setScrollTop(0)
        vi.useFakeTimers()
    })

    afterEach(() => {
        vi.useRealTimers()
        vi.restoreAllMocks()
    })

    it('does not refresh when the pull stops short of the threshold', async () => {
        const onRefresh = vi.fn().mockResolvedValue(undefined)
        const { state } = mountWithPullToRefresh({ threshold: 150, onRefresh })

        emitTouch(window, 'touchstart', 0)
        emitTouch(window, 'touchmove', 100)
        emitTouch(window, 'touchend', 100)
        await nextTick()

        expect(onRefresh).not.toHaveBeenCalled()
        expect(state.pullDistance.value).toBe(0)
        expect(state.isRefreshing.value).toBe(false)
    })

    it('refreshes once the pull passes the threshold', async () => {
        const onRefresh = vi.fn().mockResolvedValue(undefined)
        const { state } = mountWithPullToRefresh({ threshold: 100, onRefresh })

        emitTouch(window, 'touchstart', 0)
        // 400px of travel survives the resistance curve: 400^0.8 ≈ 120 > 100.
        emitTouch(window, 'touchmove', 400)
        emitTouch(window, 'touchend', 400)
        await nextTick()

        expect(onRefresh).toHaveBeenCalledTimes(1)
        expect(state.isRefreshing.value).toBe(true)
    })

    it('applies resistance so the indicator lags behind the finger', () => {
        const { state } = mountWithPullToRefresh({ threshold: 150 })

        emitTouch(window, 'touchstart', 0)
        emitTouch(window, 'touchmove', 200)

        // Without resistance this would be a linear 200.
        expect(state.pullDistance.value).toBeCloseTo(Math.pow(200, 0.8), 5)
        expect(state.pullDistance.value).toBeLessThan(200)
    })

    it('ignores the gesture when the page is already scrolled down', () => {
        const { state } = mountWithPullToRefresh({ threshold: 150 })
        setScrollTop(500)

        emitTouch(window, 'touchstart', 0)
        emitTouch(window, 'touchmove', 300)

        expect(state.isPulling.value).toBe(false)
        expect(state.pullDistance.value).toBe(0)
    })

    it('abandons the pull if the user scrolls away mid-gesture', () => {
        const { state } = mountWithPullToRefresh({ threshold: 150 })

        emitTouch(window, 'touchstart', 0)
        setScrollTop(80)
        emitTouch(window, 'touchmove', 300)

        expect(state.isPulling.value).toBe(false)
        expect(state.pullDistance.value).toBe(0)
    })

    it('does not track upward swipes', () => {
        const { state } = mountWithPullToRefresh({ threshold: 150 })

        emitTouch(window, 'touchstart', 300)
        emitTouch(window, 'touchmove', 100)

        expect(state.pullDistance.value).toBe(0)
    })

    it('clears the refreshing state after the settle delay', async () => {
        const onRefresh = vi.fn().mockResolvedValue(undefined)
        const { state } = mountWithPullToRefresh({ threshold: 100, onRefresh })

        emitTouch(window, 'touchstart', 0)
        emitTouch(window, 'touchmove', 400)
        emitTouch(window, 'touchend', 400)
        await nextTick()

        expect(state.isRefreshing.value).toBe(true)

        vi.advanceTimersByTime(500)
        await nextTick()

        expect(state.isRefreshing.value).toBe(false)
        expect(state.pullDistance.value).toBe(0)
    })

    it('still settles when the refresh handler rejects', async () => {
        const onRefresh = vi.fn().mockRejectedValue(new Error('network down'))
        const { state } = mountWithPullToRefresh({ threshold: 100, onRefresh })

        emitTouch(window, 'touchstart', 0)
        emitTouch(window, 'touchmove', 400)
        emitTouch(window, 'touchend', 400)
        await nextTick()
        await nextTick()

        vi.advanceTimersByTime(500)
        await nextTick()

        // A stuck spinner after a failed refresh would look like a frozen app.
        expect(state.isRefreshing.value).toBe(false)
    })

    it('detaches its listeners on unmount', () => {
        const removeSpy = vi.spyOn(window, 'removeEventListener')
        const { wrapper } = mountWithPullToRefresh({ threshold: 150 })

        wrapper.unmount()

        const removed = removeSpy.mock.calls.map(([type]) => type)
        expect(removed).toEqual(expect.arrayContaining(['touchstart', 'touchmove', 'touchend']))
    })

    it('binds to a scroll container when one is supplied', () => {
        const container = document.createElement('div')
        document.body.appendChild(container)
        container.scrollTop = 0

        const { state } = mountWithPullToRefresh({
            threshold: 150,
            containerRef: ref(container),
        })

        emitTouch(container, 'touchstart', 0)
        emitTouch(container, 'touchmove', 200)

        expect(state.isPulling.value).toBe(true)
        expect(state.pullDistance.value).toBeGreaterThan(0)

        container.remove()
    })
})
