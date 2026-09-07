<script setup>
import { Link } from '@inertiajs/vue3'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { defineAsyncComponent } from 'vue'
import { workoutDurationMinutes } from '@/Utils/workoutDuration'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'

const RecentWorkoutsTimelineChart = defineAsyncComponent(
    () => import('@/Components/Stats/RecentWorkoutsTimelineChart.vue'),
)

defineProps({
    recentWorkouts: { type: Array, required: true },
    processing: { type: Boolean, default: false },
})

const emit = defineEmits(['startWorkout'])
</script>

<template>
    <!-- Recent Activity -->
    <section class="stagger-4 animate-slide-up">
        <div class="mb-5 flex items-center justify-between px-1">
            <h3 class="text-text-muted sur-titre">Activité Récente</h3>
            <Link
                :href="route('workouts.index')"
                class="text-accent-primary-deep hover:text-accent-tertiary-deep focus-visible:ring-accent-primary rounded-md text-xs font-bold tracking-wider uppercase transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                Voir tout
            </Link>
        </div>

        <!-- Empty State -->
        <GlassEmptyState
            v-if="recentWorkouts.length === 0"
            icon="🏋️"
            title="Aucune séance pour l'instant"
            description="Commence ton parcours fitness !"
        >
            <template #action>
                <GlassButton variant="primary" :loading="processing" class="mx-auto" @click="emit('startWorkout')">
                    Démarrer une séance
                </GlassButton>
            </template>
        </GlassEmptyState>

        <!-- Activity Cards and Chart -->
        <div v-else class="flex flex-col gap-3">
            <div
                class="border-surface-card/20 bg-surface-card/10 relative mb-2 overflow-hidden rounded-3xl border p-4 backdrop-blur-md"
            >
                <div class="sur-titre text-accent-tertiary-deep mb-4">Durée des séances</div>
                <RecentWorkoutsTimelineChart :data="recentWorkouts" />
            </div>

            <Link
                v-for="(workout, index) in recentWorkouts"
                :key="workout.id"
                v-press
                :href="route('workouts.show', { workout: workout.id })"
                class="group focus-visible:ring-accent-primary border-surface-card/20 bg-surface-card/10 hover:bg-surface-card/20 relative flex items-center justify-between rounded-3xl border p-4 backdrop-blur-md transition duration-300 hover:-translate-y-1 hover:shadow-lg focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none active:scale-95"
            >
                <!-- Color indicator -->
                <div
                    class="absolute top-1/2 left-0 h-10 w-1.5 -translate-y-1/2 rounded-r-md"
                    :class="[
                        index === 0
                            ? 'bg-accent-tertiary'
                            : index === 1
                              ? 'bg-accent-primary'
                              : index === 2
                                ? 'bg-accent-secondary'
                                : index === 3
                                  ? 'bg-accent-info'
                                  : 'bg-accent-state',
                    ]"
                ></div>

                <div class="flex items-center gap-4 pl-3">
                    <div
                        :class="[
                            'flex size-12 items-center justify-center rounded-xl border',
                            index === 0
                                ? 'text-accent-tertiary-deep border-accent-tertiary/20 bg-accent-tertiary/10'
                                : index === 1
                                  ? 'text-accent-primary-deep border-accent-primary/20 bg-accent-primary/10'
                                  : 'text-accent-secondary-deep border-accent-secondary/20 bg-accent-secondary/10',
                        ]"
                    >
                        <GlassIcon :name="(workout.workout_lines_count || 0) > 3 ? 'timer' : 'fitness_center'" />
                    </div>
                    <div>
                        <h4 class="font-display text-text-main text-lg leading-tight font-bold uppercase italic">
                            {{ workout.name || 'Séance' }}
                        </h4>
                        <p class="text-text-muted mt-1 text-xs font-bold">
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
                <div class="flex flex-col items-end">
                    <span v-if="workout.ended_at" class="glass-badge glass-badge-success">Fait</span>
                    <span v-else class="glass-badge glass-badge-warning animate-pulse">En cours</span>
                    <span class="text-text-muted mt-1 font-mono text-xs">
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
