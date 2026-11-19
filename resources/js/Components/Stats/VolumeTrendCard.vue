<script setup>
import { Deferred } from '@inertiajs/vue3'
import IndisponibleHorsLigne from '@/Components/UI/IndisponibleHorsLigne.vue'
import { computed } from 'vue'
import { graphiqueAsynchrone } from '@/Utils/graphiqueAsynchrone'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'
import { nombre } from '@/Utils/nombre'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'

const VolumeTrendChart = graphiqueAsynchrone(() => import('@/Components/Stats/VolumeTrendChart.vue'))

const props = defineProps({
    volumeTrend: Array,
    currentPeriod: String,
    deferredData: Object,
})

/** Sans total tant que la période n'est pas arrivée : « 0 kg » dirait qu'on n'a rien soulevé. */
const totalVolume = computed(() => {
    return Array.isArray(props.volumeTrend) ? props.volumeTrend.reduce((acc, curr) => acc + curr.volume, 0) : null
})
</script>

<template>
    <!-- Volume Trend Chart -->
    <GlassCard class="stagger-3 animate-slide-up">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h3 class="titre-carte">Évolution du volume</h3>
                <p class="text-text-muted text-xs font-semibold">
                    {{
                        currentPeriod === '7j'
                            ? '7'
                            : currentPeriod === '30j'
                              ? '30'
                              : currentPeriod === '90j'
                                ? '90'
                                : '365'
                    }}
                    derniers jours
                </p>
            </div>
            <div class="text-right">
                <div class="text-text-muted text-xs font-black tracking-wider uppercase">Total</div>
                <div class="font-display text-accent-primary-deep text-2xl font-black">
                    {{ totalVolume === null ? '—' : nombre(totalVolume, 0) }}
                    <span v-if="totalVolume !== null" class="text-text-muted text-sm">kg</span>
                </div>
            </div>
        </div>
        <div class="h-48">
            <Deferred :data="deferredData ? 'deferredData' : 'performanceStats'">
                <template #rescue>
                    <IndisponibleHorsLigne />
                </template>
                <template #fallback>
                    <GlassSkeleton height="100%" width="100%" class="rounded-xl" />
                </template>
                <div v-if="volumeTrend && volumeTrend.length > 0" class="h-full">
                    <VolumeTrendChart :data="volumeTrend" />
                </div>
                <GlassEmptyState v-else taille="ligne" icon="bar_chart" title="Pas encore de données de volume" />
            </Deferred>
        </div>
    </GlassCard>
</template>
