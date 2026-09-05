<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { Head, useForm, Link, Deferred, router } from '@inertiajs/vue3'
import { defineAsyncComponent, ref, watch } from 'vue'
import IndicateurDeRafraichissement from '@/Components/UI/IndicateurDeRafraichissement.vue'
import CarteDeSeance from '@/Components/Workout/CarteDeSeance.vue'
import GraphiquesDesSeances from '@/Components/Workout/GraphiquesDesSeances.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'
import { triggerHaptic } from '@/composables/useHaptics'
import { usePullToRefresh } from '@/composables/usePullToRefresh'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { useConfirmation } from '@/composables/useConfirmation'

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
const {
    cible: seanceASupprimer,
    ouvert: suppressionDemandee,
    demander: confirmDeletion,
    annuler: annulerSuppression,
    confirmer: confirmerSuppression,
} = useConfirmation((workout, termine) => {
    /*
     * La ligne part de l'écran avant la réponse du serveur, et revient à sa
     * place exacte si elle échoue. L'ordre compte : on referme le dialogue
     * AVANT de retirer la ligne, sinon la modale reste ouverte sur une liste
     * qui a déjà bougé sous elle.
     */
    termine()

    const index = workoutList.value.findIndex((w) => w.id === workout.id)

    if (index === -1) {
        return
    }

    const removedWorkout = workoutList.value[index]
    workoutList.value.splice(index, 1)
    triggerHaptic('warning')

    deleteForm.delete(route('workouts.destroy', { workout: workout.id }), {
        preserveScroll: true,
        onError: () => {
            workoutList.value.splice(index, 0, removedWorkout)
            triggerHaptic('error')
        },
    })
})
const { isRefreshing, pullDistance } = usePullToRefresh()
</script>

<template>
    <Head title="Mes Séances" />

    <AuthenticatedLayout page-title="Mes Séances">
        <IndicateurDeRafraichissement :distance="pullDistance" :en-cours="isRefreshing" />
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
                                <GlassSkeleton height="200px" width="100%" class="rounded-xl" />
                            </GlassCard>
                        </div>
                    </template>

                    <GraphiquesDesSeances :charts="deferredData?.charts" />
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
                    <GlassCard v-for="i in 3" :key="i" padding="p-4">
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
                    <CarteDeSeance
                        v-for="workout in workoutList"
                        :key="workout.id"
                        :seance="workout"
                        @supprimer="confirmDeletion"
                    />
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
        <ConfirmDialog
            :ouvert="suppressionDemandee"
            titre="Supprimer cette séance ?"
            :description="
                seanceASupprimer ? `« ${seanceASupprimer.name || 'Séance'} » sera effacée, avec toutes ses séries.` : ''
            "
            @confirmer="confirmerSuppression"
            @annuler="annulerSuppression"
        />
    </AuthenticatedLayout>
</template>
