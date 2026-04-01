<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'
import { Head, Link, Deferred } from '@inertiajs/vue3'
import { computed, defineAsyncComponent } from 'vue'

const OneRepMaxChart = defineAsyncComponent(() => import('@/Components/Stats/OneRepMaxChart.vue'))
const VolumeTrendChart = defineAsyncComponent(() => import('@/Components/Stats/VolumeTrendChart.vue'))
const WeightDistributionChart = defineAsyncComponent(() => import('@/Components/Stats/WeightDistributionChart.vue'))
const MaxRepsChart = defineAsyncComponent(() => import('@/Components/Stats/MaxRepsChart.vue'))
const MaxWeightChart = defineAsyncComponent(() => import('@/Components/Stats/MaxWeightChart.vue'))
const AverageWeightChart = defineAsyncComponent(() => import('@/Components/Stats/AverageWeightChart.vue'))
const TotalRepsChart = defineAsyncComponent(() => import('@/Components/Stats/TotalRepsChart.vue'))
const SetsPerSessionChart = defineAsyncComponent(() => import('@/Components/Stats/SetsPerSessionChart.vue'))
const WeightRepsScatterChart = defineAsyncComponent(() => import('@/Components/Stats/WeightRepsScatterChart.vue'))
const SetWeightProgressionChart = defineAsyncComponent(() => import('@/Components/Stats/SetWeightProgressionChart.vue'))
const Estimated1RMHistoryChart = defineAsyncComponent(() => import('@/Components/Stats/Estimated1RMHistoryChart.vue'))
const SessionPerformanceChart = defineAsyncComponent(() => import('@/Components/Stats/SessionPerformanceChart.vue'))
const SessionVolumeLineChart = defineAsyncComponent(() => import('@/Components/Stats/SessionVolumeLineChart.vue'))
const HistoryChart = defineAsyncComponent(() => import('@/Components/Stats/HistoryChart.vue'))

/**
 * Component Props
 *
 * @property {Object} exercise - The exercise details.
 * @property {number} exercise.id - The unique identifier of the exercise.
 * @property {string} exercise.name - The name of the exercise.
 * @property {string} exercise.category - The category (e.g., 'Pectoraux').
 * @property {string} exercise.type - The type ('strength', 'cardio', 'timed').
 *
 * @property {Object} stats - Consolidated stats (Deferred loading).
 * @property {Array} stats.progress - Data for the 1RM progression chart.
 * @property {string} stats.progress[].date - The date of the record (e.g., '12/05').
 * @property {string} stats.progress[].full_date - The full ISO date string.
 * @property {number} stats.progress[].one_rep_max - The estimated 1RM value.
 *
 * @property {Array} stats.history - List of past workout sessions for this exercise.
 * @property {number} stats.history[].id - Unique session identifier.
 * @property {number} stats.history[].workout_id - ID of the workout.
 * @property {string} stats.history[].workout_name - Name of the workout.
 * @property {string} stats.history[].formatted_date - Formatted date string (e.g., 'Lun 12 Mai').
 * @property {number} stats.history[].best_1rm - The best estimated 1RM for this specific session.
 * @property {Array} stats.history[].sets - List of sets performed in this session.
 * @property {number} stats.history[].sets[].weight - Weight lifted.
 * @property {number} stats.history[].sets[].reps - Repetitions performed.
 * @property {number} stats.history[].sets[].1rm - Estimated 1RM for this set.
 */
const props = defineProps({
    exercise: Object,
    // ⚡ Bolt: Consolidated stats prop
    stats: {
        type: Object,
        default: () => ({
            progress: [],
            history: [],
        }),
    },
})

const volumeData = computed(() => {
    if (!props.stats.history || props.stats.history.length === 0) return []
    // History is desc, so reverse for chart
    return [...props.stats.history].reverse().map((session) => ({
        date: session.formatted_date.split('/').slice(0, 2).join('/'), // Just dd/mm
        volume: session.sets.reduce((sum, set) => sum + (set.weight || 0) * (set.reps || 0), 0),
    }))
})

const maxRepsData = computed(() => {
    if (!props.stats.history || props.stats.history.length === 0) return []
    return [...props.stats.history].reverse().map((session) => ({
        date: session.formatted_date.split('/').slice(0, 2).join('/'),
        reps: session.sets.length > 0 ? Math.max(...session.sets.map((s) => s.reps || 0)) : 0,
    }))
})

