<script setup>
import GlassCard from '@/Components/UI/GlassCard.vue'
import { defineAsyncComponent } from 'vue'

const RecentPRsChart = defineAsyncComponent(() => import('@/Components/Stats/RecentPRsChart.vue'))

defineProps({
    recentPRs: { type: Array, required: true },
})
</script>

<template>
    <!-- Recent PRs -->
    <section v-if="recentPRs.length > 0" class="animate-slide-up" style="animation-delay: 0.3s">
        <div class="mb-4 flex items-center justify-between px-1">
            <h3 class="text-text-muted text-xs font-black tracking-[0.2em] uppercase">Records personnels</h3>
        </div>

        <GlassCard class="mb-4" padding="p-4">
            <div class="mb-2">
                <h4 class="font-display text-text-main text-sm font-black uppercase italic dark:text-white">
                    Aperçu des performances
                </h4>
            </div>
            <RecentPRsChart :data="recentPRs" />
        </GlassCard>

        <div class="space-y-3">
            <GlassCard v-for="pr in recentPRs" :key="pr.id" padding="p-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div
                            class="flex size-12 items-center justify-center rounded-xl bg-linear-to-br from-yellow-400 to-orange-500 shadow-lg"
                        >
                            <span class="material-symbols-outlined text-2xl text-white">star</span>
                        </div>
                        <div>
                            <div class="text-text-main font-bold dark:text-white">{{ pr.exercise?.name }}</div>
                            <div class="text-text-muted text-xs">
                                {{
                                    pr.type === 'max_weight'
                                        ? 'Poids Max'
                                        : pr.type === 'max_1rm'
                                          ? '1RM Estimé'
                                          : 'Volume'
                                }}
                            </div>
                        </div>
                    </div>
                    <div class="text-right">
                        <div class="font-display text-electric-orange text-2xl font-black">
                            {{ pr.value
                            }}<span class="text-text-muted text-sm">{{
                                pr.type === 'max_volume_set' ? '' : 'kg'
                            }}</span>
                        </div>
                    </div>
                </div>
            </GlassCard>
        </div>
    </section>
</template>
