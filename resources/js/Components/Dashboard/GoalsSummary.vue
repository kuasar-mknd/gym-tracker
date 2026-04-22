<script setup>
import { Link } from '@inertiajs/vue3'
import GlassCard from '@/Components/UI/GlassCard.vue'

defineProps({
    activeGoals: { type: Array, required: true },
})
</script>

<template>
    <!-- Goals Summary (Compact) -->
    <section v-if="activeGoals.length > 0" class="animate-slide-up" style="animation-delay: 0.25s">
        <div class="mb-4 flex items-center justify-between px-1">
            <h3 class="text-text-muted text-xs font-black tracking-[0.2em] uppercase">Objectifs en cours</h3>
            <Link
                :href="route('goals.index')"
                class="text-electric-orange focus-visible:ring-electric-orange rounded-sm text-xs font-bold tracking-wider uppercase focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                Voir tout
            </Link>
        </div>

        <div class="space-y-3">
            <Link
                v-for="goal in activeGoals"
                :key="goal.id"
                :href="route('goals.index')"
                class="focus-visible:ring-electric-orange block rounded-3xl focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
            >
                <GlassCard :hover="true" padding="p-4">
                    <div class="mb-2 flex items-center justify-between">
                        <span class="text-text-main line-clamp-1 text-sm font-bold">{{ goal.title }}</span>
                        <span class="text-electric-orange text-xs font-black"
                            >{{ Math.round(goal.progress_pct) }}%</span
                        >
                    </div>
                    <div
                        class="h-2 w-full overflow-hidden rounded-full bg-slate-100 dark:bg-slate-700"
                        role="progressbar"
                        :aria-valuenow="goal.progress_pct"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    >
                        <div
                            class="glow-orange bg-gradient-main h-full transition-all duration-1000"
                            :style="{ width: goal.progress_pct + '%' }"
                        ></div>
                    </div>
                </GlassCard>
            </Link>
        </div>
    </section>
</template>
