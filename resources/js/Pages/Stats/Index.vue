<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'
import { Head, Link, router, Deferred } from '@inertiajs/vue3'
import { ref, watch, computed, defineAsyncComponent } from 'vue'
import axios from 'axios'

const MuscleDistributionChart = defineAsyncComponent(() => import('@/Components/Stats/MuscleDistributionChart.vue'))
const VolumeTrendChart = defineAsyncComponent(() => import('@/Components/Stats/VolumeTrendChart.vue'))
const DurationHistoryChart = defineAsyncComponent(() => import('@/Components/Stats/DurationHistoryChart.vue'))
const OneRepMaxChart = defineAsyncComponent(() => import('@/Components/Stats/OneRepMaxChart.vue'))
const WeightHistoryChart = defineAsyncComponent(() => import('@/Components/Stats/WeightHistoryChart.vue'))
const BodyFatChart = defineAsyncComponent(() => import('@/Components/Stats/BodyFatChart.vue'))

const props = defineProps({
    volumeTrend: Array,
    muscleDistribution: Array,
    monthlyComparison: Object,
    weightHistory: Array,
    bodyFatHistory: Array,
    durationHistory: Array,
    exercises: Array,
    latestWeight: [Number, String],
    weightChange: Number,
    bodyFat: Number,
    selectedPeriod: String,
})

const currentPeriod = ref(props.selectedPeriod || '30j')
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

