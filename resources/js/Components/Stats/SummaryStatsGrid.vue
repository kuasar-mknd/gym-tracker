<script setup>
import { Deferred } from '@inertiajs/vue3'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'

defineProps({
    volumeTrend: Array,
    muscleDistribution: Array,
    exercises: Array,
    monthlyComparison: Object,
})
</script>

<template>
    <!-- Summary Stats -->
    <div class="animate-slide-up grid grid-cols-4 gap-3" style="animation-delay: 0.25s">
        <GlassCard padding="p-4" class="text-center">
            <div class="text-text-muted text-[10px] font-black tracking-wider uppercase">Séances</div>
            <Deferred data="volumeTrend">
                <template #fallback>
                    <GlassSkeleton height="h-8" width="w-8" class="mx-auto mt-1" />
                </template>
                <div class="font-display text-text-main mt-1 text-2xl font-black">
                    {{ volumeTrend?.length || 0 }}
                </div>
            </Deferred>
        </GlassCard>
        <GlassCard padding="p-4" class="text-center">
            <div class="text-text-muted text-[10px] font-black tracking-wider uppercase">Muscles</div>
            <Deferred data="muscleDistribution">
                <template #fallback>
                    <GlassSkeleton height="h-8" width="w-8" class="mx-auto mt-1" />
                </template>
                <div class="font-display text-text-main mt-1 text-2xl font-black">
                    {{ muscleDistribution?.length || 0 }}
                </div>
            </Deferred>
        </GlassCard>
        <GlassCard padding="p-4" class="text-center">
            <div class="text-text-muted text-[10px] font-black tracking-wider uppercase">Exercices</div>
            <div class="font-display text-text-main mt-1 text-2xl font-black">
                {{ exercises?.length || 0 }}
            </div>
        </GlassCard>
        <GlassCard padding="p-4" class="text-center">
            <div class="text-text-muted text-[10px] font-black tracking-wider uppercase">vs Mois -1</div>
            <Deferred data="monthlyComparison">
                <template #fallback>
                    <GlassSkeleton height="h-8" width="w-12" class="mx-auto mt-1" />
                </template>
                <div
                    :class="[
                        'font-display mt-1 text-2xl font-black',
                        (monthlyComparison?.percentage || 0) >= 0 ? 'text-emerald-500' : 'text-red-500',
                    ]"
                >
                    {{ (monthlyComparison?.percentage || 0) >= 0 ? '+' : '' }}{{ monthlyComparison?.percentage || 0 }}%
                </div>
            </Deferred>
        </GlassCard>
    </div>
</template>
