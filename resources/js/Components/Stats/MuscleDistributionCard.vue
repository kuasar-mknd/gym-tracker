<script setup>
import { Deferred } from '@inertiajs/vue3'
import { defineAsyncComponent } from 'vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'

const MuscleDistributionChart = defineAsyncComponent(() => import('@/Components/Stats/MuscleDistributionChart.vue'))

defineProps({
    muscleDistribution: Array,
})
</script>

<template>
    <!-- Muscle Distribution -->
    <GlassCard>
        <div class="mb-4">
            <h3 class="font-display text-text-main text-lg font-black uppercase italic">Répartition Musculaire</h3>
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
                    <span class="material-symbols-outlined text-text-muted/30 mb-2 text-5xl">pie_chart</span>
                    <p class="text-text-muted text-sm">Données de répartition indisponibles</p>
                </div>
            </Deferred>
        </div>
    </GlassCard>
</template>
