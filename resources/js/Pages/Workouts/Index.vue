<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { Head, useForm, Link, Deferred, router } from '@inertiajs/vue3'
import { defineAsyncComponent, ref, watch } from 'vue'
import SwipeableRow from '@/Components/UI/SwipeableRow.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'
import { triggerHaptic } from '@/composables/useHaptics'
import { usePullToRefresh } from '@/composables/usePullToRefresh'

const WorkoutFrequencyChart = defineAsyncComponent(() => import('@/Components/Stats/WorkoutFrequencyChart.vue'))
const WorkoutsPerMonthChart = defineAsyncComponent(() => import('@/Components/Stats/WorkoutsPerMonthChart.vue'))
const MonthlyVolumeChart = defineAsyncComponent(() => import('@/Components/Stats/MonthlyVolumeChart.vue'))
const WorkoutDurationChart = defineAsyncComponent(() => import('@/Components/Stats/WorkoutDurationChart.vue'))
const VolumePerWorkoutChart = defineAsyncComponent(() => import('@/Components/Stats/VolumePerWorkoutChart.vue'))
const WorkoutHistoryTimelineChart = defineAsyncComponent(
    () => import('@/Components/Stats/WorkoutHistoryTimelineChart.vue'),
)

const props = defineProps({
    workouts: Object, // Laravel paginator: { data: [...], total, current_page, last_page, prev_page_url, next_page_url }
    totalExercises: Number,
    // ⚡ Bolt: PERFORMANCE OPTIMIZATION
    // Consolidated deferred data (charts + exercises) to reduce XHR requests.
    deferredData: {
        type: Object,
        default: () => ({
            charts: {
                monthly_frequency: [],
                monthly_volume: [],
                duration_history: [],
                volume_history: [],
            },
            exercises: [],
        }),
    },
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

/**
 * The optimistic delete used to splice `props.workouts.data` in place. It works,
 * but a page owning a copy of its server data is the convention here — see
 * Workouts/Show, which clones props.workout for exactly this reason — and
 * writing through a prop hides the mutation from Vue's ownership model, so
 * Inertia replacing the prop on the next visit silently discards it.
 */
const workoutList = ref([...(props.workouts?.data ?? [])])

/**
 * The history is paginated 20 to a page server-side and the page offered no way
 * to reach page 2, so everything older than the twentieth session existed and
 * could not be opened. Same two-target layout as the notifications list.
 */
const goToPage = (url) => {
    if (url) {
        router.visit(url)
    }
}

watch(
    () => props.workouts?.data,
    (rows) => {
        workoutList.value = [...(rows ?? [])]
    },
)

const deleteForm = useForm({})
const confirmDeletion = (workout) => {
    if (confirm(`Êtes-vous sûr de vouloir supprimer la séance "${workout.name || 'Séance'}" ?`)) {
        // Optimistic UI
        const index = workoutList.value.findIndex((w) => w.id === workout.id)
        if (index === -1) return

        const removedWorkout = workoutList.value[index]
        workoutList.value.splice(index, 1)
        triggerHaptic('warning')

        deleteForm.delete(route('workouts.destroy', { workout: workout.id }), {
            preserveScroll: true,
            onError: () => {
                // Rollback
                workoutList.value.splice(index, 0, removedWorkout)
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
                class="border-border bg-surface-card/90 mt-4 rounded-full border p-3 shadow-lg backdrop-blur-md"
            >
                <svg
                    v-if="isRefreshing"
                    class="text-accent-primary-deep h-6 w-6 animate-spin"
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
                    class="material-symbols-outlined text-accent-primary-deep transition-transform duration-200"
                    :style="{ transform: `rotate(${pullDistance > 100 ? 180 : 0}deg)` }"
                    aria-hidden="true"
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
                <span class="material-symbols-outlined text-xl leading-none" aria-hidden="true">add</span>
            </GlassButton>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-text-main text-xl font-semibold">Mes Séances</h2>
                <div class="flex gap-2">
                    <Link :href="route('calendar.index')">
                        <GlassButton>
                            <span class="material-symbols-outlined mr-2 text-[18px]" aria-hidden="true"
                                >calendar_month</span
                            >
                            Calendrier
                        </GlassButton>
                    </Link>
                    <Link :href="route('templates.index')">
                        <GlassButton>
                            <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">inventory_2</span>
                            Modèles
                        </GlassButton>
                    </Link>
                    <GlassButton
                        variant="primary"
                        :loading="form.processing"
                        @click="createWorkout"
                        aria-label="Nouvelle séance"
                    >
                        <span class="material-symbols-outlined mr-2 text-lg" aria-hidden="true">add</span>
                        Nouvelle Séance
                    </GlassButton>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Stats Row -->
            <div v-if="workoutList.length > 0" class="animate-slide-up space-y-6">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <GlassCard padding="p-4">
                        <div class="text-center">
                            <div class="text-gradient text-2xl font-bold">
                                {{ workouts.total ?? workoutList.length }}
                            </div>
                            <div class="text-text-muted mt-1 text-xs">Total séances</div>
                        </div>
                    </GlassCard>
                    <GlassCard padding="p-4">
                        <div class="text-center">
                            <div class="text-accent-state-deep text-2xl font-bold">
                                {{ totalExercises || 0 }}
                            </div>
                            <div class="text-text-muted mt-1 text-xs">Exercices</div>
                        </div>
                    </GlassCard>
                </div>

                <!-- ⚡ Bolt: Consolidated Deferred Loading -->
                <Deferred data="deferredData">
                    <template #fallback>
                        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                            <GlassCard v-for="i in 4" :key="i">
                                <div class="mb-4">
                                    <GlassSkeleton width="120px" height="1.5rem" />
                                    <GlassSkeleton width="180px" height="0.8rem" class="mt-2" />
                                </div>
                                <GlassSkeleton height="200px" width="100%" variant="card" />
                            </GlassCard>
                        </div>
                    </template>

                    <!-- Charts Grid -->
                    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                        <!-- Workout Frequency (Day of Week) Chart -->
                        <GlassCard v-if="deferredData?.charts?.day_of_week_frequency?.length > 0">
                            <div class="mb-4">
                                <h3 class="text-text-main text-lg font-bold">Fréquence par Jour</h3>
                                <p class="text-text-muted text-xs">Séances selon le jour de la semaine</p>
                            </div>
                            <div class="h-48 w-full">
                                <WorkoutFrequencyChart :data="deferredData.charts.day_of_week_frequency" />
                            </div>
                        </GlassCard>

                        <!-- Frequency Chart -->
                        <GlassCard v-if="deferredData?.charts?.monthly_frequency?.length > 0">
                            <div class="mb-4">
                                <h3 class="text-text-main text-lg font-bold">Fréquence Mensuelle</h3>
                                <p class="text-text-muted text-xs">Séances par mois</p>
                            </div>
                            <WorkoutsPerMonthChart :data="deferredData.charts.monthly_frequency" />
                        </GlassCard>

                        <!-- Monthly Volume Chart -->
                        <GlassCard v-if="deferredData?.charts?.monthly_volume?.length > 0">
                            <div class="mb-4">
                                <h3 class="text-text-main text-lg font-bold">Volume Mensuel</h3>
                                <p class="text-text-muted text-xs">Total soulevé par mois (kg)</p>
                            </div>
                            <MonthlyVolumeChart :data="deferredData.charts.monthly_volume" />
                        </GlassCard>

                        <!-- Duration Chart -->
                        <GlassCard v-if="deferredData?.charts?.duration_history?.length > 0">
                            <div class="mb-4">
                                <h3 class="text-text-main text-lg font-bold">Durée</h3>
                                <p class="text-text-muted text-xs">Temps d'entraînement (min)</p>
                            </div>
                            <WorkoutDurationChart :data="deferredData.charts.duration_history" />
                        </GlassCard>

                        <!-- Volume per Workout Chart -->
                        <GlassCard v-if="deferredData?.charts?.volume_history?.length > 0">
                            <div class="mb-4">
                                <h3 class="text-text-main text-lg font-bold">Volume par Séance</h3>
                                <p class="text-text-muted text-xs">Volume total soulevé (kg)</p>
                            </div>
                            <VolumePerWorkoutChart :data="deferredData.charts.volume_history" />
                        </GlassCard>
                    </div>
                </Deferred>
            </div>

            <!-- Timeline Chart -->
            <div class="animate-slide-up" style="animation-delay: 0.08s">
                <GlassCard v-if="workoutList.length > 0">
                    <div class="mb-4">
                        <h3 class="font-display text-text-main text-lg font-black uppercase italic">
                            Aperçu Historique
                        </h3>
                        <p class="text-text-muted text-xs font-semibold">Volume et Durée des dernières séances</p>
                    </div>
                    <WorkoutHistoryTimelineChart :data="workoutList" />
                </GlassCard>
            </div>

            <!-- Available Exercises -->
            <div class="animate-slide-up" style="animation-delay: 0.1s">
                <h3 class="text-text-main mb-3 font-semibold">Exercices disponibles</h3>

                <Deferred data="deferredData">
                    <template #fallback>
                        <!-- Loading State -->
                        <div class="flex gap-2 overflow-x-hidden pb-2">
                            <div v-for="i in 5" :key="i" class="shrink-0">
                                <GlassSkeleton width="120px" height="60px" class="rounded-xl" />
                            </div>
                        </div>
                    </template>

                    <!-- Data State -->
                    <div v-if="deferredData?.exercises" class="hide-scrollbar flex gap-2 overflow-x-auto pb-2">
                        <div
                            v-for="exercise in deferredData.exercises"
                            :key="exercise.id"
                            class="border-border bg-surface-card/50 shrink-0 rounded-xl border px-3 py-2 text-sm shadow-sm"
                        >
                            <div class="text-text-main font-medium">{{ exercise.name }}</div>
                            <div class="text-text-muted text-xs">{{ exercise.category }}</div>
                        </div>
                    </div>
                </Deferred>
            </div>

            <!-- Workouts List -->
            <div class="animate-slide-up" style="animation-delay: 0.2s">
                <h3 class="text-text-main mb-3 font-semibold">Historique</h3>

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

                <div v-else-if="workoutList.length === 0">
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
                        v-for="workout in workoutList"
                        :key="workout.id"
                        class="mb-3 block"
                        :action-threshold="80"
                    >
                        <template #action-right>
                            <button
                                type="button"
                                @click="confirmDeletion(workout)"
                                :dusk="`delete-workout-${workout.id}`"
                                :aria-label="`Supprimer la séance ${workout.name || 'sans nom'}`"
                                class="text-text-on-dark-accent flex h-full w-full items-center justify-center transition-all active:scale-95"
                                style="
                                    background: linear-gradient(
                                        135deg,
                                        var(--color-accent-danger) 0%,
                                        var(--color-accent-danger-deep) 100%
                                    );
                                    box-shadow: inset 0 2px 4px rgb(from var(--color-surface-card) r g b / 0.2);
                                "
                            >
                                <div class="flex flex-col items-center drop-shadow-md" aria-hidden="true">
                                    <span class="material-symbols-outlined text-2xl" aria-hidden="true">delete</span>
                                    <span class="text-[10px] font-bold tracking-wider uppercase">Supprimer</span>
                                </div>
                            </button>
                        </template>

                        <Link :href="route('workouts.show', { workout: workout.id })" class="block">
                            <GlassCard class="hover:bg-surface-glass-strong transition active:scale-[0.99]">
                                <div class="flex items-start justify-between">
                                    <div class="flex-1">
                                        <div class="flex items-center gap-2">
                                            <h4 class="text-text-main font-semibold">
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
                                                class="text-text-muted border-border bg-surface-card/50 rounded-lg border px-2 py-1 text-xs"
                                            >
                                                {{ line.exercise.name }}
                                                <span class="text-text-muted/50">• {{ line.sets_count }} séries</span>
                                            </span>
                                            <span
                                                v-if="workout.workout_lines.length > 3"
                                                class="text-text-muted/50 border-border bg-surface-card/50 rounded-lg border px-2 py-1 text-xs"
                                            >
                                                +{{ workout.workout_lines.length - 3 }}
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3">
                                        <span
                                            class="material-symbols-outlined text-text-muted/30 shrink-0 text-xl"
                                            aria-hidden="true"
                                            >chevron_right</span
                                        >
                                    </div>
                                </div>
                            </GlassCard>
                        </Link>
                    </SwipeableRow>
                </div>

                <nav
                    v-if="workouts?.last_page > 1"
                    class="mt-6 flex items-center justify-center gap-4"
                    aria-label="Pagination de l'historique"
                >
                    <GlassButton
                        :disabled="!workouts.prev_page_url"
                        @click="goToPage(workouts.prev_page_url)"
                        aria-label="Page précédente"
                        dusk="workouts-prev"
                    >
                        <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
                    </GlassButton>

                    <span class="text-text-muted text-sm font-bold" aria-live="polite">
                        Page {{ workouts.current_page }} sur {{ workouts.last_page }}
                    </span>

                    <GlassButton
                        :disabled="!workouts.next_page_url"
                        @click="goToPage(workouts.next_page_url)"
                        aria-label="Page suivante"
                        dusk="workouts-next"
                    >
                        <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
                    </GlassButton>
                </nav>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
