<script setup>
import { Deferred, Link } from '@inertiajs/vue3'
import { defineAsyncComponent } from 'vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'
import { nombre, variation } from '@/Utils/nombre'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'

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
    <GlassCard class="stagger-1 animate-slide-up relative overflow-hidden">
        <div class="mb-4 flex items-start justify-between">
            <div>
                <h3 class="sur-titre text-accent-info-deep mb-1">Poids Corporel</h3>
                <p class="font-display text-text-main text-5xl font-black tracking-tighter">
                    {{ latestWeight ? nombre(latestWeight) : '—' }}
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
                <GlassIcon :name="weightChange > 0 ? 'trending_up' : 'trending_down'" size="xs" />
                {{ variation(weightChange) }}
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
                <GlassEmptyState v-else taille="ligne" icon="monitor_weight" title="Pas encore de données de poids" />
            </Deferred>
        </div>

        <Link
            :href="route('body-measurements.index')"
            class="text-accent-info-deep mt-4 inline-flex items-center gap-2 text-xs font-bold tracking-wider uppercase transition hover:gap-3"
        >
            Voir tout l'historique
            <GlassIcon name="arrow_forward" size="xs" />
        </Link>
    </GlassCard>
</template>
