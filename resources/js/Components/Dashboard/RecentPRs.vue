<script setup>
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { defineAsyncComponent } from 'vue'
import { poids, volume } from '@/Utils/nombre'

const RecentPRsChart = defineAsyncComponent(() => import('@/Components/Stats/RecentPRsChart.vue'))

defineProps({
    recentPRs: { type: Array, required: true },
})
</script>

<template>
    <!-- Recent PRs -->
    <section
        v-if="recentPRs.length > 0"
        class="stagger-6 animate-slide-up border-surface-card/20 bg-surface-card/10 hover:bg-surface-card/20 relative overflow-hidden rounded-3xl border p-6 backdrop-blur-md transition duration-300 hover:-translate-y-1 hover:shadow-xl active:scale-95"
    >
        <div class="relative z-10 mb-6">
            <h3 class="sur-titre text-accent-primary-deep mb-1">Réussites</h3>
            <p class="font-display text-text-main text-2xl font-black uppercase italic">Records Personnels</p>
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
                            class="from-accent-warning to-accent-primary flex size-12 items-center justify-center rounded-xl bg-linear-to-br shadow-lg"
                        >
                            <GlassIcon name="star" class="text-text-on-accent" />
                        </div>
                        <div>
                            <div class="text-text-main font-bold">{{ pr.exercise?.name }}</div>
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
                        <div class="font-display text-accent-primary-deep text-2xl font-black">
                            {{ pr.type === 'max_volume_set' ? volume(pr.value) : poids(pr.value) }}
                        </div>
                    </div>
                </div>
            </GlassCard>
        </div>
    </section>
</template>