const totalRepsData = computed(() => {
    if (!props.stats.history || props.stats.history.length === 0) return []
    return [...props.stats.history].reverse().map((session) => ({
        date: session.formatted_date.split('/').slice(0, 2).join('/'),
        reps: session.sets.reduce((sum, s) => sum + (parseInt(s.reps) || 0), 0),
    }))
})

const setsPerSessionData = computed(() => {
    if (!props.stats.history || props.stats.history.length === 0) return []
    return [...props.stats.history].reverse().map((session) => ({
        date: session.formatted_date.split('/').slice(0, 2).join('/'),
        sets: session.sets.length,
    }))
})

const maxWeightData = computed(() => {
    if (!props.stats.history || props.stats.history.length === 0) return []
    return [...props.stats.history].reverse().map((session) => ({
        date: session.formatted_date.split('/').slice(0, 2).join('/'),
        weight: session.sets.length > 0 ? Math.max(...session.sets.map((s) => parseFloat(s.weight) || 0)) : 0,
    }))
})

const averageWeightData = computed(() => {
    if (!props.stats.history || props.stats.history.length === 0) return []
    return [...props.stats.history].reverse().map((session) => {
        const setsWithWeight = session.sets.filter((s) => parseFloat(s.weight) > 0)
        const totalWeight = setsWithWeight.reduce((sum, s) => sum + parseFloat(s.weight), 0)
        const average = setsWithWeight.length > 0 ? (totalWeight / setsWithWeight.length).toFixed(1) : 0
        return {
            date: session.formatted_date.split('/').slice(0, 2).join('/'),
            weight: average,
        }
    })
})

const estimated1rmData = computed(() => {
    if (!props.stats.history || props.stats.history.length === 0) return []
    return [...props.stats.history].reverse().map((session) => ({
        date: session.formatted_date.split('/').slice(0, 2).join('/'),
        weight: session.best_1rm || 0,
    }))
})

const weightDistributionData = computed(() => {
    if (!props.stats.history || props.stats.history.length === 0) return []
    const allSets = props.stats.history.flatMap((s) => s.sets)
    if (allSets.length === 0) return []

    const weights = allSets.map((s) => parseFloat(s.weight))
    const min = Math.floor(Math.min(...weights) / 5) * 5
    const max = Math.ceil(Math.max(...weights) / 5) * 5

    const distribution = {}
    // Initialize bins
    for (let i = min; i <= max; i += 5) {
        distribution[i] = 0
    }

    weights.forEach((w) => {
        const bin = Math.floor(w / 5) * 5
        if (distribution[bin] !== undefined) {
            distribution[bin]++
        } else {
            distribution[bin] = 1
        }
    })

    return Object.entries(distribution)
        .map(([label, count]) => ({ label, count }))
        .sort((a, b) => parseFloat(a.label) - parseFloat(b.label))
})

const scatterData = computed(() => {
    if (!props.stats.history || props.stats.history.length === 0) return []
    const allSets = props.stats.history.flatMap((s) => s.sets)
    return allSets
        .filter((s) => parseFloat(s.weight) > 0 && parseInt(s.reps) > 0)
        .map((s) => ({
            x: parseFloat(s.weight),
            y: parseInt(s.reps),
        }))
})
</script>

