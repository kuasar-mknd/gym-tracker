<script setup>
import { Deferred } from '@inertiajs/vue3'
import { defineAsyncComponent } from 'vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'

const TimeOfDayChart = defineAsyncComponent(() => import('@/Components/Stats/TimeOfDayChart.vue'))

defineProps({
    timeOfDayDistribution: { type: Array, required: true },
})
</script>

<template>
    <!-- Time of Day Distribution Chart -->
    <section
        class="animate-slide-up relative overflow-hidden rounded-3xl border border-white/20 bg-white/10 p-6 backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:bg-white/20 hover:shadow-xl active:scale-95"
        style="animation-delay: 0.18s"
    >
        <div class="relative z-10 mb-6">
            <h3 class="text-hot-pink mb-1 text-[10px] font-black tracking-[0.2em] uppercase">Habitudes</h3>
            <p class="font-display text-text-main text-2xl font-black uppercase italic dark:text-white">
                Horaire Favori
            </p>
        </div>

        <!-- Time of Day Chart -->
        <div class="relative -mx-2 mt-2 h-48 w-auto">
            <TimeOfDayChart
                v-if="timeOfDayDistribution && timeOfDayDistribution.some((d) => d.count > 0)"
                :data="timeOfDayDistribution"
            />
            <div v-else class="text-text-muted flex h-full items-center justify-center">
                <p class="text-sm">Pas assez de données (90j)</p>
            </div>
        </div>
    </section>
</template>
