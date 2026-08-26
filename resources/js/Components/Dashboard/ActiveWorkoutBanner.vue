<script setup>
import { Link } from '@inertiajs/vue3'
import { ref, onMounted, onUnmounted } from 'vue'

const props = defineProps({
    workout: { type: Object, required: true },
})

const elapsed = ref('')
let intervalId = null

const updateElapsed = () => {
    const start = new Date(props.workout.started_at)
    const now = new Date()
    const diff = Math.floor((now - start) / 1000)
    const h = Math.floor(diff / 3600)
    const m = Math.floor((diff % 3600) / 60)
    const s = diff % 60
    elapsed.value = h > 0 ? `${h}h ${String(m).padStart(2, '0')}m` : `${m}m ${String(s).padStart(2, '0')}s`
}

onMounted(() => {
    updateElapsed()
    intervalId = setInterval(updateElapsed, 1000)
})

onUnmounted(() => {
    if (intervalId) clearInterval(intervalId)
})
</script>

<template>
    <Link
        v-press
        :href="route('workouts.show', { workout: workout.id })"
        class="animate-fade-in group border-accent-state/40 focus-visible:ring-accent-state relative block overflow-hidden rounded-3xl border-2 transition-all duration-300 hover:-translate-y-1 hover:shadow-2xl focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900 focus-visible:outline-none active:scale-[0.98]"
        dusk="active-workout-banner"
    >
        <!-- Animated gradient background -->
        <div class="from-accent-state via-accent-info to-accent-info absolute inset-0 bg-linear-to-r opacity-90"></div>
        <div
            class="from-accent-state via-accent-info to-accent-info absolute inset-0 bg-linear-to-r opacity-0 transition-opacity duration-500 group-hover:opacity-90"
        ></div>

        <!-- Pulse ring effect -->
        <div class="absolute top-4 right-4 flex items-center gap-2">
            <span class="relative flex size-3">
                <span
                    class="bg-surface-card absolute inline-flex h-full w-full animate-ping rounded-full opacity-75"
                ></span>
                <span class="bg-surface-card relative inline-flex size-3 rounded-full"></span>
            </span>
            <span class="text-text-on-dark-accent/90 text-xs font-black tracking-widest uppercase">En cours</span>
        </div>

        <div class="relative z-10 flex items-center gap-4 p-5">
            <!-- Icon -->
            <div
                class="bg-surface-card/20 flex size-14 shrink-0 items-center justify-center rounded-2xl backdrop-blur-sm transition-transform duration-300 group-hover:scale-110"
            >
                <span
                    class="material-symbols-outlined text-text-on-dark-accent text-3xl"
                    style="font-variation-settings: 'FILL' 1"
                    aria-hidden="true"
                    >fitness_center</span
                >
            </div>

            <!-- Content -->
            <div class="min-w-0 flex-1">
                <p class="text-text-on-dark-accent/70 text-[10px] font-black tracking-[0.2em] uppercase">
                    Séance active
                </p>
                <h3 class="font-display text-text-on-dark-accent truncate text-xl font-black uppercase italic">
                    {{ workout.name || 'Séance' }}
                </h3>
                <div class="mt-1 flex items-center gap-3">
                    <span class="text-text-on-dark-accent/80 flex items-center gap-1 text-sm font-bold">
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">timer</span>
                        {{ elapsed }}
                    </span>
                    <span
                        v-if="workout.workout_lines_count"
                        class="text-text-on-dark-accent/80 flex items-center gap-1 text-sm font-bold"
                    >
                        <span class="material-symbols-outlined text-[16px]" aria-hidden="true">exercise</span>
                        {{ workout.workout_lines_count }} exos
                    </span>
                </div>
            </div>

            <!-- Arrow -->
            <div class="flex shrink-0 items-center">
                <span
                    class="material-symbols-outlined text-text-on-dark-accent/60 group-hover:text-text-on-dark-accent text-2xl transition-transform duration-300 group-hover:translate-x-1"
                    aria-hidden="true"
                    >arrow_forward_ios</span
                >
            </div>
        </div>
    </Link>
</template>
