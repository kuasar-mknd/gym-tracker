<script setup>
import { Link } from '@inertiajs/vue3'
import GlassButton from '@/Components/UI/GlassButton.vue'

defineProps({
    recentWorkouts: { type: Array, required: true },
    processing: { type: Boolean, default: false },
})

const emit = defineEmits(['startWorkout'])

const colorForWorkout = (index) => {
    const colors = ['violet', 'orange', 'pink', 'cyan', 'green']
    return colors[index % colors.length]
}
</script>

<template>
    <!-- Recent Activity -->
    <section class="animate-slide-up" style="animation-delay: 0.2s">
        <div class="mb-5 flex items-center justify-between px-1">
            <h3 class="text-text-muted text-xs font-black tracking-[0.2em] uppercase">Activité Récente</h3>
            <Link
                :href="route('workouts.index')"
                class="text-electric-orange hover:text-vivid-violet text-xs font-bold tracking-wider uppercase transition-colors"
            >
                Voir tout
            </Link>
        </div>

        <!-- Empty State -->
        <div
            v-if="recentWorkouts.length === 0"
            class="rounded-3xl border border-white/20 bg-white/10 p-8 text-center backdrop-blur-md"
        >
            <div class="mb-3 text-5xl" aria-hidden="true">🏋️</div>
            <p class="text-text-main font-bold dark:text-white">Aucune séance pour l'instant</p>
            <p class="text-text-muted mt-1 mb-5 text-sm">Commence ton parcours fitness !</p>
            <GlassButton variant="primary" @click="emit('startWorkout')" :loading="processing" class="mx-auto">
                Démarrer une séance
            </GlassButton>
        </div>

        <!-- Activity Cards -->
        <div v-else class="flex flex-col gap-3">
            <Link
                v-for="(workout, index) in recentWorkouts"
                :key="workout.id"
                v-press
                :href="route('workouts.show', { workout: workout.id })"
                class="group relative flex items-center justify-between rounded-3xl border border-white/20 bg-white/10 p-4 backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:bg-white/20 hover:shadow-lg active:scale-95"
            >
                <!-- Color indicator -->
                <div
                    class="absolute top-1/2 left-0 h-10 w-1.5 -translate-y-1/2 rounded-r-md"
                    :class="[
                        index === 0
                            ? 'bg-vivid-violet'
                            : index === 1
                              ? 'bg-electric-orange'
                              : index === 2
                                ? 'bg-hot-pink'
                                : index === 3
                                  ? 'bg-cyan-pure'
                                  : 'bg-green-500',
                    ]"
                ></div>

                <div class="flex items-center gap-4 pl-3">
                    <div
                        :class="[
                            'flex size-12 items-center justify-center rounded-xl border',
                            index === 0
                                ? 'text-vivid-violet border-violet-100 bg-violet-50'
                                : index === 1
                                  ? 'text-electric-orange border-orange-100 bg-orange-50'
                                  : 'text-hot-pink border-pink-100 bg-pink-50',
                        ]"
                    >
                        <span class="material-symbols-outlined">
                            {{ (workout.workout_lines_count || 0) > 3 ? 'timer' : 'fitness_center' }}
                        </span>
                    </div>
                    <div>
                        <h4
                            class="font-display text-text-main text-lg leading-tight font-bold uppercase italic dark:text-white"
                        >
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
                            {{
                                workout.duration_minutes ||
                                (workout.ended_at
                                    ? Math.round((new Date(workout.ended_at) - new Date(workout.started_at)) / 60000)
                                    : null) ||
                                '--'
                            }}
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