<template>
    <Head :title="exercise.name" />

    <AuthenticatedLayout :page-title="exercise.name" show-back back-route="exercises.index">
        <template #header>
            <div class="flex items-center gap-4">
                <Link
                    :href="route('exercises.index')"
                    class="text-text-muted hover:text-electric-orange flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white shadow-sm transition-colors"
                    aria-label="Retour aux exercices"
                >
                    <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
                </Link>
                <div>
                    <h1 class="font-display text-text-main text-2xl font-black tracking-tight uppercase italic">
                        {{ exercise.name }}
                    </h1>
                    <p class="text-text-muted text-xs font-bold tracking-wider uppercase">
                        {{ exercise.category }} •
                        {{ exercise.type === 'strength' ? 'Force' : exercise.type === 'cardio' ? 'Cardio' : 'Temps' }}
                    </p>
                </div>
            </div>
        </template>

        <div class="space-y-6">
            <!-- ⚡ Bolt: Wrapped heavy statistics in Inertia 2.0 <Deferred> for better perceived performance -->
            <Deferred data="stats">
                <template #fallback>
                    <GlassCard class="animate-slide-up">
                        <div class="mb-4">
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">
                                Progression 1RM
                            </h3>
                            <p class="text-text-muted text-xs font-semibold">Estimation sur 1 an</p>
                        </div>
                        <GlassSkeleton class="h-64 w-full" />
                    </GlassCard>

                    <div class="animate-slide-up mt-6 grid grid-cols-1 gap-6 md:grid-cols-2">
                        <GlassSkeleton class="h-64 w-full" />
                        <GlassSkeleton class="h-64 w-full" />
                        <GlassSkeleton class="h-64 w-full" />
                        <GlassSkeleton class="h-64 w-full" />
                    </div>
                </template>

                <!-- Progress Chart -->
                <GlassCard class="animate-slide-up">
                    <div class="mb-4">
                        <h3 class="font-display text-text-main text-lg font-black uppercase italic">Progression 1RM</h3>
                        <p class="text-text-muted text-xs font-semibold">Estimation sur 1 an</p>
                    </div>
                    <div v-if="stats.progress.length > 0" class="h-64">
                        <OneRepMaxChart :data="stats.progress" />
                    </div>
                    <div v-else class="flex h-64 flex-col items-center justify-center text-center">
                        <span class="material-symbols-outlined text-text-muted/30 mb-2 text-5xl">show_chart</span>
                        <p class="text-text-muted text-sm">Pas assez de données pour afficher le graphique</p>
                    </div>
                </GlassCard>

                <!-- Analytics Grid -->
                <div
                    v-if="stats.history && stats.history.length > 0"
                    class="animate-slide-up grid grid-cols-1 gap-6 md:grid-cols-2"
                    style="animation-delay: 0.05s"
                >
                    <GlassCard>
                        <div class="mb-4">
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">Volume</h3>
                            <p class="text-text-muted text-xs font-semibold">Volume total par séance (kg)</p>
                        </div>
                        <div class="h-64">
                            <VolumeTrendChart :data="volumeData" />
                        </div>
                    </GlassCard>

                    <GlassCard>
                        <div class="mb-4">
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">Endurance</h3>
                            <p class="text-text-muted text-xs font-semibold">Max Reps par série</p>
                        </div>
                        <div class="h-64">
                            <MaxRepsChart :data="maxRepsData" />
                        </div>
                    </GlassCard>

                    <GlassCard class="rounded-3xl border border-white/20 bg-white/10 backdrop-blur-md">
                        <div class="mb-4">
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">
                                Volume (Reps)
                            </h3>
                            <p class="text-text-muted text-xs font-semibold">Total des répétitions par séance</p>
                        </div>
                        <div class="h-64">
                            <TotalRepsChart :data="totalRepsData" />
                        </div>
                    </GlassCard>

                    <GlassCard>
                        <div class="mb-4">
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">Charges</h3>
                            <p class="text-text-muted text-xs font-semibold">Distribution des poids utilisés</p>
                        </div>
                        <div class="h-64">
                            <WeightDistributionChart :data="weightDistributionData" />
                        </div>
                    </GlassCard>

                    <GlassCard>
                        <div class="mb-4">
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">Charge Max</h3>
                            <p class="text-text-muted text-xs font-semibold">Maximum soulevé par séance (kg)</p>
                        </div>
                        <div class="h-64">
                            <MaxWeightChart :data="maxWeightData" />
                        </div>
                    </GlassCard>

                    <GlassCard class="rounded-3xl border border-white/20 bg-white/10 backdrop-blur-md">
                        <div class="mb-4">
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">1RM Estimé</h3>
                            <p class="text-text-muted text-xs font-semibold">Meilleur 1RM estimé par séance</p>
                        </div>
                        <div class="h-64">
                            <Estimated1RMHistoryChart :data="estimated1rmData" />
                        </div>
                    </GlassCard>

                    <GlassCard>
                        <div class="mb-4">
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">
                                Charge Moyenne
                            </h3>
                            <p class="text-text-muted text-xs font-semibold">Poids moyen par série (kg)</p>
                        </div>
                        <div class="h-64">
                            <AverageWeightChart :data="averageWeightData" />
                        </div>
                    </GlassCard>

                    <GlassCard class="rounded-3xl border border-white/20 bg-white/10 backdrop-blur-md">
                        <div class="mb-4">
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">Séries</h3>
                            <p class="text-text-muted text-xs font-semibold">Nombre de séries par séance</p>
                        </div>
                        <div class="h-64">
                            <SetsPerSessionChart :data="setsPerSessionData" />
                        </div>
                    </GlassCard>

                    <GlassCard class="rounded-3xl border border-white/20 bg-white/10 backdrop-blur-md">
                        <div class="mb-4">
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">Poids vs Reps</h3>
                            <p class="text-text-muted text-xs font-semibold">Répartition de toutes les séries</p>
                        </div>
                        <div class="h-64">
                            <WeightRepsScatterChart :data="scatterData" />
                        </div>
                    </GlassCard>

                    <GlassCard class="rounded-3xl border border-white/20 bg-white/10 backdrop-blur-md">
                        <div class="mb-4">
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">
                                Progression par Série
                            </h3>
                            <p class="text-text-muted text-xs font-semibold">
                                Poids des 3 premières séries dans le temps
                            </p>
                        </div>
                        <div class="h-64">
                            <SetWeightProgressionChart :data="stats.history" />
                        </div>
                    </GlassCard>
                </div>
                <GlassCard v-else class="animate-slide-up" style="animation-delay: 0.05s">
                    <div class="flex h-64 flex-col items-center justify-center text-center">
                        <span class="material-symbols-outlined text-text-muted/30 mb-2 text-5xl">bar_chart</span>
                        <p class="text-text-muted text-sm">Pas assez de données pour afficher les statistiques</p>
                    </div>
                </GlassCard>

                <!-- Session Performance Chart -->
                <div class="animate-slide-up" style="animation-delay: 0.15s">
                    <GlassCard class="rounded-3xl border border-white/20 bg-white/10 backdrop-blur-md">
                        <div class="mb-4">
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">
                                Performance Historique
                            </h3>
                            <p class="text-text-muted text-xs font-semibold">Volume et 1RM au fil du temps</p>
                        </div>
                        <div v-if="stats.history.length > 0" class="h-64">
                            <SessionPerformanceChart :data="stats.history" />
                        </div>
                        <div v-else class="flex h-64 flex-col items-center justify-center text-center">
                            <span class="material-symbols-outlined text-text-muted/30 mb-2 text-5xl">bar_chart</span>
                            <p class="text-text-muted text-sm">Pas assez de données pour afficher le graphique</p>
                        </div>
                    </GlassCard>
                </div>

                <!-- Session Volume Line Chart -->
                <div class="animate-slide-up" style="animation-delay: 0.18s">
                    <GlassCard class="rounded-3xl border border-white/20 bg-white/10 backdrop-blur-md">
                        <div class="mb-4">
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">
                                Évolution du Volume
                            </h3>
                            <p class="text-text-muted text-xs font-semibold">Volume total par séance</p>
                        </div>
                        <div v-if="volumeData.length > 0" class="h-64">
                            <SessionVolumeLineChart :data="volumeData" />
                        </div>
                        <div v-else class="flex h-64 flex-col items-center justify-center text-center">
                            <span class="material-symbols-outlined text-text-muted/30 mb-2 text-5xl">show_chart</span>
                            <p class="text-text-muted text-sm">Pas assez de données pour afficher le graphique</p>
                        </div>
                    </GlassCard>
                </div>

                <!-- History Chart -->
                <div class="animate-slide-up" style="animation-delay: 0.2s">
                    <GlassCard class="rounded-3xl border border-white/20 bg-white/10 backdrop-blur-md">
                        <div class="mb-4">
                            <h3 class="font-display text-text-main text-lg font-black uppercase italic">
                                Historique du 1RM
                            </h3>
                            <p class="text-text-muted text-xs font-semibold">Évolution du meilleur 1RM estimé</p>
                        </div>
                        <div v-if="stats.history.length > 0" class="h-64">
                            <HistoryChart :data="stats.history" />
                        </div>
                        <div v-else class="flex h-64 flex-col items-center justify-center text-center">
                            <span class="material-symbols-outlined text-text-muted/30 mb-2 text-5xl">show_chart</span>
                            <p class="text-text-muted text-sm">Pas assez de données pour afficher le graphique</p>
                        </div>
                    </GlassCard>
                </div>
            </Deferred>
        </div>
    </AuthenticatedLayout>
</template>
