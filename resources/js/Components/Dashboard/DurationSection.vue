<script setup>
import { defineAsyncComponent } from 'vue'

const DurationDistributionChart = defineAsyncComponent(() => import('@/Components/Stats/DurationDistributionChart.vue'))

defineProps({
    durationDistribution: { type: Array, required: true },
})
</script>

<template>
    <!-- Duration Distribution Chart -->
    <section
        class="animate-slide-up border-surface-card/20 bg-surface-card/10 hover:bg-surface-card/20 relative overflow-hidden rounded-3xl border p-6 backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl active:scale-95"
        style="animation-delay: 0.17s"
    >
        <div class="relative z-10 mb-6">
            <h3 class="text-accent-info-deep mb-1 text-[10px] font-black tracking-[0.2em] uppercase">Répartition</h3>
            <p class="font-display text-text-main text-2xl font-black uppercase italic">Durée Séances</p>
        </div>

        <!-- Duration Chart -->
        <div class="relative -mx-2 mt-2 w-auto">
            <DurationDistributionChart
                v-if="durationDistribution && durationDistribution.some((d) => d.count > 0)"
                :data="durationDistribution"
            />
            <div v-else class="text-text-muted flex h-full items-center justify-center">
                <p class="text-sm">Pas assez de données (90j)</p>
            </div>
        </div>
    </section>
</template>
