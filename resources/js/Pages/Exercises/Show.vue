<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { Head, Link } from '@inertiajs/vue3'
import { computed, defineAsyncComponent } from 'vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'
import { peutEtablirUnRecord, seriesValidees, volumeDesSeriesValidees } from '@/Utils/seriesValidees'

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
 * @property {Array} progress - Data for the 1RM progression chart.
 * @property {string} progress[].date - The date of the record (e.g., '12/05').
 * @property {string} progress[].full_date - The full ISO date string.
 * @property {number} progress[].one_rep_max - The estimated 1RM value.
 *
 * @property {Array} history - List of past workout sessions for this exercise.
 * @property {number} history[].id - Unique session identifier.
 * @property {number} history[].workout_id - ID of the workout.
 * @property {string} history[].workout_name - Name of the workout.
 * @property {string} history[].formatted_date - Formatted date string (e.g., 'Lun 12 Mai').
 * @property {number|null} history[].best_1rm - The best estimated 1RM for this specific session, null when no set can set the record.
 * @property {Array} history[].sets - Every set of this session, validated or not.
 * @property {number} history[].sets[].weight - Weight lifted.
 * @property {number} history[].sets[].reps - Repetitions performed.
 * @property {number} history[].sets[].one_rep_max - Estimated 1RM for this set.
 * @property {boolean} history[].sets[].is_completed - The set was ticked as done.
 * @property {boolean} history[].sets[].is_warmup - The set is a warm-up.
 */
const props = defineProps({
    exercise: Object,
    progress: Array,
    history: Array,
})

/**
 * Les séances de la plus ancienne à la plus récente, avec les séries que
 * chaque graphique a le droit de lire.
 *
 * Les graphiques ne comptent que les séries validées (#1956) : le volume avec
 * les mêmes règles que celui de la séance, échauffements validés compris ; la
 * charge max avec celles du record. Une séance sans série qui compte n'a pas
 * de meilleure valeur : son point vaut null, que Chart.js ne trace pas, plutôt
 * qu'un zéro qui creuserait la courbe.
 */
const seances = computed(() => {
    if (!props.history || props.history.length === 0) return []
    // History is desc, so reverse for chart
    return [...props.history].reverse().map((session) => ({
        date: session.formatted_date.split('/').slice(0, 2).join('/'), // Just dd/mm
        series: seriesValidees(session.sets),
        seriesDuRecord: session.sets.filter(peutEtablirUnRecord),
        meilleur1rm: session.best_1rm ?? null,
    }))
})

const volumeData = computed(() =>
    seances.value.map((session) => ({
        date: session.date,
        volume: volumeDesSeriesValidees(session.series),
    })),
)

const maxRepsData = computed(() =>
    seances.value.map((session) => ({
        date: session.date,
        reps: session.series.length > 0 ? Math.max(...session.series.map((s) => s.reps || 0)) : null,
    })),
)

const totalRepsData = computed(() =>
    seances.value.map((session) => ({
        date: session.date,
        reps: session.series.reduce((sum, s) => sum + (parseInt(s.reps) || 0), 0),
    })),
)

const setsPerSessionData = computed(() =>
    seances.value.map((session) => ({
        date: session.date,
        sets: session.series.length,
    })),
)

const maxWeightData = computed(() =>
    seances.value.map((session) => ({
        date: session.date,
        weight:
            session.seriesDuRecord.length > 0
                ? Math.max(...session.seriesDuRecord.map((s) => parseFloat(s.weight)))
                : null,
    })),
)

const averageWeightData = computed(() =>
    seances.value.map((session) => {
        const setsWithWeight = session.series.filter((s) => parseFloat(s.weight) > 0)
        const totalWeight = setsWithWeight.reduce((sum, s) => sum + parseFloat(s.weight), 0)
        return {
            date: session.date,
            weight: setsWithWeight.length > 0 ? totalWeight / setsWithWeight.length : null,
        }
    }),
)

const estimated1rmData = computed(() =>
    seances.value.map((session) => ({
        date: session.date,
        weight: session.meilleur1rm,
    })),
)

