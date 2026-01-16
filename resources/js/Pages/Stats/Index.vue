<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import { Head, Link } from '@inertiajs/vue3'
import { ref, watch, computed } from 'vue'
import axios from 'axios'
import MuscleDistributionChart from '@/Components/Stats/MuscleDistributionChart.vue'
import VolumeTrendChart from '@/Components/Stats/VolumeTrendChart.vue'
import OneRepMaxChart from '@/Components/Stats/OneRepMaxChart.vue'

const props = defineProps({
    volumeTrend: Array,
    muscleDistribution: Array,
    monthlyComparison: Object,
    exercises: Array,
    latestWeight: Number,
    weightChange: Number,
    bodyFat: Number,
})

const selectedPeriod = ref('30j')
const selectedExercise = ref(null)
const exerciseProgressData = ref([])
const loadingExercise = ref(false)

const periods = [
    { value: '7j', label: '7 JOURS' },
    { value: '30j', label: '30 JOURS' },
    { value: '90j', label: '3 MOIS' },
    { value: '1a', label: '1 AN' },
]

const totalVolume = computed(() => {
    return props.volumeTrend?.reduce((acc, curr) => acc + curr.volume, 0) || 0
})

const fetchExerciseProgress = async (exerciseId) => {
    if (!exerciseId) return
    loadingExercise.value = true
    try {
        const response = await axios.get(route('stats.exercise', { exercise: exerciseId }))
        exerciseProgressData.value = response.data.progress
    } catch (error) {
        console.error('Error fetching exercise progress:', error)
    } finally {
        loadingExercise.value = false
    }
}

watch(selectedExercise, (newVal) => {
    if (newVal) {
        fetchExerciseProgress(newVal)
    }
})
</script>

