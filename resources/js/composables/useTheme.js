/**
 * useTheme - Theme Management Composable
 *
 * Manages dark/light mode with system preference detection
 * and localStorage persistence.
 */
import { ref, watch, onMounted } from 'vue'

/**
 * @typedef {'system' | 'light' | 'dark'} ThemeMode
 */

const STORAGE_KEY = 'gymtracker-theme'

/** @type {import('vue').Ref<ThemeMode>} */
const theme = ref('system')

/** @type {import('vue').Ref<boolean>} */
const isDark = ref(false)

/**
 * Apply the theme to the document
 */
function applyTheme() {
    const root = document.documentElement

    if (theme.value === 'system') {
        // Follow system preference
        isDark.value = window.matchMedia('(prefers-color-scheme: dark)').matches
    } else {
        isDark.value = theme.value === 'dark'
    }

    if (isDark.value) {
        root.classList.add('dark')
    } else {
        root.classList.remove('dark')
    }
}

/**
 * Set the theme mode and persist to localStorage
 * @param {ThemeMode} mode
 */
function setTheme(mode) {
    theme.value = mode
    localStorage.setItem(STORAGE_KEY, mode)
    applyTheme()
}

/**
 * Cycle through themes: system -> light -> dark -> system
 */
function toggleTheme() {
    const modes = ['system', 'light', 'dark']
    const currentIndex = modes.indexOf(theme.value)
    const nextIndex = (currentIndex + 1) % modes.length
    setTheme(modes[nextIndex])
}

/**
 * Initialize theme from localStorage or default to system
 */
function initTheme() {
    const stored = localStorage.getItem(STORAGE_KEY)
    if (stored && ['system', 'light', 'dark'].includes(stored)) {
        theme.value = stored
    }
    applyTheme()

    // Listen for system preference changes
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (theme.value === 'system') {
            applyTheme()
        }
    })
}

/**
 * Vue composable for theme management
 */
export function useTheme() {
    onMounted(() => {
        initTheme()
    })

    return {
        theme,
        isDark,
        setTheme,
        toggleTheme,
    }
}
