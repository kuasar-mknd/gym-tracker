import { describe, it, expect, beforeEach, vi, afterEach } from 'vitest'
import { nextTick } from 'vue'

describe('useTheme composable', () => {
    let originalMatchMedia
    let matchMediaMock
    let changeListener

    beforeEach(async () => {
        // Reset localStorage
        localStorage.clear()

        // Reset document classes
        document.documentElement.className = ''

        // Mock matchMedia
        changeListener = null
        matchMediaMock = vi.fn().mockImplementation((query) => {
            return {
                matches: false,
                media: query,
                onchange: null,
                addListener: vi.fn(), // Deprecated
                removeListener: vi.fn(), // Deprecated
                addEventListener: vi.fn((event, callback) => {
                    if (event === 'change') {
                        changeListener = callback
                    }
                }),
                removeEventListener: vi.fn(),
                dispatchEvent: vi.fn(),
            }
        })

        originalMatchMedia = window.matchMedia
        window.matchMedia = matchMediaMock

        vi.resetModules()
    })

    afterEach(() => {
        window.matchMedia = originalMatchMedia
        vi.restoreAllMocks()
    })

    describe('initTheme', () => {
        it('should default to system light theme if no localStorage and matchMedia is light', async () => {
            matchMediaMock.mockReturnValueOnce({ matches: false, addEventListener: vi.fn() })

            const { initTheme, useTheme } = await import('@/composables/useTheme')
            initTheme()

            const { theme, isDark } = useTheme()
            expect(theme.value).toBe('system')
            expect(isDark.value).toBe(false)
            expect(document.documentElement.classList.contains('dark')).toBe(false)
        })

        it('should default to system dark theme if no localStorage and matchMedia is dark', async () => {
            matchMediaMock.mockReturnValue({ matches: true, addEventListener: vi.fn() })

            const { initTheme, useTheme } = await import('@/composables/useTheme')
            initTheme()

            const { theme, isDark } = useTheme()
            expect(theme.value).toBe('system')
            expect(isDark.value).toBe(true)
            expect(document.documentElement.classList.contains('dark')).toBe(true)
        })

        it('should initialize with stored theme "dark"', async () => {
            localStorage.setItem('gymtracker-theme', 'dark')

            const { initTheme, useTheme } = await import('@/composables/useTheme')
            initTheme()

            const { theme, isDark } = useTheme()
            expect(theme.value).toBe('dark')
            expect(isDark.value).toBe(true)
            expect(document.documentElement.classList.contains('dark')).toBe(true)
        })

        it('should initialize with stored theme "light"', async () => {
            localStorage.setItem('gymtracker-theme', 'light')
            matchMediaMock.mockReturnValue({ matches: true, addEventListener: vi.fn() })

            const { initTheme, useTheme } = await import('@/composables/useTheme')
            initTheme()

            const { theme, isDark } = useTheme()
            expect(theme.value).toBe('light')
            expect(isDark.value).toBe(false)
            expect(document.documentElement.classList.contains('dark')).toBe(false)
        })

        it('should not re-initialize if already initialized', async () => {
            const { initTheme, useTheme } = await import('@/composables/useTheme')
            initTheme()

            const { theme } = useTheme()
            theme.value = 'dark' // Manually change theme

            // Should not overwrite with localStorage/system
            initTheme()
            expect(theme.value).toBe('dark')
        })

        it('should listen to system preference changes when theme is system', async () => {
            const { initTheme, useTheme } = await import('@/composables/useTheme')
            initTheme()

            const { theme, isDark } = useTheme()
            expect(theme.value).toBe('system')
            expect(isDark.value).toBe(false)

            // Simulate system theme change to dark
            matchMediaMock.mockReturnValue({ matches: true, addEventListener: vi.fn() })
            if (changeListener) {
                changeListener({ matches: true })
            }

            expect(isDark.value).toBe(true)
            expect(document.documentElement.classList.contains('dark')).toBe(true)
        })

        it('should ignore system preference changes when theme is explicitly set', async () => {
            localStorage.setItem('gymtracker-theme', 'light')
            const { initTheme, useTheme } = await import('@/composables/useTheme')
            initTheme()

            const { theme, isDark } = useTheme()
            expect(theme.value).toBe('light')
            expect(isDark.value).toBe(false)

            // Simulate system theme change to dark
            matchMediaMock.mockReturnValue({ matches: true, addEventListener: vi.fn() })
            if (changeListener) {
                changeListener({ matches: true })
            }

            // Should stay light
            expect(isDark.value).toBe(false)
            expect(document.documentElement.classList.contains('dark')).toBe(false)
        })
    })

    describe('setTheme', () => {
        it('should change theme to dark, update localStorage and DOM', async () => {
            const { useTheme } = await import('@/composables/useTheme')
            const { setTheme, theme, isDark } = useTheme()

            setTheme('dark')

            expect(theme.value).toBe('dark')
            expect(isDark.value).toBe(true)
            expect(localStorage.getItem('gymtracker-theme')).toBe('dark')
            expect(document.documentElement.classList.contains('dark')).toBe(true)
        })

        it('should change theme to light, update localStorage and DOM', async () => {
            const { useTheme } = await import('@/composables/useTheme')
            const { setTheme, theme, isDark } = useTheme()

            setTheme('dark')
            expect(document.documentElement.classList.contains('dark')).toBe(true)

            setTheme('light')

            expect(theme.value).toBe('light')
            expect(isDark.value).toBe(false)
            expect(localStorage.getItem('gymtracker-theme')).toBe('light')
            expect(document.documentElement.classList.contains('dark')).toBe(false)
        })

        it('should change theme to system and apply current system preference', async () => {
            matchMediaMock.mockReturnValue({ matches: true, addEventListener: vi.fn() }) // System is dark

            const { useTheme } = await import('@/composables/useTheme')
            const { setTheme, theme, isDark } = useTheme()

            setTheme('system')

            expect(theme.value).toBe('system')
            expect(isDark.value).toBe(true)
            expect(localStorage.getItem('gymtracker-theme')).toBe('system')
            expect(document.documentElement.classList.contains('dark')).toBe(true)
        })
    })

    describe('toggleTheme', () => {
        it('should cycle through themes correctly: system -> light -> dark -> system', async () => {
            const { useTheme } = await import('@/composables/useTheme')
            const { toggleTheme, theme, setTheme } = useTheme()

            setTheme('system')
            expect(theme.value).toBe('system')

            toggleTheme()
            expect(theme.value).toBe('light')

            toggleTheme()
            expect(theme.value).toBe('dark')

            toggleTheme()
            expect(theme.value).toBe('system')
        })
    })
})
