import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { defineComponent, ref, nextTick } from 'vue'
import { usePullToRefresh } from '@/composables/usePullToRefresh'
import { router } from '@inertiajs/vue3'
import * as haptics from '@/composables/useHaptics'

vi.mock('@inertiajs/vue3', () => ({
    router: {
        reload: vi.fn(),
    },
}))

vi.mock('@/composables/useHaptics', () => ({
    triggerHaptic: vi.fn(),
}))

const TestComponent = defineComponent({
    setup() {
        const { isRefreshing, pullDistance, isPulling } = usePullToRefresh({
            threshold: 100,
        })
        return { isRefreshing, pullDistance, isPulling }
    },
    template: '<div></div>',
})

const ContainerTestComponent = defineComponent({
    setup() {
        const containerRef = ref(null)
        const { isRefreshing, pullDistance, isPulling } = usePullToRefresh({
            threshold: 100,
            containerRef,
        })
        return { isRefreshing, pullDistance, isPulling, containerRef }
    },
    template: '<div ref="containerRef"></div>',
})

describe('usePullToRefresh', () => {
    let wrapper

    beforeEach(() => {
        vi.clearAllMocks()
        vi.useFakeTimers()

        Object.defineProperty(window, 'scrollY', {
            value: 0,
            writable: true,
        })
    })

    afterEach(() => {
        if (wrapper) {
            wrapper.unmount()
        }
        vi.useRealTimers()
    })

    it('initializes with default values', () => {
        wrapper = mount(TestComponent)
        expect(wrapper.vm.isRefreshing).toBe(false)
        expect(wrapper.vm.pullDistance).toBe(0)
        expect(wrapper.vm.isPulling).toBe(false)
    })

    it('starts pulling when at top of page', async () => {
        wrapper = mount(TestComponent)
        window.scrollY = 0

        const touchStartEvent = new TouchEvent('touchstart', {
            touches: [{ clientY: 100 }],
        })
        window.dispatchEvent(touchStartEvent)

        expect(wrapper.vm.isPulling).toBe(true)
    })

    it('does not start pulling when not at top of page', async () => {
        wrapper = mount(TestComponent)
        window.scrollY = 50

        const touchStartEvent = new TouchEvent('touchstart', {
            touches: [{ clientY: 100 }],
        })
        window.dispatchEvent(touchStartEvent)

        expect(wrapper.vm.isPulling).toBe(false)
    })

    it('updates pull distance on move when pulling down', async () => {
        wrapper = mount(TestComponent)

        const touchStartEvent = new TouchEvent('touchstart', {
            touches: [{ clientY: 100 }],
        })
        window.dispatchEvent(touchStartEvent)

        const touchMoveEvent = new TouchEvent('touchmove', {
            touches: [{ clientY: 200 }], // Pulled down 100px
        })
        window.dispatchEvent(touchMoveEvent)

        expect(wrapper.vm.isPulling).toBe(true)
        expect(wrapper.vm.pullDistance).toBeGreaterThan(0)
        // Math.pow(100, 0.8) is approximately 39.81
        expect(wrapper.vm.pullDistance).toBeCloseTo(39.81)
    })

    it('cancels pull if moved up', async () => {
        wrapper = mount(TestComponent)

        const touchStartEvent = new TouchEvent('touchstart', {
            touches: [{ clientY: 100 }],
        })
        window.dispatchEvent(touchStartEvent)

        const touchMoveEvent = new TouchEvent('touchmove', {
            touches: [{ clientY: 50 }], // Moved up
        })
        window.dispatchEvent(touchMoveEvent)

        expect(wrapper.vm.isPulling).toBe(true) // Still considered 'pulling' but distance is 0
        expect(wrapper.vm.pullDistance).toBe(0)
    })

    it('cancels pull if scrolled down during pull', async () => {
        wrapper = mount(TestComponent)

        const touchStartEvent = new TouchEvent('touchstart', {
            touches: [{ clientY: 100 }],
        })
        window.dispatchEvent(touchStartEvent)

        window.scrollY = 10 // Scrolled down

        const touchMoveEvent = new TouchEvent('touchmove', {
            touches: [{ clientY: 150 }],
        })
        window.dispatchEvent(touchMoveEvent)

        expect(wrapper.vm.isPulling).toBe(false)
        expect(wrapper.vm.pullDistance).toBe(0)
    })

    it('triggers refresh when threshold is reached and released', async () => {
        wrapper = mount(TestComponent)

        const touchStartEvent = new TouchEvent('touchstart', {
            touches: [{ clientY: 100 }],
        })
        window.dispatchEvent(touchStartEvent)

        // Move enough to exceed threshold of 100 after pow(0.8)
        // delta ^ 0.8 > 100 => delta > 100 ^ 1.25 => delta > 316.22
        const touchMoveEvent = new TouchEvent('touchmove', {
            touches: [{ clientY: 450 }], // delta = 350, pow(350, 0.8) ~= 108
        })
        window.dispatchEvent(touchMoveEvent)

        const touchEndEvent = new TouchEvent('touchend')
        window.dispatchEvent(touchEndEvent)

        // We have to wait for the async onRefresh to be called
        await nextTick()

        expect(wrapper.vm.isRefreshing).toBe(true)
        expect(wrapper.vm.isPulling).toBe(false)
        expect(wrapper.vm.pullDistance).toBe(100) // snapped to threshold

        expect(haptics.triggerHaptic).toHaveBeenCalledWith('time')
        expect(router.reload).toHaveBeenCalledWith({ preserveScroll: true })

        // Fast forward 500ms to clear refreshing state
        vi.advanceTimersByTime(500)
        await nextTick()

        expect(wrapper.vm.isRefreshing).toBe(false)
        expect(wrapper.vm.pullDistance).toBe(0)
    })

    it('does not trigger refresh if released before threshold', async () => {
        wrapper = mount(TestComponent)

        const touchStartEvent = new TouchEvent('touchstart', {
            touches: [{ clientY: 100 }],
        })
        window.dispatchEvent(touchStartEvent)

        // Move slightly
        const touchMoveEvent = new TouchEvent('touchmove', {
            touches: [{ clientY: 150 }], // delta = 50, pow(50, 0.8) ~= 22.9
        })
        window.dispatchEvent(touchMoveEvent)

        const touchEndEvent = new TouchEvent('touchend')
        window.dispatchEvent(touchEndEvent)

        await nextTick()

        expect(wrapper.vm.isRefreshing).toBe(false)
        expect(wrapper.vm.isPulling).toBe(false)
        expect(wrapper.vm.pullDistance).toBe(0)

        expect(haptics.triggerHaptic).not.toHaveBeenCalled()
        expect(router.reload).not.toHaveBeenCalled()
    })

    it('works with a specific container element', async () => {
        wrapper = mount(ContainerTestComponent)

        const container = wrapper.element
        Object.defineProperty(container, 'scrollTop', {
            value: 0,
            writable: true,
        })

        const touchStartEvent = new TouchEvent('touchstart', {
            touches: [{ clientY: 100 }],
        })
        container.dispatchEvent(touchStartEvent)

        expect(wrapper.vm.isPulling).toBe(true)

        // Scrolled down container
        container.scrollTop = 20
        const touchStartEvent2 = new TouchEvent('touchstart', {
            touches: [{ clientY: 100 }],
        })
        container.dispatchEvent(touchStartEvent2)

        expect(wrapper.vm.isPulling).toBe(false)
    })

    it('uses custom onRefresh callback if provided', async () => {
        const customRefresh = vi.fn().mockResolvedValue()
        const CustomRefreshComponent = defineComponent({
            setup() {
                const { isRefreshing, pullDistance, isPulling } = usePullToRefresh({
                    threshold: 100,
                    onRefresh: customRefresh,
                })
                return { isRefreshing, pullDistance, isPulling }
            },
            template: '<div></div>',
        })

        wrapper = mount(CustomRefreshComponent)

        const touchStartEvent = new TouchEvent('touchstart', {
            touches: [{ clientY: 100 }],
        })
        window.dispatchEvent(touchStartEvent)

        const touchMoveEvent = new TouchEvent('touchmove', {
            touches: [{ clientY: 450 }],
        })
        window.dispatchEvent(touchMoveEvent)

        const touchEndEvent = new TouchEvent('touchend')
        window.dispatchEvent(touchEndEvent)

        await nextTick()

        expect(customRefresh).toHaveBeenCalled()
        expect(router.reload).not.toHaveBeenCalled()
    })
})
