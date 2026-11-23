<script setup>
import { Link } from '@inertiajs/vue3'
import { workoutDurationMinutes } from '@/Utils/workoutDuration'
import { nombre, volume } from '@/Utils/nombre'

defineProps({
    recentWorkouts: { type: Array, required: true },
})

const jourDeLaSeance = (seance) =>
    new Date(seance.started_at).toLocaleDateString('fr-FR', {
        weekday: 'long',
        day: 'numeric',
        month: 'short',
    })
</script>

<template>
    <section v-if="recentWorkouts.length > 0" class="stagger-4 animate-slide-up" dusk="dashboard-dernieres-seances">
        <div class="mb-4 flex items-center justify-between px-1">
            <h3 class="titre-plat">Dernières séances</h3>
            <Link
                :href="route('workouts.index')"
                class="text-text-muted hover:text-text-main focus-visible:ring-accent-primary relative rounded-md text-sm font-medium transition-colors before:absolute before:-inset-x-2 before:-inset-y-3.5 before:content-[''] focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                Voir tout
            </Link>
        </div>

        <div class="flex flex-col gap-3">
            <Link
                v-for="seance in recentWorkouts.slice(0, 3)"
                :key="seance.id"
                v-press
                :href="route('workouts.show', { workout: seance.id })"
                class="verre-fin rounded-carte focus-visible:ring-accent-primary flex items-center justify-between gap-3 p-4 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                <div class="min-w-0">
                    <h4 class="font-display text-text-main truncate text-lg leading-tight font-semibold">
                        {{ seance.name || 'Séance' }}
                    </h4>
                    <p class="text-text-muted mt-1 text-xs tabular-nums">
                        {{ jourDeLaSeance(seance) }}
                        ·
                        <template v-if="seance.ended_at">{{ nombre(workoutDurationMinutes(seance), 0) }} min</template>
                        <span v-else class="glass-badge glass-badge-warning">En cours</span>
                    </p>
                </div>
                <span v-if="seance.workout_volume > 0" class="text-text-main shrink-0 text-sm font-medium tabular-nums">
                    {{ volume(seance.workout_volume) }}
                </span>
            </Link>
        </div>
    </section>
</template>
