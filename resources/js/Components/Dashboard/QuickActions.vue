<script setup>
import { Link } from '@inertiajs/vue3'

defineProps({
    processing: { type: Boolean, default: false },
})

const emit = defineEmits(['startWorkout'])
</script>

<template>
    <section class="animate-slide-up grid grid-cols-2 gap-4" style="animation-delay: 0.1s">
        <!-- Start Workout -->
        <button
            v-press
            @click="emit('startWorkout')"
            :disabled="processing"
            :aria-busy="processing"
            id="start-workout-button"
            dusk="start-workout-button"
            class="hover:shadow-glow-orange/70 group shadow-glow-orange relative h-52 overflow-hidden rounded-3xl transition-all duration-300"
            :class="{ 'cursor-wait': processing }"
        >
            <div class="absolute inset-0 z-0 bg-white/60 backdrop-blur-md dark:bg-slate-800/60"></div>
            <div
                class="from-electric-orange/10 absolute inset-0 z-0 bg-linear-to-br to-transparent opacity-0 transition-opacity duration-300 group-hover:opacity-100"
            ></div>
            <div
                class="border-electric-orange/20 group-hover:border-electric-orange/60 absolute inset-0 z-10 rounded-3xl border-2 transition-colors"
            ></div>
            <div class="relative z-20 flex h-full flex-col items-center justify-center gap-3 p-4">
                <div
                    class="from-electric-orange to-hot-pink flex size-16 items-center justify-center rounded-2xl bg-linear-to-br shadow-lg shadow-orange-500/30 transition-transform duration-300 group-hover:scale-110"
                >
                    <span
                        v-if="processing"
                        class="material-symbols-outlined animate-spin text-4xl text-white"
                        aria-hidden="true"
                        >autorenew</span
                    >
                    <span v-else class="material-symbols-outlined text-4xl text-white" aria-hidden="true"
                        >fitness_center</span
                    >
                </div>
                <span
                    class="font-display text-text-main text-center text-xl leading-none font-black tracking-tight uppercase italic dark:text-white"
                >
                    Démarrer<br />Séance
                </span>
            </div>
        </button>

        <!-- Templates -->
        <Link
            v-press
            :href="route('templates.index')"
            class="hover:shadow-glow-violet/70 group shadow-glow-violet relative h-52 overflow-hidden rounded-3xl transition-all duration-300"
        >
            <div class="absolute inset-0 z-0 bg-white/60 backdrop-blur-md dark:bg-slate-800/60"></div>
            <div
                class="from-vivid-violet/10 absolute inset-0 z-0 bg-linear-to-br to-transparent opacity-0 transition-opacity duration-300 group-hover:opacity-100"
            ></div>
            <div
                class="border-vivid-violet/20 group-hover:border-vivid-violet/60 absolute inset-0 z-10 rounded-3xl border-2 transition-colors"
            ></div>
            <div class="relative z-20 flex h-full flex-col items-center justify-center gap-3 p-4">
                <div
                    class="from-vivid-violet to-hot-pink flex size-16 items-center justify-center rounded-2xl bg-linear-to-br shadow-lg shadow-purple-500/30 transition-transform duration-300 group-hover:scale-110"
                >
                    <span class="material-symbols-outlined text-4xl text-white">assignment_add</span>
                </div>
                <span
                    class="font-display text-text-main text-center text-xl leading-none font-black tracking-tight uppercase italic dark:text-white"
                >
                    Mes<br />Programmes
                </span>
            </div>
        </Link>
    </section>
</template>
