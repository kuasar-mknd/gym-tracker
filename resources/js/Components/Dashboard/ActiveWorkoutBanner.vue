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
        class="animate-fade-in group relative block overflow-hidden rounded-3xl border-2 border-emerald-400/40 transition-all duration-300 hover:-translate-y-1 hover:shadow-2xl focus-visible:ring-2 focus-visible:ring-emerald-400 focus-visible:ring-offset-2 focus-visible:ring-offset-slate-900 focus-visible:outline-none active:scale-[0.98]"
        dusk="active-workout-banner"
    >
        <!-- Animated gradient background -->
        <div class="absolute inset-0 bg-linear-to-r from-emerald-500 via-teal-500 to-cyan-500 opacity-90"></div>
        <div
            class="absolute inset-0 bg-linear-to-r from-emerald-400 via-teal-400 to-cyan-400 opacity-0 transition-opacity duration-500 group-hover:opacity-90"
        ></div>

        <!-- Pulse ring effect -->
        <div class="absolute top-4 right-4 flex items-center gap-2">
            <span class="relative flex size-3">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-white opacity-75"></span>
                <span class="relative inline-flex size-3 rounded-full bg-white"></span>
            </span>
            <span class="text-xs font-black tracking-widest text-white/90 uppercase">En cours</span>
        </div>

        <div class="relative z-10 flex items-center gap-4 p-5">
            <!-- Icon -->
            <div
                class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-white/20 backdrop-blur-sm transition-transform duration-300 group-hover:scale-110"
            >
                <span class="material-symbols-outlined text-3xl text-white" style="font-variation-settings: 'FILL' 1"
                    >fitness_center</span
                >
            </div>

            <!-- Content -->
            <div class="min-w-0 flex-1">
                <p class="text-[10px] font-black tracking-[0.2em] text-white/70 uppercase">Séance active</p>
                <h3 class="font-display truncate text-xl font-black text-white uppercase italic">
                    {{ workout.name || 'Séance' }}
                </h3>
                <div class="mt-1 flex items-center gap-3">
                    <span class="flex items-center gap-1 text-sm font-bold text-white/80">
                        <span class="material-symbols-outlined text-[16px]">timer</span>
                        {{ elapsed }}
                    </span>
                    <span
                        v-if="workout.workout_lines_count"
                        class="flex items-center gap-1 text-sm font-bold text-white/80"
                    >
                        <span class="material-symbols-outlined text-[16px]">exercise</span>
                        {{ workout.workout_lines_count }} exos
                    </span>
                </div>
            </div>

            <!-- Arrow -->
            <div class="flex shrink-0 items-center">
                <span
                    class="material-symbols-outlined text-2xl text-white/60 transition-transform duration-300 group-hover:translate-x-1 group-hover:text-white"
                    >arrow_forward_ios</span
                >
            </div>
        </div>
    </Link>
</template>
