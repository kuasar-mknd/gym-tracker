<script setup>
import { Link } from '@inertiajs/vue3'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { graphiqueAsynchrone } from '@/Utils/graphiqueAsynchrone'
import { workoutDurationMinutes } from '@/Utils/workoutDuration'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'

const RecentWorkoutsTimelineChart = graphiqueAsynchrone(
    () => import('@/Components/Stats/RecentWorkoutsTimelineChart.vue'),
)

defineProps({
    recentWorkouts: { type: Array, required: true },
    processing: { type: Boolean, default: false },
})

/** Démarrer reste ouvert sur une copie hors ligne : la séance démarre par la file (#2049). */
const emit = defineEmits(['startWorkout'])
</script>

<template>
    <!-- Recent Activity -->
    <section class="stagger-4 animate-slide-up">
        <div class="mb-4 flex items-center justify-between px-1">
            <h3 class="titre-plat">Activité récente</h3>
            <Link
                :href="route('workouts.index')"
                class="text-text-muted hover:text-text-main focus-visible:ring-accent-primary relative rounded-md text-sm font-medium transition-colors before:absolute before:-inset-x-2 before:-inset-y-3.5 before:content-[''] focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                Voir tout
            </Link>
        </div>

        <!-- Empty State -->
        <GlassEmptyState
            v-if="recentWorkouts.length === 0"
            icon="fitness_center"
            title="Aucune séance pour l'instant"
            description="Démarre ta première séance."
        >
            <template #action>
                <GlassButton variant="primary" :loading="processing" class="mx-auto" @click="emit('startWorkout')">
                    Démarrer une séance
                </GlassButton>
            </template>
        </GlassEmptyState>

        <!-- Activity Cards and Chart -->
        <div v-else class="flex flex-col gap-3">
            <div class="verre-fin rounded-carte relative mb-2 overflow-hidden p-4">
                <div class="etiquette mb-4">Durée des séances</div>
                <RecentWorkoutsTimelineChart :data="recentWorkouts" />
            </div>

            <Link
                v-for="workout in recentWorkouts"
                :key="workout.id"
                v-press
                :href="route('workouts.show', { workout: workout.id })"
                class="verre-fin rounded-carte focus-visible:ring-accent-primary relative flex items-center justify-between gap-3 p-4 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                <div class="flex min-w-0 items-center gap-3">
                    <div
                        class="bg-surface-glass-strong text-text-main rounded-icone flex size-11 shrink-0 items-center justify-center"
                    >
                        <GlassIcon :name="(workout.workout_lines_count || 0) > 3 ? 'timer' : 'fitness_center'" />
                    </div>
                    <div class="min-w-0">
                        <h4 class="font-display text-text-main truncate text-lg leading-tight font-semibold">
                            {{ workout.name || 'Séance' }}
                        </h4>
                        <p class="text-text-muted mt-1 text-xs tabular-nums">
                            {{
                                new Date(workout.started_at).toLocaleDateString('fr-FR', {
                                    weekday: 'long',
                                    day: 'numeric',
                                    month: 'short',
                                })
                            }}
                            •
                            {{ workoutDurationMinutes(workout) ?? '--' }}
                            min
                        </p>
                    </div>
                </div>
                <div class="flex shrink-0 flex-col items-end">
                    <span v-if="workout.ended_at" class="glass-badge glass-badge-success">Fait</span>
                    <span v-else class="glass-badge glass-badge-warning animate-pulse">En cours</span>
                    <span class="text-text-muted mt-1 text-xs tabular-nums">
                        {{
                            new Date(workout.started_at).toLocaleTimeString('fr-FR', {
                                hour: '2-digit',
                                minute: '2-digit',
                            })
                        }}
                    </span>
                </div>
            </Link>
        </div>
    </section>
</template>
