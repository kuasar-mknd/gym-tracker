<script setup>
import { Deferred } from '@inertiajs/vue3'
import { defineAsyncComponent } from 'vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'

const DurationHistoryChart = defineAsyncComponent(() => import('@/Components/Stats/DurationHistoryChart.vue'))

defineProps({
    durationHistory: Array,
})
</script>

<template>
    <!-- Duration History Chart -->
    <GlassCard class="animate-slide-up" style="animation-delay: 0.18s">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h3 class="font-display text-text-main text-lg font-black uppercase italic">Durée des Séances</h3>
                <p class="text-text-muted text-xs font-semibold">Historique des 30 dernières séances</p>
            </div>
        </div>
        <div class="h-48">
            <Deferred data="durationHistory">
                <template #fallback>
                    <GlassSkeleton height="h-full" width="w-full" class="rounded-xl" />
                </template>
                <div v-if="durationHistory && durationHistory.length > 0" class="h-full">
                    <DurationHistoryChart :data="durationHistory" />
                </div>
                <div v-else class="flex h-full flex-col items-center justify-center text-center">
                    <span class="material-symbols-outlined text-text-muted/30 mb-2 text-5xl">timer_off</span>
                    <p class="text-text-muted text-sm">Pas encore de données de durée</p>
                </div>
            </Deferred>
        </div>
    </GlassCard>
</template>
