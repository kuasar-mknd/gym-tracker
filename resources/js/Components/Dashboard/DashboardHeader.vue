<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
    user: { type: Object, required: true },
    /**
     * The controller has always sent this and the dashboard never rendered it,
     * so the query ran on every page load to fill a prop nothing read.
     */
    latestWeight: { type: [Number, String], default: null },
})

/**
 * Arrives as a decimal string ("75.50"). Trailing zeros are noise on a body
 * weight, and the separator has to be the French one to match every other
 * figure on the page.
 */
const formattedWeight = computed(() => {
    if (props.latestWeight === null || props.latestWeight === '') {
        return null
    }

    const value = Number(props.latestWeight)

    return Number.isFinite(value) ? value.toLocaleString('fr-FR', { maximumFractionDigits: 1 }) : null
})
</script>

<template>
    <header
        id="dashboard-header"
        class="animate-fade-in flex items-center justify-between rounded-3xl border border-white/20 bg-white/10 p-4 shadow-lg backdrop-blur-md transition-all duration-300 hover:bg-white/20 hover:shadow-xl active:scale-[0.98]"
    >
        <div class="flex items-center gap-4">
            <!-- Avatar with gradient border -->
            <div class="relative">
                <div
                    class="from-electric-orange to-vivid-violet relative size-14 overflow-hidden rounded-full bg-linear-to-tr p-[2px]"
                >
                    <div
                        class="flex h-full w-full items-center justify-center overflow-hidden rounded-full border-2 border-white bg-white dark:border-slate-700 dark:bg-slate-800"
                    >
                        <span class="text-gradient text-2xl font-black">
                            {{ user.name?.charAt(0).toUpperCase() }}
                        </span>
                    </div>
                </div>
            </div>
            <div>
                <p
                    class="text-text-muted mb-0.5 text-[10px] font-black tracking-[0.2em] uppercase"
                    dusk="dashboard-welcome"
                >
                    BON RETOUR
                </p>
                <h1
                    class="font-display text-text-main text-3xl font-black tracking-tighter uppercase italic dark:text-white"
                >
                    {{ user.name?.split(' ')[0] }}
                </h1>
                <Link
                    v-if="formattedWeight"
                    :href="route('body-measurements.index')"
                    dusk="dashboard-latest-weight"
                    class="text-text-muted hover:text-text-main focus-visible:ring-electric-orange mt-1 inline-flex items-center gap-1 rounded-lg text-xs font-bold focus-visible:ring-2 focus-visible:outline-none"
                >
                    <span class="material-symbols-outlined text-[14px]" aria-hidden="true">monitor_weight</span>
                    {{ formattedWeight }} kg
                </Link>
            </div>
        </div>

        <!-- Streak Badge -->
        <div
            class="streak-badge group flex cursor-pointer items-center gap-1.5 rounded-2xl border border-white/20 bg-white/10 px-3 py-2 backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:bg-white/20 hover:shadow-lg active:scale-95"
        >
            <span
                class="material-symbols-outlined text-electric-orange text-[24px] transition-transform duration-300 group-hover:scale-110 group-hover:animate-pulse"
                style="font-variation-settings: 'FILL' 1"
                >local_fire_department</span
            >
            <span class="text-text-main text-xl font-black italic dark:text-white">
                {{ user.current_streak || 0 }}
                <span class="text-text-muted ml-0.5 text-[10px] font-bold uppercase not-italic">Jours</span>
            </span>
        </div>
    </header>
</template>
