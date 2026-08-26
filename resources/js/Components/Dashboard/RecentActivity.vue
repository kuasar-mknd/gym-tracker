<script setup>
import { Link } from '@inertiajs/vue3'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { defineAsyncComponent } from 'vue'
import { workoutDurationMinutes } from '@/Utils/workoutDuration'

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
    <section class="animate-slide-up" style="animation-delay: 0.2s">
        <div class="mb-5 flex items-center justify-between px-1">
            <h3 class="text-text-muted text-xs font-black tracking-[0.2em] uppercase">Activité Récente</h3>
            <Link
                :href="route('workouts.index')"
                class="text-accent-primary-deep hover:text-accent-tertiary focus-visible:ring-accent-primary rounded-sm text-xs font-bold tracking-wider uppercase transition-colors focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                Voir tout
            </Link>
        </div>

        <!-- Empty State -->
        <div
            v-if="recentWorkouts.length === 0"
            class="border-surface-card/20 bg-surface-card/10 rounded-3xl border p-8 text-center backdrop-blur-md"
        >
            <div class="mb-3 text-5xl" aria-hidden="true">🏋️</div>
            <p class="text-text-main font-bold">Aucune séance pour l'instant</p>
            <p class="text-text-muted mt-1 mb-5 text-sm">Commence ton parcours fitness !</p>
            <GlassButton variant="primary" @click="emit('startWorkout')" :loading="processing" class="mx-auto">
                Démarrer une séance
            </GlassButton>
        </div>

        <!-- Activity Cards and Chart -->
        <div v-else class="flex flex-col gap-3">
            <div
                class="border-surface-card/20 bg-surface-card/10 relative mb-2 overflow-hidden rounded-3xl border p-4 backdrop-blur-md"
            >
                <div class="text-accent-tertiary mb-4 text-[10px] font-black tracking-[0.2em] uppercase">
                    Durée des séances
                </div>
                <RecentWorkoutsTimelineChart :data="recentWorkouts" />
            </div>

            <Link
                v-for="(workout, index) in recentWorkouts"
                :key="workout.id"
                v-press
                :href="route('workouts.show', { workout: workout.id })"
                class="group focus-visible:ring-accent-primary border-surface-card/20 bg-surface-card/10 hover:bg-surface-card/20 relative flex items-center justify-between rounded-3xl border p-4 backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:shadow-lg focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none active:scale-95"
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
                                ? 'text-accent-tertiary border-accent-tertiary/20 bg-accent-tertiary/10'
                                : index === 1
                                  ? 'text-accent-primary-deep border-accent-primary/20 bg-accent-primary/10'
                                  : 'text-accent-secondary-deep border-accent-secondary/20 bg-accent-secondary/10',
                        ]"
                    >
                        <span class="material-symbols-outlined" aria-hidden="true">
                            {{ (workout.workout_lines_count || 0) > 3 ? 'timer' : 'fitness_center' }}
                        </span>
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
