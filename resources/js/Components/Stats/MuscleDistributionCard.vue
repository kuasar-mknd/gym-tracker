<script setup>
import { Deferred } from '@inertiajs/vue3'
import { defineAsyncComponent } from 'vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'

const MuscleDistributionChart = defineAsyncComponent(() => import('@/Components/Stats/MuscleDistributionChart.vue'))

defineProps({
    muscleDistribution: Array,
    deferredData: Object,
})
</script>

<template>
    <!-- Muscle Distribution -->
    <GlassCard>
        <div class="mb-4">
            <h3 class="titre-carte">Répartition Musculaire</h3>
            <p class="text-text-muted text-xs font-semibold">Volume par groupe musculaire</p>
        </div>
        <div class="min-h-52">
            <Deferred :data="deferredData ? 'deferredData' : 'performanceStats'">
                <template #fallback>
                    <GlassSkeleton height="100%" width="100%" class="rounded-xl" />
                </template>
                <div v-if="muscleDistribution && muscleDistribution.length > 0" class="h-full">
                    <MuscleDistributionChart :data="muscleDistribution" />
                </div>
                <div v-else class="flex h-full flex-col items-center justify-center text-center">
                    <GlassIcon name="pie_chart" size="2xl" class="text-text-muted/30 mb-2" />
                    <p class="text-text-muted text-sm">Données de répartition indisponibles</p>
                </div>
            </Deferred>
        </div>
    </GlassCard>
</template>
