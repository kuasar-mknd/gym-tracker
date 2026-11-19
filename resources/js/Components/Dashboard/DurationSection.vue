<script setup>
import { graphiqueAsynchrone } from '@/Utils/graphiqueAsynchrone'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'

const DurationDistributionChart = graphiqueAsynchrone(() => import('@/Components/Stats/DurationDistributionChart.vue'))

defineProps({
    durationDistribution: { type: Array, required: true },
})
</script>

<template>
    <!-- Duration Distribution Chart -->
    <section class="stagger-3 animate-slide-up carte-plate rounded-2xl p-6">
        <div class="relative z-10 mb-6">
            <p class="text-text-muted etiquette mb-1">Répartition</p>
            <p class="titre-plat">Durée séances</p>
        </div>

        <!-- Duration Chart -->
        <div class="relative -mx-2 mt-2 w-auto">
            <DurationDistributionChart
                v-if="durationDistribution && durationDistribution.some((d) => d.count > 0)"
                :data="durationDistribution"
            />
            <GlassEmptyState v-else taille="ligne" icon="timer" title="Pas assez de données (90j)" />
        </div>
    </section>
</template>
