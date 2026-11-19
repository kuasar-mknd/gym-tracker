<script setup>
import { graphiqueAsynchrone } from '@/Utils/graphiqueAsynchrone'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'

const TimeOfDayChart = graphiqueAsynchrone(() => import('@/Components/Stats/TimeOfDayChart.vue'))

defineProps({
    timeOfDayDistribution: { type: Array, required: true },
})
</script>

<template>
    <!-- Time of Day Distribution Chart -->
    <section class="stagger-4 animate-slide-up verre-fin rounded-carte relative overflow-hidden p-5 sm:p-6">
        <div class="relative z-10 mb-5">
            <p class="etiquette">Habitudes</p>
            <h3 class="titre-plat">Horaire favori</h3>
        </div>

        <!-- Time of Day Chart -->
        <div class="relative -mx-2 mt-2 w-auto">
            <TimeOfDayChart
                v-if="timeOfDayDistribution && timeOfDayDistribution.some((d) => d.count > 0)"
                :data="timeOfDayDistribution"
            />
            <GlassEmptyState v-else taille="ligne" icon="schedule" title="Pas assez de données (90j)" />
        </div>
    </section>
</template>
