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
        class="group relative flex w-full items-center gap-4 rounded-2xl border border-white/20 bg-white/10 px-4 py-4 backdrop-blur-md transition-all duration-300 hover:scale-[1.02] hover:bg-white/20 hover:shadow-xl active:scale-95"
    >
        <!-- Icon Container -->
        <div
            class="flex h-12 w-12 shrink-0 items-center justify-center rounded-xl transition-all duration-500 group-hover:rotate-12"
            :class="[
                isDark
                    ? 'bg-violet-500/20 text-violet-300 shadow-[0_0_15px_rgba(139,92,246,0.3)]'
                    : 'bg-orange-500/20 text-orange-300 shadow-[0_0_15px_rgba(249,115,22,0.3)]',
            ]"
        >
            <span class="material-symbols-outlined text-2xl">
                {{ themeIcons[theme] }}
            </span>
        </div>

        <!-- Text -->
        <div class="flex-1 text-left">
            <div class="text-sm font-bold text-white shadow-sm">Thème</div>
            <div class="text-xs font-medium text-white/60">
                {{ themeLabels[theme] }}
            </div>
        </div>

        <!-- Chevron -->
        <span class="material-symbols-outlined text-white/40 transition-transform duration-300 group-hover:translate-x-1">
            chevron_right
        </span>

        <!-- Shine effect -->
        <div class="absolute inset-0 -z-10 rounded-2xl bg-linear-to-br from-white/5 to-transparent opacity-0 transition-opacity duration-500 group-hover:opacity-100"></div>
    </button>
</template>
