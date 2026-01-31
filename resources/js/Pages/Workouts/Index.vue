<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { Head, useForm, Link } from '@inertiajs/vue3'
import { defineAsyncComponent } from 'vue'
import SwipeableRow from '@/Components/UI/SwipeableRow.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'
import { triggerHaptic } from '@/composables/useHaptics'
import { usePullToRefresh } from '@/composables/usePullToRefresh'

const WorkoutsPerMonthChart = defineAsyncComponent(() => import('@/Components/Stats/WorkoutsPerMonthChart.vue'))
const WorkoutDurationChart = defineAsyncComponent(() => import('@/Components/Stats/WorkoutDurationChart.vue'))
const VolumePerWorkoutChart = defineAsyncComponent(() => import('@/Components/Stats/VolumePerWorkoutChart.vue'))

const props = defineProps({
    workouts: Object, // Paginated data: { data: [...], links: {...}, meta: {...} }
    exercises: Array,
    monthlyFrequency: Array,
    durationHistory: Array,
    volumeHistory: Array,
})

const form = useForm({})

const createWorkout = () => {
    form.post(route('workouts.store'))
}

const formatDate = (dateStr) => {
    return new Date(dateStr).toLocaleDateString('fr-FR', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    })
}

const deleteForm = useForm({})
const confirmDeletion = (workout) => {
    if (confirm(`Êtes-vous sûr de vouloir supprimer la séance "${workout.name || 'Séance'}" ?`)) {
        // Optimistic UI
        const index = props.workouts.data.findIndex((w) => w.id === workout.id)
        if (index === -1) return

        const removedWorkout = props.workouts.data[index]
        props.workouts.data.splice(index, 1)
        triggerHaptic('warning')

        deleteForm.delete(route('workouts.destroy', { workout: workout.id }), {
            preserveScroll: true,
            onError: () => {
                // Rollback
                props.workouts.data.splice(index, 0, removedWorkout)
                triggerHaptic('error')
            },
        })
    }
}
const { isRefreshing, pullDistance } = usePullToRefresh()
</script>

