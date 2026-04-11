<script setup>
/**
 * SummaryStatsGrid.vue
 *
 * This component displays a grid of four high-level statistics cards.
 * It provides a quick overview of the user's workout activity, muscle group
 * distribution, available exercises, and a monthly volume comparison.
 *
 * Many of these statistics rely on asynchronous or deferred data, and the
 * component handles the loading states using Inertia.js's <Deferred> wrapper
 * with fallback skeletons.
 */

import { Deferred } from '@inertiajs/vue3'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'

defineProps({
    /**
     * The trend array detailing the workout volume over a specific time period.
     * Used here to determine the total number of sessions.
     */
    volumeTrend: Array,

    /**
     * The distribution of workout volume across different muscle groups.
     * Used to calculate the total number of targeted muscle groups.
     */
    muscleDistribution: Array,

    /**
     * The list of all available exercises.
     * Used to display the total count of exercises known to the system.
     */
    exercises: Array,

    /**
     * An object containing the current month's volume, previous month's volume,
     * absolute difference, and the percentage change.
     */
    monthlyComparison: Object,

    /**
     * Consolidated deferred data object.
     */
    deferredData: Object,
})
</script>

<template>
    <!-- Summary Stats -->
    <div class="animate-slide-up grid grid-cols-4 gap-3" style="animation-delay: 0.25s">
        <!-- Number of Sessions Card -->
        <div
            class="group relative overflow-hidden rounded-3xl border border-white/20 bg-white/10 p-4 text-center backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:bg-white/20 hover:shadow-xl active:scale-95"
        >
            <div class="text-text-muted text-[10px] font-black tracking-wider uppercase">Séances</div>
            <Deferred :data="deferredData ? 'deferredData' : 'performanceStats'">
                <template #fallback>
                    <GlassSkeleton height="2rem" width="2rem" class="mx-auto mt-1" />
                </template>
                <div class="font-display text-text-main mt-1 text-2xl font-black">
                    {{ volumeTrend?.length || 0 }}
                </div>
            </Deferred>
        </div>

        <!-- Number of Muscles Targeted Card -->
        <div
            class="group relative overflow-hidden rounded-3xl border border-white/20 bg-white/10 p-4 text-center backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:bg-white/20 hover:shadow-xl active:scale-95"
        >
            <div class="text-text-muted text-[10px] font-black tracking-wider uppercase">Muscles</div>
            <Deferred :data="deferredData ? 'deferredData' : 'performanceStats'">
                <template #fallback>
                    <GlassSkeleton height="2rem" width="2rem" class="mx-auto mt-1" />
                </template>
                <div class="font-display text-text-main mt-1 text-2xl font-black">
                    {{ muscleDistribution?.length || 0 }}
                </div>
            </Deferred>
        </div>

        <!-- Number of Exercises Card -->
        <div
            class="group relative overflow-hidden rounded-3xl border border-white/20 bg-white/10 p-4 text-center backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:bg-white/20 hover:shadow-xl active:scale-95"
        >
            <div class="text-text-muted text-[10px] font-black tracking-wider uppercase">Exercices</div>
            <div class="font-display text-text-main mt-1 text-2xl font-black">
                {{ exercises?.length || 0 }}
            </div>
        </div>

        <!-- Monthly Comparison Volume Change Card -->
        <div
            class="group relative overflow-hidden rounded-3xl border border-white/20 bg-white/10 p-4 text-center backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:bg-white/20 hover:shadow-xl active:scale-95"
        >
            <div class="text-text-muted text-[10px] font-black tracking-wider uppercase">vs Mois -1</div>
            <Deferred :data="deferredData ? 'deferredData' : 'performanceStats'">
                <template #fallback>
                    <GlassSkeleton height="2rem" width="3rem" class="mx-auto mt-1" />
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
        </div>
    </div>
</template>