<template>
    <Head title="Statistiques" />

    <AuthenticatedLayout liquid-variant="cyan-magenta">
        <div class="space-y-6">
            <!-- Header -->
            <header class="flex animate-fade-in items-start justify-between">
                <div>
                    <h1
                        class="font-display text-4xl font-black uppercase italic leading-none tracking-tighter text-text-main"
                    >
                        Mon<br />
                        <span class="text-gradient-cyan-magenta">Évolution</span>
                    </h1>
                </div>

                <!-- Period Selector -->
                <div class="flex rounded-xl border border-white bg-white/50 p-1 shadow-sm">
                    <button
                        v-for="period in periods"
                        :key="period.value"
                        @click="selectedPeriod = period.value"
                        :class="[
                            'rounded-lg px-3 py-1.5 text-[10px] font-black uppercase tracking-wider transition-all',
                            selectedPeriod === period.value
                                ? 'bg-cyan-pure text-text-main shadow-sm'
                                : 'text-text-muted hover:text-text-main',
                        ]"
                    >
                        {{ period.label }}
                    </button>
                </div>
            </header>

            <!-- Weight Evolution Card -->
            <GlassCard class="relative animate-slide-up overflow-hidden" style="animation-delay: 0.05s">
                <div class="mb-4 flex items-start justify-between">
                    <div>
                        <h3 class="mb-1 text-[10px] font-black uppercase tracking-[0.2em] text-cyan-pure">
                            Poids Corporel
                        </h3>
                        <p class="font-display text-5xl font-black tracking-tighter text-text-main">
                            {{ latestWeight || '—' }}
                            <span class="text-lg text-text-muted">kg</span>
                        </p>
                    </div>
                    <div
                        v-if="weightChange"
                        :class="[
                            'flex items-center gap-1 rounded-full px-3 py-1.5 text-xs font-bold',
                            weightChange > 0 ? 'bg-red-50 text-red-600' : 'bg-emerald-50 text-emerald-600',
                        ]"
                    >
                        <span class="material-symbols-outlined text-sm">
                            {{ weightChange > 0 ? 'trending_up' : 'trending_down' }}
                        </span>
                        {{ weightChange > 0 ? '+' : '' }}{{ weightChange }} kg
                    </div>
                </div>

                <!-- Weight Chart Placeholder -->
                <div class="relative -mx-2 h-40 w-full">
                    <svg
                        class="h-full w-full overflow-visible"
                        fill="none"
                        preserveAspectRatio="none"
                        viewBox="0 0 375 150"
                    >
                        <defs>
                            <linearGradient id="weight-gradient" x1="0" x2="0" y1="0" y2="1">
                                <stop offset="0%" stop-color="#00E5FF" stop-opacity="0.2"></stop>
                                <stop offset="100%" stop-color="#00E5FF" stop-opacity="0"></stop>
                            </linearGradient>
                        </defs>
                        <path
                            d="M0 80 Q 50 75, 100 85 T 200 70 T 300 90 T 375 60 V 150 H 0 Z"
                            fill="url(#weight-gradient)"
                        ></path>
                        <path
                            d="M0 80 Q 50 75, 100 85 T 200 70 T 300 90 T 375 60"
                            fill="none"
                            stroke="#00E5FF"
                            stroke-linecap="round"
                            stroke-width="3"
                        ></path>
                        <circle cx="375" cy="60" fill="#fff" r="6" stroke="#00E5FF" stroke-width="3"></circle>
                    </svg>
                </div>

                <Link
                    :href="route('body-measurements.index')"
                    class="mt-4 inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-cyan-pure transition-all hover:gap-3"
                >
                    Voir tout l'historique
                    <span class="material-symbols-outlined text-base">arrow_forward</span>
                </Link>
            </GlassCard>

            <!-- Body Metrics Grid -->
            <div class="grid animate-slide-up grid-cols-2 gap-4" style="animation-delay: 0.1s">
                <!-- Body Fat -->
                <GlassCard padding="p-5">
                    <div class="flex items-start justify-between">
                        <div>
                            <h4 class="mb-1 text-[10px] font-black uppercase tracking-[0.15em] text-magenta-pure">
                                Masse Grasse
                            </h4>
                            <p class="font-display text-3xl font-black text-text-main">
                                {{ bodyFat || '—' }}
                                <span class="text-sm text-text-muted">%</span>
                            </p>
                        </div>
                        <div class="flex size-12 items-center justify-center rounded-xl bg-magenta-pure/10">
                            <span class="material-symbols-outlined text-2xl text-magenta-pure">water_drop</span>
                        </div>
                    </div>
                </GlassCard>

                <!-- This Month Volume -->
                <GlassCard padding="p-5">
                    <div class="flex items-start justify-between">
                        <div>
                            <h4 class="mb-1 text-[10px] font-black uppercase tracking-[0.15em] text-vivid-violet">
                                Volume Mois
                            </h4>
                            <p class="font-display text-3xl font-black text-text-main">
                                {{ Math.round(monthlyComparison?.current_month_volume || 0).toLocaleString() }}
                                <span class="text-sm text-text-muted">kg</span>
                            </p>
                        </div>
                        <div
                            :class="[
                                'flex items-center gap-0.5 rounded-lg px-2 py-1 text-xs font-bold',
                                (monthlyComparison?.percentage || 0) >= 0
                                    ? 'bg-emerald-50 text-emerald-600'
                                    : 'bg-red-50 text-red-600',
                            ]"
                        >
                            <span class="material-symbols-outlined text-sm">
                                {{ (monthlyComparison?.percentage || 0) >= 0 ? 'trending_up' : 'trending_down' }}
                            </span>
                            {{ (monthlyComparison?.percentage || 0) >= 0 ? '+' : ''
                            }}{{ monthlyComparison?.percentage || 0 }}%
                        </div>
                    </div>
                </GlassCard>
            </div>

            <!-- Volume Trend Chart -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.15s">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h3 class="font-display text-lg font-black uppercase italic text-text-main">
                            Évolution du Volume
                        </h3>
                        <p class="text-xs font-semibold text-text-muted">
                            {{
                                selectedPeriod === '7j'
                                    ? '7'
                                    : selectedPeriod === '30j'
                                      ? '30'
                                      : selectedPeriod === '90j'
                                        ? '90'
                                        : '365'
                            }}
                            derniers jours
                        </p>
                    </div>
                    <div class="text-right">
                        <div class="text-xs font-black uppercase tracking-wider text-text-muted">Total</div>
                        <div class="font-display text-2xl font-black text-electric-orange">
                            {{ totalVolume.toLocaleString() }}
                            <span class="text-sm text-text-muted">kg</span>
                        </div>
                    </div>
                </div>
                <div v-if="volumeTrend && volumeTrend.length > 0" class="h-48">
                    <VolumeTrendChart :data="volumeTrend" />
                </div>
                <div v-else class="flex h-48 flex-col items-center justify-center text-center">
                    <span class="material-symbols-outlined mb-2 text-5xl text-text-muted/30">bar_chart</span>
                    <p class="text-sm text-text-muted">Pas encore de données de volume</p>
                </div>
            </GlassCard>

            <!-- Muscle Distribution & Exercise Progress -->
            <div class="grid animate-slide-up grid-cols-1 gap-6 lg:grid-cols-2" style="animation-delay: 0.2s">
                <!-- Muscle Distribution -->
                <GlassCard>
                    <div class="mb-4">
                        <h3 class="font-display text-lg font-black uppercase italic text-text-main">
                            Répartition Musculaire
                        </h3>
                        <p class="text-xs font-semibold text-text-muted">Volume par groupe musculaire</p>
                    </div>
                    <div v-if="muscleDistribution && muscleDistribution.length > 0" class="h-52">
                        <MuscleDistributionChart :data="muscleDistribution" />
                    </div>
                    <div v-else class="flex h-52 flex-col items-center justify-center text-center">
                        <span class="material-symbols-outlined mb-2 text-5xl text-text-muted/30">pie_chart</span>
                        <p class="text-sm text-text-muted">Données de répartition indisponibles</p>
                    </div>
                </GlassCard>

                <!-- Exercise Progress (1RM) -->
                <GlassCard>
                    <div class="mb-4">
                        <h3 class="font-display text-lg font-black uppercase italic text-text-main">Progression 1RM</h3>
                        <div class="mt-3">
                            <select v-model="selectedExercise" class="glass-input w-full">
                                <option :value="null" disabled>Sélectionner un exercice</option>
                                <option v-for="ex in exercises" :key="ex.id" :value="ex.id">
                                    {{ ex.name }}
                                </option>
                            </select>
                        </div>
                    </div>

                    <div v-if="loadingExercise" class="flex h-48 items-center justify-center">
                        <div
                            class="h-8 w-8 animate-spin rounded-full border-2 border-electric-orange border-t-transparent"
                        ></div>
                    </div>
                    <div v-else-if="selectedExercise && exerciseProgressData.length > 0" class="h-48">
                        <OneRepMaxChart :data="exerciseProgressData" />
                    </div>
                    <div
                        v-else-if="selectedExercise"
                        class="flex h-48 flex-col items-center justify-center text-center"
                    >
                        <span class="material-symbols-outlined mb-2 text-4xl text-text-muted/30">trending_up</span>
                        <p class="text-sm text-text-muted">Pas assez de données pour cet exercice</p>
                    </div>
                    <div v-else class="flex h-48 flex-col items-center justify-center text-center">
                        <span class="material-symbols-outlined mb-2 text-4xl text-text-muted/30">fitness_center</span>
                        <p class="text-sm text-text-muted">Choisis un exercice pour voir ton évolution</p>
                    </div>
                </GlassCard>
            </div>

            <!-- Summary Stats -->
            <div class="grid animate-slide-up grid-cols-4 gap-3" style="animation-delay: 0.25s">
                <GlassCard padding="p-4" class="text-center">
                    <div class="text-[10px] font-black uppercase tracking-wider text-text-muted">Séances</div>
                    <div class="mt-1 font-display text-2xl font-black text-text-main">
                        {{ volumeTrend?.length || 0 }}
                    </div>
                </GlassCard>
                <GlassCard padding="p-4" class="text-center">
                    <div class="text-[10px] font-black uppercase tracking-wider text-text-muted">Muscles</div>
                    <div class="mt-1 font-display text-2xl font-black text-text-main">
                        {{ muscleDistribution?.length || 0 }}
                    </div>
                </GlassCard>
                <GlassCard padding="p-4" class="text-center">
                    <div class="text-[10px] font-black uppercase tracking-wider text-text-muted">Exercices</div>
                    <div class="mt-1 font-display text-2xl font-black text-text-main">
                        {{ exercises?.length || 0 }}
                    </div>
                </GlassCard>
                <GlassCard padding="p-4" class="text-center">
                    <div class="text-[10px] font-black uppercase tracking-wider text-text-muted">vs Mois -1</div>
                    <div
                        :class="[
                            'mt-1 font-display text-2xl font-black',
                            (monthlyComparison?.percentage || 0) >= 0 ? 'text-emerald-500' : 'text-red-500',
                        ]"
                    >
                        {{ (monthlyComparison?.percentage || 0) >= 0 ? '+' : ''
                        }}{{ monthlyComparison?.percentage || 0 }}%
                    </div>
                </GlassCard>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
