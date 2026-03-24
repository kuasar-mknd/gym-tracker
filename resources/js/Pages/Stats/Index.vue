<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, router } from '@inertiajs/vue3'
import { ref } from 'vue'

import WeightEvolutionCard from '@/Components/Stats/WeightEvolutionCard.vue'
import BodyMetricsGrid from '@/Components/Stats/BodyMetricsGrid.vue'
import VolumeTrendCard from '@/Components/Stats/VolumeTrendCard.vue'
import DurationHistoryCard from '@/Components/Stats/DurationHistoryCard.vue'
import MuscleDistributionCard from '@/Components/Stats/MuscleDistributionCard.vue'
import ExerciseProgressCard from '@/Components/Stats/ExerciseProgressCard.vue'
import SummaryStatsGrid from '@/Components/Stats/SummaryStatsGrid.vue'

const props = defineProps({
    // ⚡ Bolt: Consolidated props
    performanceStats: Object,
    bodyStats: Object,

    exercises: Array,
    latestWeight: [Number, String],
    weightChange: Number,
    bodyFat: Number,
    selectedPeriod: String,
})

const currentPeriod = ref(props.selectedPeriod || '30j')

const periods = [
    { value: '7j', label: '7 JOURS' },
    { value: '30j', label: '30 JOURS' },
    { value: '90j', label: '3 MOIS' },
    { value: '1a', label: '1 AN' },
]

const handlePeriodChange = (period) => {
    currentPeriod.value = period
    router.visit(route('stats.index'), {
        data: { period },
        preserveScroll: true,
        preserveState: true,
    })
}
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
                        v-press="{ haptic: 'selection' }"
                        @click="handlePeriodChange(period.value)"
                        :class="[
                            'rounded-lg px-3 py-1.5 text-[10px] font-black tracking-wider uppercase transition-all',
                            currentPeriod === period.value
                                ? 'bg-cyan-pure text-text-main shadow-sm'
                                : 'text-text-muted hover:text-text-main',
                        ]"
                        :aria-pressed="currentPeriod === period.value"
                    >
                        {{ period.label }}
                    </button>
                </div>
            </header>

            <WeightEvolutionCard
                :latest-weight="latestWeight"
                :weight-change="weightChange"
                :weight-history="bodyStats?.weightHistory"
            />

            <BodyMetricsGrid
                :body-fat="bodyFat"
                :body-fat-history="bodyStats?.bodyFatHistory"
                :monthly-comparison="performanceStats?.monthlyComparison"
            />

            <VolumeTrendCard :volume-trend="performanceStats?.volumeTrend" :current-period="currentPeriod" />

            <DurationHistoryCard :duration-history="performanceStats?.durationHistory" />

            <div class="animate-slide-up grid grid-cols-1 gap-6 lg:grid-cols-2" style="animation-delay: 0.2s">
                <MuscleDistributionCard :muscle-distribution="performanceStats?.muscleDistribution" />

                <ExerciseProgressCard :exercises="exercises" />
            </div>

            <SummaryStatsGrid
                :volume-trend="performanceStats?.volumeTrend"
                :muscle-distribution="performanceStats?.muscleDistribution"
                :exercises="exercises"
                :monthly-comparison="performanceStats?.monthlyComparison"
            />
        </div>
    </AuthenticatedLayout>
</template>
