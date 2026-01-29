<script setup>
/**
 * ThemeToggle.vue
 *
 * A toggle button that cycles through theme modes: System → Light → Dark
 * Shows the current mode with an icon and label.
 */
import { useTheme } from '@/composables/useTheme'
import { triggerHaptic } from '@/composables/useHaptics'

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
    triggerHaptic('tap')
    toggleTheme()
}
</script>

<template>
    <button
        @click="handleToggle"
        class="group flex w-full items-center gap-3 rounded-2xl border border-white/20 bg-white/10 px-4 py-3 text-left backdrop-blur-md transition-all duration-300 hover:scale-[1.02] hover:bg-white/20 hover:shadow-[0_0_15px_rgba(255,255,255,0.1)] active:scale-95"
    >
        <div
            class="flex h-10 w-10 items-center justify-center rounded-xl backdrop-blur-sm transition-colors duration-300"
            :class="[isDark ? 'bg-violet-500/20 text-violet-400' : 'bg-orange-500/20 text-orange-500']"
        >
            <span class="material-symbols-outlined text-2xl">
                {{ themeIcons[theme] }}
            </span>
        </div>
        <div class="flex-1">
            <div class="font-display text-text-main text-sm font-black tracking-wide uppercase dark:text-white">
                Thème
            </div>
            <div class="text-text-muted text-xs font-medium dark:text-slate-400">
                {{ themeLabels[theme] }}
            </div>
        </div>
        <span
            class="material-symbols-outlined text-text-muted transition-transform duration-300 group-hover:translate-x-1 dark:text-slate-400"
        >
            chevron_right
        </span>
    </button>
</template>
