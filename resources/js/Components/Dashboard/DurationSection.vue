<script setup>
import { defineAsyncComponent } from 'vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'

const DurationDistributionChart = defineAsyncComponent(() => import('@/Components/Stats/DurationDistributionChart.vue'))

defineProps({
    durationDistribution: { type: Array, required: true },
})
</script>

<template>
    <!-- Duration Distribution Chart -->
    <section
        class="stagger-3 animate-slide-up border-surface-card/20 bg-surface-card/10 hover:bg-surface-card/20 relative overflow-hidden rounded-3xl border p-6 backdrop-blur-md transition duration-300 hover:-translate-y-1 hover:shadow-xl active:scale-95"
    >
        <div class="relative z-10 mb-6">
            <h3 class="text-accent-info-deep sur-titre mb-1">Répartition</h3>
            <p class="font-display text-text-main text-2xl font-black uppercase italic">Durée Séances</p>
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