const weightDistributionData = computed(() => {
    const allSets = seances.value.flatMap((s) => s.series)
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
    return seances.value
        .flatMap((s) => s.series)
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
                    class="text-text-muted hover:text-accent-primary-deep border-border bg-surface-card flex h-10 w-10 items-center justify-center rounded-full border shadow-sm transition-colors"
                    aria-label="Retour aux exercices"
                >
                    <GlassIcon name="arrow_back" />
                </Link>
                <div>
                    <h1 class="titre-section">
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
            <!-- Progress Chart -->
            <GlassCard class="animate-slide-up">
                <div class="mb-4">
                    <h3 class="titre-carte">Progression 1RM</h3>
                    <p class="text-text-muted text-xs font-semibold">Estimation sur 1 an</p>
                </div>
                <div v-if="progress.length > 0" class="h-64">
                    <OneRepMaxChart :data="progress" />
                </div>
                <GlassEmptyState
                    v-else
                    taille="ligne"
                    icon="show_chart"
                    title="Pas assez de données pour afficher le graphique"
                    class="h-64"
                />
            </GlassCard>

            <!-- Analytics Grid -->
            <div
                v-if="history && history.length > 0"
                class="stagger-1 animate-slide-up grid grid-cols-1 gap-6 md:grid-cols-2"
            >
                <GlassCard>
                    <div class="mb-4">
                        <h3 class="titre-carte">Volume</h3>
                        <p class="text-text-muted text-xs font-semibold">Volume total par séance (kg)</p>
                    </div>
                    <div class="h-64">
                        <VolumeTrendChart :data="volumeData" hauteur="h-full" />
                    </div>
                </GlassCard>

                <GlassCard>
                    <div class="mb-4">
                        <h3 class="titre-carte">Endurance</h3>
                        <p class="text-text-muted text-xs font-semibold">Max Reps par série</p>
                    </div>
                    <div class="h-64">
                        <MaxRepsChart :data="maxRepsData" />
                    </div>
                </GlassCard>

                <GlassCard>
                    <div class="mb-4">
                        <h3 class="titre-carte">Volume (Reps)</h3>
                        <p class="text-text-muted text-xs font-semibold">Total des répétitions par séance</p>
                    </div>
                    <div class="h-64">
                        <TotalRepsChart :data="totalRepsData" />
                    </div>
                </GlassCard>

                <GlassCard>
                    <div class="mb-4">
                        <h3 class="titre-carte">Charges</h3>
                        <p class="text-text-muted text-xs font-semibold">Distribution des poids utilisés</p>
                    </div>
                    <div class="h-64">
                        <WeightDistributionChart :data="weightDistributionData" />
                    </div>
                </GlassCard>

                <GlassCard>
                    <div class="mb-4">
                        <h3 class="titre-carte">Charge Max</h3>
                        <p class="text-text-muted text-xs font-semibold">Maximum soulevé par séance (kg)</p>
                    </div>
                    <div class="h-64">
                        <MaxWeightChart :data="maxWeightData" />
                    </div>
                </GlassCard>

                <GlassCard>
                    <div class="mb-4">
                        <h3 class="titre-carte">1RM Estimé</h3>
                        <p class="text-text-muted text-xs font-semibold">Meilleur 1RM estimé par séance</p>
                    </div>
                    <div class="h-64">
                        <Estimated1RMHistoryChart :data="estimated1rmData" />
                    </div>
                </GlassCard>

                <GlassCard>
                    <div class="mb-4">
                        <h3 class="titre-carte">Charge Moyenne</h3>
                        <p class="text-text-muted text-xs font-semibold">Poids moyen par série (kg)</p>
                    </div>
                    <div class="h-64">
                        <AverageWeightChart :data="averageWeightData" />
                    </div>
                </GlassCard>

                <GlassCard>
                    <div class="mb-4">
                        <h3 class="titre-carte">Séries</h3>
                        <p class="text-text-muted text-xs font-semibold">Nombre de séries par séance</p>
                    </div>
                    <div class="h-64">
                        <SetsPerSessionChart :data="setsPerSessionData" />
                    </div>
                </GlassCard>

                <GlassCard>
                    <div class="mb-4">
                        <h3 class="titre-carte">Poids vs Reps</h3>
                        <p class="text-text-muted text-xs font-semibold">Répartition de toutes les séries</p>
                    </div>
                    <div class="h-64">
                        <WeightRepsScatterChart :data="scatterData" />
                    </div>
                </GlassCard>

                <GlassCard>
                    <div class="mb-4">
                        <h3 class="titre-carte">Progression par Série</h3>
                        <p class="text-text-muted text-xs font-semibold">Poids des 3 premières séries dans le temps</p>
                    </div>
                    <div class="h-64">
                        <SetWeightProgressionChart :data="history" />
                    </div>
                </GlassCard>
            </div>
            <GlassCard v-else class="stagger-1 animate-slide-up">
                <GlassEmptyState
                    taille="ligne"
                    icon="bar_chart"
                    title="Pas assez de données pour afficher les statistiques"
                    class="h-64"
                />
            </GlassCard>

            <!-- Session Performance Chart -->
            <div class="stagger-3 animate-slide-up">
                <GlassCard>
                    <div class="mb-4">
                        <h3 class="titre-carte">Performance Historique</h3>
                        <p class="text-text-muted text-xs font-semibold">Volume et 1RM au fil du temps</p>
                    </div>
                    <div v-if="history.length > 0" class="h-64">
                        <SessionPerformanceChart :data="history" />
                    </div>
                    <GlassEmptyState
                        v-else
                        taille="ligne"
                        icon="bar_chart"
                        title="Pas assez de données pour afficher le graphique"
                        class="h-64"
                    />
                </GlassCard>
            </div>

            <!-- Session Volume Line Chart -->
            <div class="stagger-4 animate-slide-up">
                <GlassCard>
                    <div class="mb-4">
                        <h3 class="titre-carte">Évolution du Volume</h3>
                        <p class="text-text-muted text-xs font-semibold">Volume total par séance</p>
                    </div>
                    <div v-if="volumeData.length > 0" class="h-64">
                        <SessionVolumeLineChart :data="volumeData" />
                    </div>
                    <GlassEmptyState
                        v-else
                        taille="ligne"
                        icon="show_chart"
                        title="Pas assez de données pour afficher le graphique"
                        class="h-64"
                    />
                </GlassCard>
            </div>

            <!-- History Chart -->
            <div class="stagger-4 animate-slide-up">
                <GlassCard>
                    <div class="mb-4">
                        <h3 class="titre-carte">Historique du 1RM</h3>
                        <p class="text-text-muted text-xs font-semibold">Évolution du meilleur 1RM estimé</p>
                    </div>
                    <div v-if="history.length > 0" class="h-64">
                        <HistoryChart :data="history" />
                    </div>
                    <GlassEmptyState
                        v-else
                        taille="ligne"
                        icon="show_chart"
                        title="Pas assez de données pour afficher le graphique"
                        class="h-64"
                    />
                </GlassCard>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
