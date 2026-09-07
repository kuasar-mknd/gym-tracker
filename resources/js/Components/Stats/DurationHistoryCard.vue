<script setup>
import { Deferred } from '@inertiajs/vue3'
import { defineAsyncComponent } from 'vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'

const DurationHistoryChart = defineAsyncComponent(() => import('@/Components/Stats/DurationHistoryChart.vue'))

defineProps({
    durationHistory: Array,
    deferredData: Object,
})
</script>

<template>
    <!-- Duration History Chart -->
    <GlassCard class="stagger-4 animate-slide-up">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h3 class="font-display text-text-main text-lg font-black uppercase italic">Durée des Séances</h3>
                <p class="text-text-muted text-xs font-semibold">Historique des 30 dernières séances</p>
            </div>
        </div>
        <div class="h-48">
            <Deferred :data="deferredData ? 'deferredData' : 'performanceStats'">
                <template #fallback>
                    <GlassSkeleton height="100%" width="100%" class="rounded-xl" />
                </template>
                <div v-if="durationHistory && durationHistory.length > 0" class="h-full">
                    <DurationHistoryChart :data="durationHistory" />
                </div>
                <div v-else class="flex h-full flex-col items-center justify-center text-center">
                    <GlassIcon name="timer_off" size="2xl" class="text-text-muted/30 mb-2" />
                    <p class="text-text-muted text-sm">Pas encore de données de durée</p>
                </div>
            </Deferred>
        </div>
    </GlassCard>
</template>
