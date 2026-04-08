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
    <section
        v-if="recentPRs.length > 0"
        class="animate-slide-up relative overflow-hidden rounded-3xl border border-white/20 bg-white/10 p-6 backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:bg-white/20 hover:shadow-xl active:scale-95"
        style="animation-delay: 0.3s"
    >
        <div class="relative z-10 mb-6">
            <h3 class="mb-1 text-[10px] font-black tracking-[0.2em] text-[#FF5500] uppercase">Réussites</h3>
            <p class="font-display text-text-main text-2xl font-black uppercase italic dark:text-white">
                Records Personnels
            </p>
        </div>

        <!-- PR Bar Chart -->
        <div class="relative -mx-2 mt-2 mb-6 h-48 w-auto">
            <RecentPRsChart :data="recentPRs" />
        </div>

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
