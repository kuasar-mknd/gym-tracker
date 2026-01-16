<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import WorkoutsPerMonthChart from '@/Components/Stats/WorkoutsPerMonthChart.vue'
import { Head, useForm, Link } from '@inertiajs/vue3'

const props = defineProps({
    workouts: Object, // Paginated data: { data: [...], links: {...}, meta: {...} }
    exercises: Array,
    monthlyFrequency: Array,
})

const form = useForm({})

const createWorkout = () => {
    console.error('createWorkout called')
    form.post(route('workouts.store'))
}

const formatDate = (dateStr) => {
    return new Date(dateStr).toLocaleDateString('fr-FR', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    })
}
</script>

<template>
    <Head title="Mes Séances" />

    <AuthenticatedLayout page-title="Mes Séances">
        <template #header-actions>
            <GlassButton
                variant="primary"
                class="flex !h-11 !min-h-[44px] !w-11 items-center justify-center !p-0"
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
                <h2 class="text-xl font-semibold text-white">Mes Séances</h2>
                <div class="flex gap-2">
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
            <div v-if="workouts.length > 0" class="animate-slide-up space-y-6">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <GlassCard padding="p-4">
                        <div class="text-center">
                            <div class="text-gradient text-2xl font-bold">{{ workouts.data?.length || 0 }}</div>
                            <div class="mt-1 text-xs text-white/60">Total séances</div>
                        </div>
                    </GlassCard>
                    <GlassCard padding="p-4">
                        <div class="text-center">
                            <div class="text-2xl font-bold text-accent-success">
                                {{ workouts.data?.reduce((acc, w) => acc + w.workout_lines.length, 0) || 0 }}
                            </div>
                            <div class="mt-1 text-xs text-white/60">Exercices</div>
                        </div>
                    </GlassCard>
                </div>

                <!-- Frequency Chart -->
                <GlassCard v-if="monthlyFrequency && monthlyFrequency.length > 0">
                    <div class="mb-4">
                        <h3 class="text-lg font-bold text-white">Fréquence d'entraînement</h3>
                        <p class="text-xs text-white/50">Séances par mois (6 derniers mois)</p>
                    </div>
                    <WorkoutsPerMonthChart :data="monthlyFrequency" />
                </GlassCard>
            </div>

            <!-- Available Exercises -->
            <div class="animate-slide-up" style="animation-delay: 0.1s">
                <h3 class="mb-3 font-semibold text-white">Exercices disponibles</h3>
                <div class="hide-scrollbar flex gap-2 overflow-x-auto pb-2">
                    <div
                        v-for="exercise in exercises"
                        :key="exercise.id"
                        class="bg-glass flex-shrink-0 rounded-xl border border-white/10 px-3 py-2 text-sm shadow-sm"
                    >
                        <div class="font-medium text-white">{{ exercise.name }}</div>
                        <div class="text-xs text-white/50">{{ exercise.category }}</div>
                    </div>
                </div>
            </div>

            <!-- Workouts List -->
            <div class="animate-slide-up" style="animation-delay: 0.2s">
                <h3 class="mb-3 font-semibold text-white">Historique</h3>

                <div v-if="!workouts.data || workouts.data.length === 0">
                    <GlassCard>
                        <div class="py-12 text-center">
                            <div class="mb-3 text-5xl">💪</div>
                            <h3 class="text-lg font-semibold text-white">Aucune séance</h3>
                            <p class="mt-1 text-white/60">Clique sur le bouton + pour commencer</p>
                            <GlassButton
                                variant="primary"
                                class="mt-4"
                                :loading="form.processing"
                                @click="createWorkout"
                                data-testid="empty-state-start-workout"
                            >
                                Commencer maintenant
                            </GlassButton>
                        </div>
                    </GlassCard>
                </div>

                <div v-else class="space-y-3">
                    <Link
                        v-for="workout in workouts.data"
                        :key="workout.id"
                        :href="route('workouts.show', { workout: workout.id })"
                        class="block"
                    >
                        <GlassCard class="hover:bg-glass-strong transition active:scale-[0.99]">
                            <div class="flex items-start justify-between">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2">
                                        <h4 class="font-semibold text-white">
                                            {{ workout.name || 'Séance' }}
                                        </h4>
                                        <span class="glass-badge glass-badge-primary text-xs">
                                            {{ workout.workout_lines.length }} exo
                                        </span>
                                    </div>
                                    <div class="mt-1 text-sm text-white/50">
                                        {{ formatDate(workout.started_at) }}
                                    </div>

                                    <!-- Exercise preview -->
                                    <div v-if="workout.workout_lines.length > 0" class="mt-3 flex flex-wrap gap-2">
                                        <span
                                            v-for="line in workout.workout_lines.slice(0, 3)"
                                            :key="line.id"
                                            class="rounded-lg border border-white/10 bg-white/5 px-2 py-1 text-xs text-white/70"
                                        >
                                            {{ line.exercise.name }}
                                            <span class="text-white/40">• {{ line.sets.length }} séries</span>
                                        </span>
                                        <span
                                            v-if="workout.workout_lines.length > 3"
                                            class="rounded-lg bg-white/5 px-2 py-1 text-xs text-white/40"
                                        >
                                            +{{ workout.workout_lines.length - 3 }}
                                        </span>
                                    </div>
                                </div>
                                <svg
                                    class="h-5 w-5 flex-shrink-0 text-white/30"
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
                        </GlassCard>
                    </Link>
                </div>
            </div>
        </div>

        <!-- FAB for mobile -->
        <button
            @click="createWorkout"
            class="glass-fab sm:hidden"
            :disabled="form.processing"
            aria-label="Nouvelle séance"
        >
            <svg
                class="h-6 w-6 text-white"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
                aria-hidden="true"
            >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
        </button>
    </AuthenticatedLayout>
</template>
