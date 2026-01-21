<script setup>
/**
 * ThemeToggle.vue
 *
 * A toggle button that cycles through theme modes: System → Light → Dark
 * Shows the current mode with an icon and label.
 */
import { useTheme } from '@/composables/useTheme'
import { vibrate } from '@/composables/useHaptics'

const { theme, isDark, toggleTheme } = useTheme()

const themeIcons = {
    system: 'brightness_auto',
    light: 'light_mode',
    dark: 'dark_mode',
}

const themeLabels = {
    system: 'Système',
    light: 'Clair',
    dark: 'Sombre',
}

function handleToggle() {
    vibrate('tap')
    toggleTheme()
}
</script>

<template>
    <button
        @click="handleToggle"
        class="flex items-center gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 transition-all hover:bg-slate-50 active:scale-95 dark:border-slate-700 dark:bg-slate-800 dark:hover:bg-slate-700"
    >
        <div
            class="flex h-10 w-10 items-center justify-center rounded-lg transition-colors"
            :class="[isDark ? 'bg-violet-500/20 text-violet-400' : 'bg-orange-500/20 text-orange-500']"
        >
            <span class="material-symbols-outlined text-2xl">
                {{ themeIcons[theme] }}
            </span>
        </div>
        <div class="text-left">
            <div class="text-text-main text-sm font-bold dark:text-white">Thème</div>
            <div class="text-text-muted text-xs dark:text-slate-400">
                {{ themeLabels[theme] }}
            </div>
        </div>
        <span class="material-symbols-outlined text-text-muted ml-auto dark:text-slate-400"> chevron_right </span>
    </button>
</template>