<template>
    <Head title="Mes Séances" />

    <AuthenticatedLayout page-title="Mes Séances">
        <!-- Pull to Refresh Indicator -->
        <div
            class="pointer-events-none fixed top-0 left-0 z-50 flex w-full justify-center transition-transform duration-200 ease-out"
            :style="{ transform: `translateY(${Math.min(pullDistance, 150)}px)` }"
        >
            <div
                v-if="pullDistance > 0 || isRefreshing"
                class="mt-4 rounded-full border border-slate-200 bg-white/90 p-3 shadow-lg backdrop-blur-md dark:border-slate-700 dark:bg-slate-800/90"
            >
                <svg
                    v-if="isRefreshing"
                    class="text-electric-orange h-6 w-6 animate-spin"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                >
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path
                        class="opacity-75"
                        fill="currentColor"
                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                    ></path>
                </svg>
                <span
                    v-else
                    class="material-symbols-outlined text-electric-orange transition-transform duration-200"
                    :style="{ transform: `rotate(${pullDistance > 100 ? 180 : 0}deg)` }"
                >
                    arrow_downward
                </span>
            </div>
        </div>
        <template #header-actions>
            <GlassButton
                variant="primary"
                class="min-h-touch! flex h-11! w-11! items-center justify-center p-0!"
                :loading="form.processing"
                @click="createWorkout"
                aria-label="Nouvelle séance"
            >
                <svg
                    class="h-4 w-4"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
            </GlassButton>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-text-main text-xl font-semibold dark:text-white">Mes Séances</h2>
                <div class="flex gap-2">
                    <Link :href="route('calendar.index')">
                        <GlassButton>
                            <span class="material-symbols-outlined mr-2 text-[18px]">calendar_month</span>
                            Calendrier
                        </GlassButton>
                    </Link>
                    <Link :href="route('templates.index')">
                        <GlassButton>
                            <svg class="mr-2 h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"
                                />
                            </svg>
                            Modèles
                        </GlassButton>
                    </Link>
                    <GlassButton
                        variant="primary"
                        :loading="form.processing"
                        @click="createWorkout"
                        aria-label="Nouvelle séance"
                    >
                        <svg
                            class="mr-2 h-4 w-4"
                            xmlns="http://www.w3.org/2000/svg"
                            fill="none"
                            viewBox="0 0 24 24"
                            stroke="currentColor"
                            aria-hidden="true"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                        </svg>
                        Nouvelle Séance
                    </GlassButton>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Stats Row -->
            <div v-if="workouts.data?.length > 0" class="animate-slide-up space-y-6">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <GlassCard padding="p-4">
                        <div class="text-center">
                            <div class="text-gradient text-2xl font-bold">
                                {{ workouts.meta?.total || workouts.data?.length || 0 }}
                            </div>
                            <div class="text-text-muted mt-1 text-xs">Total séances</div>
                        </div>
                    </GlassCard>
                    <GlassCard padding="p-4">
                        <div class="text-center">
                            <div class="text-accent-success text-2xl font-bold">
                                {{ workouts.data?.reduce((acc, w) => acc + w.workout_lines.length, 0) || 0 }}
                            </div>
                            <div class="text-text-muted mt-1 text-xs">Exercices</div>
                        </div>
                    </GlassCard>
                </div>

                <!-- Charts Grid -->
                <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <!-- Frequency Chart -->
                    <GlassCard v-if="monthlyFrequency && monthlyFrequency.length > 0">
                        <div class="mb-4">
                            <h3 class="text-text-main text-lg font-bold dark:text-white">Fréquence</h3>
                            <p class="text-text-muted text-xs">Séances par mois</p>
                        </div>
                        <WorkoutsPerMonthChart :data="monthlyFrequency" />
                    </GlassCard>

                    <!-- Duration Chart -->
                    <GlassCard v-if="durationHistory && durationHistory.length > 0">
                        <div class="mb-4">
                            <h3 class="text-text-main text-lg font-bold dark:text-white">Durée</h3>
                            <p class="text-text-muted text-xs">Temps d'entraînement (min)</p>
                        </div>
                        <WorkoutDurationChart :data="durationHistory" />
                    </GlassCard>

                    <!-- Volume per Workout Chart -->
                    <GlassCard v-if="volumeHistory && volumeHistory.length > 0" class="lg:col-span-2">
                        <div class="mb-4">
                            <h3 class="text-text-main text-lg font-bold dark:text-white">Volume par Séance</h3>
                            <p class="text-text-muted text-xs">Volume total soulevé (kg)</p>
                        </div>
                        <VolumePerWorkoutChart :data="volumeHistory" />
                    </GlassCard>
                </div>
            </div>

            <!-- Available Exercises -->
            <div class="animate-slide-up" style="animation-delay: 0.1s">
                <h3 class="text-text-main mb-3 font-semibold dark:text-white">Exercices disponibles</h3>

                <!-- Loading State -->
                <div v-if="!exercises" class="flex gap-2 overflow-x-hidden pb-2">
                    <div v-for="i in 5" :key="i" class="shrink-0">
                        <GlassSkeleton width="120px" height="60px" class="rounded-xl" />
                    </div>
                </div>

                <!-- Data State -->
                <div v-else class="hide-scrollbar flex gap-2 overflow-x-auto pb-2">
                    <div
                        v-for="exercise in exercises"
                        :key="exercise.id"
                        class="shrink-0 rounded-xl border border-slate-200 bg-white/50 px-3 py-2 text-sm shadow-sm dark:border-slate-700 dark:bg-slate-800/50"
                    >
                        <div class="text-text-main font-medium dark:text-white">{{ exercise.name }}</div>
                        <div class="text-text-muted text-xs">{{ exercise.category }}</div>
                    </div>
                </div>
            </div>

            <!-- Workouts List -->
            <div class="animate-slide-up" style="animation-delay: 0.2s">
                <h3 class="text-text-main mb-3 font-semibold dark:text-white">Historique</h3>

                <!-- Skeleton Loading -->
                <div v-if="!workouts" class="space-y-3">
                    <GlassCard v-for="i in 3" :key="i" class="p-4" padding="none">
                        <div class="flex items-start justify-between">
                            <div class="flex-1 space-y-2">
                                <div class="flex items-center gap-2">
                                    <GlassSkeleton width="40%" height="1.5rem" />
                                    <GlassSkeleton width="40px" height="1.2rem" />
                                </div>
                                <GlassSkeleton width="30%" height="0.8rem" />
                                <div class="mt-2 flex gap-2">
                                    <GlassSkeleton width="60px" height="1.2rem" />
                                    <GlassSkeleton width="60px" height="1.2rem" />
                                </div>
                            </div>
                        </div>
                    </GlassCard>
                </div>

                <div v-else-if="!workouts.data || workouts.data.length === 0">
                    <GlassEmptyState
                        title="Aucune séance"
                        description="C'est le moment de commencer ton aventure ! Clique sur le bouton pour créer ta première séance."
                        icon="💪"
                        action-label="Commencer maintenant"
                        action-id="empty-state-start-workout"
                        @action="createWorkout"
                        color="orange"
                    />
                </div>

                <div v-else class="space-y-3">
                    <SwipeableRow
                        v-for="workout in workouts.data"
                        :key="workout.id"
                        class="mb-3 block"
                        :action-threshold="80"
                    >
                        <template #action-right>
                            <button
                                @click="confirmDeletion(workout)"
                                class="flex h-full w-full items-center justify-end pr-6 text-white transition-all active:scale-95"
                                style="
                                    background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);
                                    box-shadow: inset 0 2px 4px rgba(255, 255, 255, 0.2);
                                "
                            >
                                <div class="flex flex-col items-center drop-shadow-md">
                                    <span class="material-symbols-outlined text-2xl">delete</span>
                                    <span class="text-[10px] font-bold tracking-wider uppercase">Supprimer</span>
                                </div>
                            </button>
                        </template>

                        <Link :href="route('workouts.show', { workout: workout.id })" class="block">
                            <GlassCard class="hover:bg-glass-strong transition active:scale-[0.99]">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2">
                                            <h4 class="text-text-main font-semibold dark:text-white">
                                                {{ workout.name || 'Séance' }}
                                            </h4>
                                            <span class="glass-badge glass-badge-primary text-xs">
                                                {{ workout.workout_lines.length }} exo
                                            </span>
                                        </div>
                                        <div class="text-text-muted mt-1 text-sm">
                                            {{ formatDate(workout.started_at) }}
                                        </div>

                                        <!-- Exercise preview -->
                                        <div v-if="workout.workout_lines.length > 0" class="mt-3 flex flex-wrap gap-2">
                                            <span
                                                v-for="line in workout.workout_lines.slice(0, 3)"
                                                :key="line.id"
                                                class="text-text-muted rounded-lg border border-slate-200 bg-white/50 px-2 py-1 text-xs dark:border-slate-700 dark:bg-slate-800/50"
                                            >
                                                {{ line.exercise.name }}
                                                <span class="text-text-muted/50">• {{ line.sets_count }} séries</span>
                                            </span>
                                            <span
                                                v-if="workout.workout_lines.length > 3"
                                                class="text-text-muted/50 rounded-lg bg-white/50 px-2 py-1 text-xs"
                                            >
                                                +{{ workout.workout_lines.length - 3 }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <svg
                                            class="text-text-muted/30 h-5 w-5 shrink-0"
                                            xmlns="http://www.w3.org/2000/svg"
                                            fill="none"
                                            viewBox="0 0 24 24"
                                            stroke="currentColor"
                                        >
                                            <path
                                                stroke-linecap="round"
                                                stroke-linejoin="round"
                                                stroke-width="2"
                                                d="M9 5l7 7-7 7"
                                            />
                                        </svg>
                                    </div>
                                </div>
                            </GlassCard>
                        </Link>
                    </SwipeableRow>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
