<script setup>
import { Link } from '@inertiajs/vue3'
import GlassIcon from '@/Components/UI/GlassIcon.vue'

defineProps({
    processing: { type: Boolean, default: false },
})

/*
 * Démarrer reste ouvert sur une copie hors ligne (#2049) : la séance démarre
 * par la file, sans rien tirer de la copie (`useDemarrageDUneSeance`).
 */
const emit = defineEmits(['startWorkout'])
</script>

<template>
    <section class="stagger-2 animate-slide-up grid grid-cols-2 gap-3">
        <!-- Démarrer une séance : l'action principale, seul aplat orange de l'écran. -->
        <button
            v-press
            @click="emit('startWorkout')"
            :disabled="processing"
            :aria-busy="processing"
            id="start-workout-button"
            dusk="start-workout-button"
            class="accent-fill verre-plein rounded-carte focus-visible:ring-accent-primary flex min-h-28 flex-col items-start justify-between p-4 text-left text-base leading-tight font-medium focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
            :class="{ 'cursor-wait': processing }"
        >
            <span class="bg-surface-card/20 rounded-icone flex size-10 items-center justify-center">
                <GlassIcon v-if="processing" name="autorenew" size="lg" class="animate-spin" />
                <GlassIcon v-else name="fitness_center" size="lg" />
            </span>
            Démarrer une séance
        </button>

        <!-- Modèles -->
        <Link
            v-press
            :href="route('templates.index')"
            class="verre-regulier rounded-carte text-text-main focus-visible:ring-accent-primary flex min-h-28 flex-col items-start justify-between p-4 text-base leading-tight font-medium focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
        >
            <span class="bg-surface-glass-strong rounded-icone flex size-10 items-center justify-center">
                <GlassIcon name="assignment_add" size="lg" />
            </span>
            Mes modèles
        </Link>
    </section>
</template>
