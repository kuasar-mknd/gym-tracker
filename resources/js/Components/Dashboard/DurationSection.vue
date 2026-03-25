<script setup>
import { Deferred } from '@inertiajs/vue3'
import { defineAsyncComponent } from 'vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'

const DurationDistributionChart = defineAsyncComponent(() => import('@/Components/Stats/DurationDistributionChart.vue'))

defineProps({
    durationDistribution: { type: Array, required: true },
})
</script>

<template>
    <!-- Duration Distribution Chart -->
    <section
        class="glass-panel-light animate-slide-up relative overflow-hidden rounded-3xl p-6"
        style="animation-delay: 0.17s"
    >
        <div class="relative z-10 mb-6">
            <h3 class="text-cyan-pure mb-1 text-[10px] font-black tracking-[0.2em] uppercase">Répartition</h3>
            <p class="font-display text-text-main text-2xl font-black uppercase italic dark:text-white">
                Durée Séances
            </p>
        </div>

        <!-- Duration Chart -->
        <div class="relative -mx-2 mt-2 h-48 w-auto">
            <Deferred data="workoutDistributions">
                <template #fallback>
                    <GlassSkeleton height="100%" width="100%" variant="circle" />
                </template>
                <DurationDistributionChart
                    v-if="durationDistribution && durationDistribution.some((d) => d.count > 0)"
                    :data="durationDistribution"
                />
                <div v-else class="text-text-muted flex h-full items-center justify-center">
                    <p class="text-sm">Pas assez de données (90j)</p>
                </div>
            </Deferred>
        </div>
    </section>
</template>
