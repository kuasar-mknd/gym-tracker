import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest'
import { readFileSync, readdirSync, statSync } from 'node:fs'
import { join, resolve } from 'node:path'
import {
    isHapticsSupported,
    triggerHaptic,
    stopVibration,
    useHaptics,
} from '@/composables/useHaptics'

describe('useHaptics composable', () => {
    let vibrateMock

    const withVibrationSupport = () => {
        vibrateMock = vi.fn().mockReturnValue(true)
        Object.defineProperty(navigator, 'vibrate', {
            value: vibrateMock,
            configurable: true,
            writable: true,
        })
    }

    const withoutVibrationSupport = () => {
        delete navigator.vibrate
    }

    beforeEach(() => {
        withVibrationSupport()
    })

    afterEach(() => {
        withoutVibrationSupport()
        vi.restoreAllMocks()
    })

    describe('isHapticsSupported', () => {
        it('reports support when the Vibration API is present', () => {
            expect(isHapticsSupported()).toBe(true)
        })

        it('reports no support when the Vibration API is absent', () => {
            withoutVibrationSupport()

            expect(isHapticsSupported()).toBe(false)
        })
    })

    describe('triggerHaptic', () => {
        it.each([
            ['tap', [5]],
            ['toggle', [15]],
            ['selection', [3]],
            ['success', [50, 30, 50]],
            ['error', [100, 50, 100, 50, 100]],
            ['warning', [30, 20, 30]],
            ['timer', [200, 100, 200]],
        ])('plays the %s pattern', (type, pattern) => {
            triggerHaptic(type)

            expect(vibrateMock).toHaveBeenCalledWith(pattern)
        })

        it('defaults to the tap pattern when called with no argument', () => {
            triggerHaptic()

            expect(vibrateMock).toHaveBeenCalledWith([5])
        })

        it('falls back to the tap pattern for an unknown type', () => {
            triggerHaptic('does-not-exist')

            expect(vibrateMock).toHaveBeenCalledWith([5])
        })

        it('returns false and does not throw when vibration is unsupported', () => {
            withoutVibrationSupport()

            expect(triggerHaptic('success')).toBe(false)
        })

        it('returns false when the underlying vibrate call throws', () => {
            vibrateMock.mockImplementation(() => {
                throw new Error('vibration blocked by user agent')
            })

            expect(triggerHaptic('error')).toBe(false)
        })
    })

    describe('stopVibration', () => {
        it('cancels an ongoing vibration', () => {
            stopVibration()

            expect(vibrateMock).toHaveBeenCalledWith(0)
        })

        it('returns false when vibration is unsupported', () => {
            withoutVibrationSupport()

            expect(stopVibration()).toBe(false)
        })

        it('returns false when the underlying vibrate call throws', () => {
            vibrateMock.mockImplementation(() => {
                throw new Error('vibration blocked by user agent')
            })

            expect(stopVibration()).toBe(false)
        })
    })

    describe('useHaptics', () => {
        it('exposes the helpers and the pattern table', () => {
            const haptics = useHaptics()

            expect(haptics.isSupported).toBe(true)
            expect(haptics.triggerHaptic).toBe(triggerHaptic)
            expect(haptics.stopVibration).toBe(stopVibration)
            expect(Object.keys(haptics.patterns)).toContain('timer')
        })
    })
})

/**
 * An unknown pattern name degrades silently to `tap`, so a typo produces a 5ms
 * blip instead of the intended feedback with nothing failing. This sweep is what
 * catches it: `usePullToRefresh` shipped `triggerHaptic('time')` for exactly that
 * reason.
 */
describe('haptic pattern names used across the app', () => {
    const jsRoot = resolve(__dirname, '../../../resources/js')

    const collectVueAndJsFiles = (dir) =>
        readdirSync(dir).flatMap((entry) => {
            const fullPath = join(dir, entry)

            if (statSync(fullPath).isDirectory()) {
                return collectVueAndJsFiles(fullPath)
            }

            return /\.(vue|js)$/.test(entry) ? [fullPath] : []
        })

    it('only references patterns that actually exist', () => {
        const { patterns } = useHaptics()
        const knownPatterns = Object.keys(patterns)
        const usagePattern = /(?:triggerHaptic\(|haptic:\s*)['"]([a-zA-Z-]+)['"]/g

        const invalidUsages = collectVueAndJsFiles(jsRoot).flatMap((file) => {
            const contents = readFileSync(file, 'utf8')

            return [...contents.matchAll(usagePattern)]
                .map((match) => match[1])
                .filter((name) => !knownPatterns.includes(name))
                .map((name) => `${file.replace(jsRoot, 'resources/js')}: '${name}'`)
        })

        expect(invalidUsages).toEqual([])
    })
})
