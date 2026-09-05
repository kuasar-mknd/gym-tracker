<script setup>
import { Deferred, Link } from '@inertiajs/vue3'
import { defineAsyncComponent } from 'vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'

const WeightHistoryChart = defineAsyncComponent(() => import('@/Components/Stats/WeightHistoryChart.vue'))

defineProps({
    latestWeight: [Number, String],
    weightChange: Number,
    weightHistory: Array,
    deferredData: Object,
})
</script>

<template>
    <!-- Weight Evolution Card -->
    <GlassCard class="animate-slide-up relative overflow-hidden" style="animation-delay: 0.05s">
        <div class="mb-4 flex items-start justify-between">
            <div>
                <h3 class="text-accent-info-deep mb-1 text-[10px] font-black tracking-[0.2em] uppercase">
                    Poids Corporel
                </h3>
                <p class="font-display text-text-main text-5xl font-black tracking-tighter">
                    {{ latestWeight || '—' }}
                    <span class="text-text-muted text-lg">kg</span>
                </p>
            </div>
            <div
                v-if="weightChange"
                :class="[
                    'flex items-center gap-1 rounded-full px-3 py-1.5 text-xs font-bold',
                    weightChange > 0 ? 'bg-trend-down/10 text-trend-down' : 'bg-trend-up/10 text-trend-up',
                ]"
            >
                <span class="material-symbols-outlined text-sm" aria-hidden="true">
                    {{ weightChange > 0 ? 'trending_up' : 'trending_down' }}
                </span>
                {{ weightChange > 0 ? '+' : '' }}{{ weightChange }} kg
            </div>
        </div>

        <!-- Real Weight Chart (Deferred) -->
        <div class="relative -mx-2 h-40 w-full">
            <Deferred :data="deferredData ? 'deferredData' : 'bodyStats'">
                <template #fallback>
                    <div class="flex h-full items-center justify-center px-4">
                        <GlassSkeleton height="8rem" width="100%" class="rounded-xl" />
                    </div>
                </template>
                <WeightHistoryChart v-if="weightHistory?.length > 0" compact :data="weightHistory" />
                <div v-else class="flex h-full items-center justify-center text-center">
                    <p class="text-text-muted/50 text-sm italic">Pas encore de données de poids</p>
                </div>
            </Deferred>
        </div>

        <Link
            :href="route('body-measurements.index')"
            class="text-accent-info-deep mt-4 inline-flex items-center gap-2 text-xs font-bold tracking-wider uppercase transition-all hover:gap-3"
        >
            Voir tout l'historique
            <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_forward</span>
        </Link>
    </GlassCard>
</template>
