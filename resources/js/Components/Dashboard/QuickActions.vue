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
    <section class="stagger-2 animate-slide-up grid grid-cols-2 gap-4">
        <!-- Démarrer : l'action principale, seule surface orange de l'écran -->
        <button
            v-press
            @click="emit('startWorkout')"
            :disabled="processing"
            :aria-busy="processing"
            aria-label="Démarrer une nouvelle séance d'entraînement"
            id="start-workout-button"
            dusk="start-workout-button"
            class="accent-fill focus-visible:ring-accent-primary flex h-44 flex-col items-center justify-center gap-3 rounded-2xl p-4 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
            :class="{ 'cursor-wait': processing }"
        >
            <GlassIcon v-if="processing" name="autorenew" size="xl" class="animate-spin" />
            <GlassIcon v-else name="fitness_center" size="xl" />
            <span class="font-display text-center text-xl leading-tight font-semibold">Démarrer<br />séance</span>
        </button>

        <!-- Modèles -->
        <Link
            v-press
            :href="route('templates.index')"
            aria-label="Voir mes modèles d'entraînement"
            class="carte-plate focus-visible:ring-accent-primary flex h-44 flex-col items-center justify-center gap-3 rounded-2xl p-4 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
        >
            <GlassIcon name="assignment_add" size="xl" class="text-text-muted" />
            <span class="font-display text-text-main text-center text-xl leading-tight font-semibold">
                Mes<br />modèles
            </span>
        </Link>
    </section>
</template>
