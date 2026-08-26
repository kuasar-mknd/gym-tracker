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

import { computed } from 'vue'
import { Deferred } from '@inertiajs/vue3'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'

const props = defineProps({
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

/**
 * La variation mensuelle, ou null quand il n'y a pas de mois precedent.
 *
 * `undefined` et `null` sont ramenes au meme cas : le premier arrive quand la
 * prop n'est pas encore la — la carte est derriere un Deferred — le second quand
 * le serveur dit qu'il n'y a rien a comparer.
 */
const monthlyChange = computed(() => props.monthlyComparison?.percentage ?? null)
</script>

<template>
    <!-- Summary Stats -->
    <div class="animate-slide-up grid grid-cols-4 gap-3" style="animation-delay: 0.25s">
        <!-- Number of Sessions Card -->
        <div
            class="group border-surface-card/20 bg-surface-card/10 hover:bg-surface-card/20 relative overflow-hidden rounded-3xl border p-4 text-center backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl active:scale-95"
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
            class="group border-surface-card/20 bg-surface-card/10 hover:bg-surface-card/20 relative overflow-hidden rounded-3xl border p-4 text-center backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl active:scale-95"
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
            class="group border-surface-card/20 bg-surface-card/10 hover:bg-surface-card/20 relative overflow-hidden rounded-3xl border p-4 text-center backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl active:scale-95"
        >
            <div class="text-text-muted text-[10px] font-black tracking-wider uppercase">Exercices</div>
            <div class="font-display text-text-main mt-1 text-2xl font-black">
                {{ exercises?.length || 0 }}
            </div>
        </div>

        <!-- Monthly Comparison Volume Change Card -->
        <div
            class="group border-surface-card/20 bg-surface-card/10 hover:bg-surface-card/20 relative overflow-hidden rounded-3xl border p-4 text-center backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl active:scale-95"
        >
            <div class="text-text-muted text-[10px] font-black tracking-wider uppercase">vs Mois -1</div>
            <Deferred :data="deferredData ? 'deferredData' : 'performanceStats'">
                <template #fallback>
                    <GlassSkeleton height="2rem" width="3rem" class="mx-auto mt-1" />
                </template>
                <!--
                    `|| 0` traitait l'absence de comparaison comme une variation
                    nulle et affichait « +0 % » : un chiffre a la place d'un mois
                    precedent qui n'existe pas. Le tiret dit la meme chose sans
                    pretendre mesurer quoi que ce soit (#1388).
                -->
                <div
                    v-if="monthlyChange === null"
                    class="font-display text-text-muted mt-1 text-2xl font-black"
                    title="Pas de mois précédent à comparer"
                >
                    —
                </div>
                <div
                    v-else
                    :class="[
                        'font-display mt-1 text-2xl font-black',
                        monthlyChange >= 0 ? 'text-trend-up' : 'text-trend-down',
                    ]"
                >
                    {{ monthlyChange >= 0 ? '+' : '' }}{{ monthlyChange }}%
                </div>
            </Deferred>
        </div>
    </div>
</template>
