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
import GlassSegmented from '@/Components/UI/GlassSegmented.vue'

const props = defineProps({
    // ⚡ Bolt: Consolidated deferred data
    deferredData: Object,

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

    <AuthenticatedLayout liquid-variant="cyan-magenta" page-title="Mon Évolution">
        <div class="space-y-6">
            <!-- Header -->
            <header class="animate-fade-in flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <!--
                    Le titre du bureau. Sur téléphone, c'est la barre collante de
                    la mise en page qui le porte, et l'afficher ici aussi le
                    dirait deux fois — en plus de mettre deux `<h1>` dans une
                    même page.
                -->
                <div class="hidden sm:block">
                    <h1
                        class="font-display text-text-main text-3xl leading-none font-black tracking-tighter uppercase italic sm:text-4xl"
                    >
                        Mon<br />
                        <span class="text-gradient-cyan-magenta">Évolution</span>
                    </h1>
                </div>

                <GlassSegmented
                    :model-value="currentPeriod"
                    :options="periods"
                    label="Période"
                    size="sm"
                    class="shrink-0"
                    @update:model-value="handlePeriodChange"
                />
            </header>

            <WeightEvolutionCard
                :latest-weight="latestWeight"
                :weight-change="weightChange"
                :weight-history="deferredData?.body?.weightHistory"
                :deferred-data="deferredData"
            />

            <BodyMetricsGrid
                :body-fat="bodyFat"
                :body-fat-history="deferredData?.body?.bodyFatHistory"
                :monthly-comparison="deferredData?.performance?.monthlyComparison"
                :deferred-data="deferredData"
            />

            <VolumeTrendCard
                :volume-trend="deferredData?.performance?.volumeTrend"
                :current-period="currentPeriod"
                :deferred-data="deferredData"
            />

            <DurationHistoryCard
                :duration-history="deferredData?.performance?.durationHistory"
                :deferred-data="deferredData"
            />

            <div class="stagger-4 animate-slide-up grid grid-cols-1 gap-6 lg:grid-cols-2">
                <MuscleDistributionCard
                    :muscle-distribution="deferredData?.performance?.muscleDistribution"
                    :deferred-data="deferredData"
                />

                <ExerciseProgressCard :exercises="exercises" />
            </div>

            <SummaryStatsGrid
                :volume-trend="deferredData?.performance?.volumeTrend"
                :muscle-distribution="deferredData?.performance?.muscleDistribution"
                :exercises="exercises"
                :monthly-comparison="deferredData?.performance?.monthlyComparison"
                :deferred-data="deferredData"
            />
        </div>
    </AuthenticatedLayout>
</template>
