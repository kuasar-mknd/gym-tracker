import { describe, it, expect, beforeEach, vi } from 'vitest'
import useHapticsModule, { isHapticsSupported, triggerHaptic, stopVibration, useHaptics } from '../useHaptics.js'

describe('useHaptics', () => {
    let originalNavigator

    beforeEach(() => {
        // Save the original navigator to restore it later if needed
        originalNavigator = global.navigator

        // Mock navigator with vibrate support for testing happy paths
        const mockNavigator = {
            vibrate: vi.fn().mockReturnValue(true),
        }

        // We use Object.defineProperty to override the getter in jsdom
        Object.defineProperty(global, 'navigator', {
            value: mockNavigator,
            writable: true,
            configurable: true,
        })
    })

    describe('isHapticsSupported', () => {
        it('should return true if navigator.vibrate exists', () => {
            expect(isHapticsSupported()).toBe(true)
        })

        it('should return false if navigator is undefined', () => {
            Object.defineProperty(global, 'navigator', {
                value: undefined,
                writable: true,
                configurable: true,
            })
            expect(isHapticsSupported()).toBe(false)
        })

        it('should return false if vibrate is not in navigator', () => {
            Object.defineProperty(global, 'navigator', {
                value: {},
                writable: true,
                configurable: true,
            })
            expect(isHapticsSupported()).toBe(false)
        })
    })

    describe('triggerHaptic', () => {
        it('should return false and not call vibrate if not supported', () => {
            Object.defineProperty(global, 'navigator', {
                value: {},
                writable: true,
                configurable: true,
            })

            const result = triggerHaptic('tap')
            expect(result).toBe(false)
        })

        it('should call navigator.vibrate with tap pattern by default', () => {
            triggerHaptic()
            expect(global.navigator.vibrate).toHaveBeenCalledWith([5])
        })

        it('should call navigator.vibrate with the requested pattern', () => {
            triggerHaptic('success')
            expect(global.navigator.vibrate).toHaveBeenCalledWith([50, 30, 50])
        })

        it('should fallback to tap pattern if requested pattern does not exist', () => {
            triggerHaptic('unknown_pattern')
            expect(global.navigator.vibrate).toHaveBeenCalledWith([5])
        })

        it('should return true if vibrate call is successful', () => {
            const result = triggerHaptic('tap')
            expect(result).toBe(true)
        })

        it('should return false and catch error if vibrate throws', () => {
            global.navigator.vibrate.mockImplementation(() => {
                throw new Error('Test error')
            })

            const result = triggerHaptic('tap')
            expect(result).toBe(false)
        })
    })

    describe('stopVibration', () => {
        it('should call navigator.vibrate with 0', () => {
            stopVibration()
            expect(global.navigator.vibrate).toHaveBeenCalledWith(0)
        })

        it('should return false and not call vibrate if not supported', () => {
            Object.defineProperty(global, 'navigator', {
                value: {},
                writable: true,
                configurable: true,
            })

            const result = stopVibration()
            expect(result).toBe(false)
        })

        it('should return true if vibrate(0) call is successful', () => {
            const result = stopVibration()
            expect(result).toBe(true)
        })

        it('should return false and catch error if vibrate throws', () => {
            global.navigator.vibrate.mockImplementation(() => {
                throw new Error('Test error')
            })

            const result = stopVibration()
            expect(result).toBe(false)
        })
    })

    describe('useHaptics composable', () => {
        it('should return the expected interface', () => {
            const haptics = useHaptics()
            expect(haptics).toHaveProperty('isSupported')
            expect(haptics).toHaveProperty('triggerHaptic')
            expect(haptics).toHaveProperty('stopVibration')
            expect(haptics).toHaveProperty('patterns')
        })

        it('should have correct initial values', () => {
            const haptics = useHaptics()
            expect(haptics.isSupported).toBe(true)
            expect(haptics.triggerHaptic).toBe(triggerHaptic)
            expect(haptics.stopVibration).toBe(stopVibration)
        })
    })

    describe('default export', () => {
        it('should expose the necessary methods and properties', () => {
            expect(useHapticsModule).toHaveProperty('triggerHaptic')
            expect(useHapticsModule).toHaveProperty('stopVibration')
            expect(useHapticsModule).toHaveProperty('isHapticsSupported')
            expect(useHapticsModule).toHaveProperty('patterns')
        })
    })
})
