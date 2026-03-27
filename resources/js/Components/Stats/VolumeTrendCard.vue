<script setup>
import { Deferred } from '@inertiajs/vue3'
import { defineAsyncComponent, computed } from 'vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'

const VolumeTrendChart = defineAsyncComponent(() => import('@/Components/Stats/VolumeTrendChart.vue'))

const props = defineProps({
    volumeTrend: Array,
    currentPeriod: String,
})

const totalVolume = computed(() => {
    return props.volumeTrend?.reduce((acc, curr) => acc + curr.volume, 0) || 0
})
</script>

<template>
    <!-- Volume Trend Chart -->
    <GlassCard class="animate-slide-up" style="animation-delay: 0.15s">
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h3 class="font-display text-text-main text-lg font-black uppercase italic">Évolution du Volume</h3>
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
                <div class="font-display text-electric-orange text-2xl font-black">
                    {{ totalVolume.toLocaleString() }}
                    <span class="text-text-muted text-sm">kg</span>
                </div>
            </div>
        </div>
        <div class="h-48">
            <Deferred data="performanceStats">
                <template #fallback>
                    <GlassSkeleton height="100%" width="100%" class="rounded-xl" />
                </template>
                <div v-if="volumeTrend && volumeTrend.length > 0" class="h-full">
                    <VolumeTrendChart :data="volumeTrend" />
                </div>
                <div v-else class="flex h-full flex-col items-center justify-center text-center">
                    <span class="material-symbols-outlined text-text-muted/30 mb-2 text-5xl">bar_chart</span>
                    <p class="text-text-muted text-sm">Pas encore de données de volume</p>
                </div>
            </Deferred>
        </div>
    </GlassCard>
</template>