const handlePeriodChange = (period) => {
    currentPeriod.value = period
    router.visit(route('stats.index'), {
        data: { period },
        preserveScroll: true,
        preserveState: true,
    })
}

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
            <header class="animate-fade-in flex items-start justify-between">
                <div>
                    <h1
                        class="font-display text-text-main text-4xl leading-none font-black tracking-tighter uppercase italic"
                    >
                        Mon<br />
                        <span class="text-gradient-cyan-magenta">Évolution</span>
                    </h1>
                </div>

                <!-- Period Selector -->
                <div class="border-glass-border flex rounded-xl border bg-white/50 p-1 shadow-sm backdrop-blur-sm">
                    <button
                        v-for="period in periods"
                        :key="period.value"
                        @click="handlePeriodChange(period.value)"
                        :class="[
                            'rounded-lg px-3 py-1.5 text-[10px] font-black tracking-wider uppercase transition-all',
                            currentPeriod === period.value
                                ? 'bg-cyan-pure text-text-main shadow-sm'
                                : 'text-text-muted hover:text-text-main',
                        ]"
                    >
                        {{ period.label }}
                    </button>
                </div>
            </header>

            <!-- Weight Evolution Card -->
            <GlassCard class="animate-slide-up relative overflow-hidden" style="animation-delay: 0.05s">
                <div class="mb-4 flex items-start justify-between">
                    <div>
                        <h3 class="mb-1 text-[10px] font-black tracking-[0.2em] text-sky-600 uppercase">
                            Poids Corporel
                        </h3>
                        <p class="font-display text-text-main text-5xl font-black tracking-tighter">
                            {{ latestWeight || '—' }}
                            <span class="text-text-muted text-lg">kg</span>
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

                <!-- Real Weight Chart (Deferred) -->
                <div class="relative -mx-2 h-40 w-full">
                    <Deferred data="weightHistory">
                        <template #fallback>
                            <div class="flex h-full items-center justify-center px-4">
                                <GlassSkeleton height="h-32" width="w-full" class="rounded-xl" />
                            </div>
                        </template>
                        <WeightHistoryChart v-if="props.weightHistory?.length > 0" :data="props.weightHistory" />
                        <div v-else class="flex h-full items-center justify-center text-center">
                            <p class="text-text-muted/50 text-sm italic">Pas encore de données de poids</p>
                        </div>
                    </Deferred>
                </div>

                <Link
                    :href="route('body-measurements.index')"
                    class="mt-4 inline-flex items-center gap-2 text-xs font-bold tracking-wider text-sky-600 uppercase transition-all hover:gap-3"
                >
                    Voir tout l'historique
                    <span class="material-symbols-outlined text-base">arrow_forward</span>
                </Link>
            </GlassCard>

            <!-- Body Metrics Grid -->
            <div class="animate-slide-up grid grid-cols-2 gap-4" style="animation-delay: 0.1s">
                <!-- Body Fat -->
                <GlassCard padding="p-5">
                    <div class="flex items-start justify-between">
                        <div>
                            <h4 class="mb-1 text-[10px] font-black tracking-[0.15em] text-pink-600 uppercase">
                                Masse Grasse
                            </h4>
                            <p class="font-display text-text-main text-3xl font-black">
                                {{ bodyFat || '—' }}
                                <span class="text-text-muted text-sm">%</span>
                            </p>
                        </div>
                        <div class="bg-magenta-pure/10 flex size-12 items-center justify-center rounded-xl">
                            <span class="material-symbols-outlined text-2xl text-pink-600">water_drop</span>
                        </div>
                    </div>

                    <!-- Real Body Fat Chart (Deferred) -->
                    <div class="mt-4 h-32 w-full">
                        <Deferred data="bodyFatHistory">
                            <template #fallback>
                                <GlassSkeleton height="h-full" width="w-full" class="rounded-xl" />
                            </template>
                            <BodyFatChart v-if="props.bodyFatHistory?.length > 0" :data="props.bodyFatHistory" />
                            <div v-else class="flex h-full items-center justify-center">
                                <p class="text-text-muted/30 text-[10px] italic">Pas de données historiques</p>
                            </div>
                        </Deferred>
                    </div>
                </GlassCard>

                <!-- This Month Volume -->
                <GlassCard padding="p-5">
                    <Deferred data="monthlyComparison">
                        <template #fallback>
                            <div class="space-y-4">
                                <GlassSkeleton height="h-4" width="w-24" />
                                <GlassSkeleton height="h-10" width="w-32" />
                                <GlassSkeleton height="h-6" width="w-16" class="rounded-lg" />
                            </div>
                        </template>
                        <div class="flex items-start justify-between">
                            <div>
                                <h4 class="mb-1 text-[10px] font-black tracking-[0.15em] text-violet-600 uppercase">
                                    Volume Mois
                                </h4>
                                <p class="font-display text-text-main text-3xl font-black">
                                    {{ Math.round(monthlyComparison?.current_month_volume || 0).toLocaleString() }}
                                    <span class="text-text-muted text-sm">kg</span>
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
                    </Deferred>
                </GlassCard>
            </div>

            <!-- Volume Trend Chart -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.15s">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h3 class="font-display text-text-main text-lg font-black uppercase italic">
                            Évolution du Volume
                        </h3>
                        <p class="text-text-muted text-xs font-semibold">
                            {{
                                currentPeriod === '7j'
                                    ? '7'
                                    : currentPeriod === '30j'
                                      ? '30'
                                      : currentPeriod === '90j'
                                        ? '90'
                                        : '365'
                            }}
                            derniers jours
                        </p>
                    </div>
                    <div class="text-right">
                        <div class="text-text-muted text-xs font-black tracking-wider uppercase">Total</div>
                        <div class="font-display text-electric-orange text-2xl font-black">
                            {{ totalVolume.toLocaleString() }}
                            <span class="text-text-muted text-sm">kg</span>
                        </div>
                    </div>
                </div>
                <div class="h-48">
                    <Deferred data="volumeTrend">
                        <template #fallback>
                            <GlassSkeleton height="h-full" width="w-full" class="rounded-xl" />
                        </template>
                        <div v-if="volumeTrend && volumeTrend.length > 0" class="h-full">
                            <VolumeTrendChart :data="volumeTrend" />
                        </div>
                        <div v-else class="flex h-full flex-col items-center justify-center text-center">
                            <span class="material-symbols-outlined text-text-muted/30 mb-2 text-5xl">bar_chart</span>
                            <p class="text-text-muted text-sm">Pas encore de données de volume</p>
                        </div>
                    </Deferred>
                </div>
            </GlassCard>

            <!-- Duration History Chart -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.18s">
                <div class="mb-4 flex items-center justify-between">
                    <div>
                        <h3 class="font-display text-text-main text-lg font-black uppercase italic">
                            Durée des Séances
                        </h3>
                        <p class="text-text-muted text-xs font-semibold">Historique des 30 dernières séances</p>
                    </div>
                </div>
                <div class="h-48">
                    <Deferred data="durationHistory">
                        <template #fallback>
                            <GlassSkeleton height="h-full" width="w-full" class="rounded-xl" />
                        </template>
                        <div v-if="durationHistory && durationHistory.length > 0" class="h-full">
                            <DurationHistoryChart :data="durationHistory" />
                        </div>
                        <div v-else class="flex h-full flex-col items-center justify-center text-center">
                            <span class="material-symbols-outlined text-text-muted/30 mb-2 text-5xl">timer_off</span>
                            <p class="text-text-muted text-sm">Pas encore de données de durée</p>
                        </div>
                    </Deferred>
                </div>
            </GlassCard>

            <!-- Muscle Distribution & Exercise Progress -->
            <div class="animate-slide-up grid grid-cols-1 gap-6 lg:grid-cols-2" style="animation-delay: 0.2s">
                <!-- Muscle Distribution -->
                <GlassCard>
                    <div class="mb-4">
                        <h3 class="font-display text-text-main text-lg font-black uppercase italic">
                            Répartition Musculaire
                        </h3>
                        <p class="text-text-muted text-xs font-semibold">Volume par groupe musculaire</p>
                    </div>
                    <div class="h-52">
                        <Deferred data="muscleDistribution">
                            <template #fallback>
                                <GlassSkeleton height="h-full" width="w-full" class="rounded-xl" />
                            </template>
                            <div v-if="muscleDistribution && muscleDistribution.length > 0" class="h-full">
                                <MuscleDistributionChart :data="muscleDistribution" />
                            </div>
                            <div v-else class="flex h-full flex-col items-center justify-center text-center">
                                <span class="material-symbols-outlined text-text-muted/30 mb-2 text-5xl"
                                    >pie_chart</span
                                >
                                <p class="text-text-muted text-sm">Données de répartition indisponibles</p>
                            </div>
                        </Deferred>
                    </div>
                </GlassCard>

                <!-- Exercise Progress (1RM) -->
                <GlassCard>
                    <div class="mb-4">
                        <h3 class="font-display text-text-main text-lg font-black uppercase italic">Progression 1RM</h3>
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
                            class="border-electric-orange h-8 w-8 animate-spin rounded-full border-2 border-t-transparent"
                        ></div>
                    </div>
                    <div v-else-if="selectedExercise && exerciseProgressData.length > 0" class="h-48">
                        <OneRepMaxChart :data="exerciseProgressData" />
                    </div>
                    <div
                        v-else-if="selectedExercise"
                        class="flex h-48 flex-col items-center justify-center text-center"
                    >
                        <span class="material-symbols-outlined text-text-muted/30 mb-2 text-4xl">trending_up</span>
                        <p class="text-text-muted text-sm">Pas assez de données pour cet exercice</p>
                    </div>
                    <div v-else class="flex h-48 flex-col items-center justify-center text-center">
                        <span class="material-symbols-outlined text-text-muted/30 mb-2 text-4xl">fitness_center</span>
                        <p class="text-text-muted text-sm">Choisis un exercice pour voir ton évolution</p>
                    </div>
                </GlassCard>
            </div>

            <!-- Summary Stats -->
            <div class="animate-slide-up grid grid-cols-4 gap-3" style="animation-delay: 0.25s">
                <GlassCard padding="p-4" class="text-center">
                    <div class="text-text-muted text-[10px] font-black tracking-wider uppercase">Séances</div>
                    <Deferred data="volumeTrend">
                        <template #fallback>
                            <GlassSkeleton height="h-8" width="w-8" class="mx-auto mt-1" />
                        </template>
                        <div class="font-display text-text-main mt-1 text-2xl font-black">
                            {{ volumeTrend?.length || 0 }}
                        </div>
                    </Deferred>
                </GlassCard>
                <GlassCard padding="p-4" class="text-center">
                    <div class="text-text-muted text-[10px] font-black tracking-wider uppercase">Muscles</div>
                    <Deferred data="muscleDistribution">
                        <template #fallback>
                            <GlassSkeleton height="h-8" width="w-8" class="mx-auto mt-1" />
                        </template>
                        <div class="font-display text-text-main mt-1 text-2xl font-black">
                            {{ muscleDistribution?.length || 0 }}
                        </div>
                    </Deferred>
                </GlassCard>
                <GlassCard padding="p-4" class="text-center">
                    <div class="text-text-muted text-[10px] font-black tracking-wider uppercase">Exercices</div>
                    <div class="font-display text-text-main mt-1 text-2xl font-black">
                        {{ exercises?.length || 0 }}
                    </div>
                </GlassCard>
                <GlassCard padding="p-4" class="text-center">
                    <div class="text-text-muted text-[10px] font-black tracking-wider uppercase">vs Mois -1</div>
                    <Deferred data="monthlyComparison">
                        <template #fallback>
                            <GlassSkeleton height="h-8" width="w-12" class="mx-auto mt-1" />
                        </template>
                        <div
                            :class="[
                                'font-display mt-1 text-2xl font-black',
                                (monthlyComparison?.percentage || 0) >= 0 ? 'text-emerald-500' : 'text-red-500',
                            ]"
                        >
                            {{ (monthlyComparison?.percentage || 0) >= 0 ? '+' : ''
                            }}{{ monthlyComparison?.percentage || 0 }}%
                        </div>
                    </Deferred>
                </GlassCard>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
