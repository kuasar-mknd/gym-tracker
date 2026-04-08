import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest'
import { isHapticsSupported, triggerHaptic, stopVibration, useHaptics } from '../../resources/js/composables/useHaptics.js'

describe('useHaptics composable', () => {
  let originalNavigator

  beforeEach(() => {
    // Save original navigator to restore later
    originalNavigator = global.navigator

    // Create a mock navigator with a mocked vibrate function
    global.navigator = {
      vibrate: vi.fn().mockReturnValue(true)
    }
  })

  afterEach(() => {
    // Restore original navigator
    global.navigator = originalNavigator
    vi.restoreAllMocks()
  })

  describe('isHapticsSupported', () => {
    it('returns true when navigator.vibrate exists', () => {
      expect(isHapticsSupported()).toBe(true)
    })

    it('returns false when navigator is undefined', () => {
      const nav = global.navigator
      global.navigator = undefined
      expect(isHapticsSupported()).toBe(false)
      global.navigator = nav
    })

    it('returns false when vibrate is not in navigator', () => {
      global.navigator = {}
      expect(isHapticsSupported()).toBe(false)
    })
  })

  describe('triggerHaptic', () => {
    it('calls navigator.vibrate with tap pattern by default', () => {
      triggerHaptic()
      expect(global.navigator.vibrate).toHaveBeenCalledWith([5])
    })

    it('calls navigator.vibrate with correct pattern when type is specified', () => {
      triggerHaptic('success')
      expect(global.navigator.vibrate).toHaveBeenCalledWith([50, 30, 50])
    })

    it('falls back to tap pattern for unknown types', () => {
      triggerHaptic('unknown_type')
      expect(global.navigator.vibrate).toHaveBeenCalledWith([5])
    })

    it('returns false and does not call vibrate if not supported', () => {
      global.navigator = {} // vibrate missing
      const result = triggerHaptic()
      expect(result).toBe(false)
    })

    it('handles navigator.vibrate throwing an error', () => {
      global.navigator.vibrate = vi.fn().mockImplementation(() => {
        throw new Error('Vibration failed')
      })
      const result = triggerHaptic()
      expect(result).toBe(false)
    })
  })

  describe('stopVibration', () => {
    it('calls navigator.vibrate(0)', () => {
      stopVibration()
      expect(global.navigator.vibrate).toHaveBeenCalledWith(0)
    })

    it('returns false and does not call vibrate if not supported', () => {
      global.navigator = {}
      const result = stopVibration()
      expect(result).toBe(false)
    })

    it('handles navigator.vibrate throwing an error', () => {
      global.navigator.vibrate = vi.fn().mockImplementation(() => {
        throw new Error('Vibration failed')
      })
      const result = stopVibration()
      expect(result).toBe(false)
    })
  })

  describe('useHaptics', () => {
    it('returns an object with expected properties', () => {
      const haptics = useHaptics()
      expect(haptics).toHaveProperty('isSupported')
      expect(haptics).toHaveProperty('triggerHaptic')
      expect(haptics).toHaveProperty('stopVibration')
      expect(haptics).toHaveProperty('patterns')
      expect(haptics.isSupported).toBe(true)
    })
  })
})
