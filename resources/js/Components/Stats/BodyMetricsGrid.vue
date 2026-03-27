<script setup>
import { Deferred } from '@inertiajs/vue3'
import { defineAsyncComponent } from 'vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'

const BodyFatChart = defineAsyncComponent(() => import('@/Components/Stats/BodyFatChart.vue'))

defineProps({
    bodyFat: Number,
    bodyFatHistory: Array,
    monthlyComparison: Object,
})
</script>

<template>
    <!-- Body Metrics Grid -->
    <div class="animate-slide-up grid grid-cols-2 gap-4" style="animation-delay: 0.1s">
        <!-- Body Fat -->
        <GlassCard padding="p-5">
            <div class="flex items-start justify-between">
                <div>
                    <h4 class="mb-1 text-[10px] font-black tracking-[0.15em] text-pink-600 uppercase">Masse Grasse</h4>
                    <p class="font-display text-text-main text-3xl font-black">
                        {{ bodyFat || '—' }}
                        <span class="text-text-muted text-sm">%</span>
                    </p>
                </div>
                <div class="bg-magenta-pure/10 flex size-12 items-center justify-center rounded-xl">
                    <span class="material-symbols-outlined text-2xl text-pink-600">water_drop</span>
                </div>
            </div>

            <!-- Real Body Fat Chart (Deferred) -->
            <div class="mt-4 h-32 w-full">
                <Deferred data="bodyStats">
                    <template #fallback>
                        <GlassSkeleton height="100%" width="100%" class="rounded-xl" />
                    </template>
                    <BodyFatChart v-if="bodyFatHistory?.length > 0" :data="bodyFatHistory" />
                    <div v-else class="flex h-full items-center justify-center">
                        <p class="text-text-muted/30 text-[10px] italic">Pas de données historiques</p>
                    </div>
                </Deferred>
            </div>
        </GlassCard>

        <!-- This Month Volume -->
        <GlassCard padding="p-5">
            <Deferred data="performanceStats">
                <template #fallback>
                    <div class="space-y-4">
                        <GlassSkeleton height="1rem" width="6rem" />
                        <GlassSkeleton height="2.5rem" width="8rem" />
                        <GlassSkeleton height="1.5rem" width="4rem" class="rounded-lg" />
                    </div>
                </template>
                <div class="flex items-start justify-between">
                    <div>
                        <h4 class="mb-1 text-[10px] font-black tracking-[0.15em] text-violet-600 uppercase">
                            Volume Mois
                        </h4>
                        <p class="font-display text-text-main text-3xl font-black">
                            {{ Math.round(monthlyComparison?.current_volume || 0).toLocaleString() }}
                            <span class="text-text-muted text-sm">kg</span>
                        </p>
                    </div>
                    <div
                        :class="[
                            'flex items-center gap-0.5 rounded-lg px-2 py-1 text-xs font-bold',
                            (monthlyComparison?.percentage || 0) >= 0
                                ? 'bg-emerald-50 text-emerald-600'
                                : 'bg-red-50 text-red-600',
                        ]"
                    >
                        <span class="material-symbols-outlined text-sm">
                            {{ (monthlyComparison?.percentage || 0) >= 0 ? 'trending_up' : 'trending_down' }}
                        </span>
                        {{ (monthlyComparison?.percentage || 0) >= 0 ? '+' : ''
                        }}{{ monthlyComparison?.percentage || 0 }}%
                    </div>
                </div>
            </Deferred>
        </GlassCard>
    </div>
</template>
