<script setup>
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { graphiqueAsynchrone } from '@/Utils/graphiqueAsynchrone'
import { poids, volume } from '@/Utils/nombre'

const RecentPRsChart = graphiqueAsynchrone(() => import('@/Components/Stats/RecentPRsChart.vue'))

defineProps({
    recentPRs: { type: Array, required: true },
})
</script>

<template>
    <!-- Recent PRs -->
    <section v-if="recentPRs.length > 0" class="stagger-6 animate-slide-up carte-plate rounded-2xl p-6">
        <div class="relative z-10 mb-6">
            <p class="text-text-muted etiquette mb-1">Réussites</p>
            <p class="titre-plat">Records personnels</p>
        </div>

        <!-- PR Bar Chart -->
        <div class="relative -mx-2 mt-2 mb-6 h-48 w-auto">
            <RecentPRsChart :data="recentPRs" />
        </div>

        <div class="space-y-3">
            <GlassCard v-for="pr in recentPRs" :key="pr.id" variant="plate" padding="p-4" rounded="rounded-2xl">
                <div class="flex items-center justify-between gap-3">
                    <div class="flex min-w-0 items-center gap-3">
                        <div class="bg-surface-sunken flex size-12 shrink-0 items-center justify-center rounded-xl">
                            <GlassIcon name="star" class="text-accent-primary-deep" />
                        </div>
                        <div class="min-w-0">
                            <div class="text-text-main truncate font-medium" :title="pr.exercise?.name">
                                {{ pr.exercise?.name }}
                            </div>
                            <div class="text-text-muted text-xs">
                                {{
                                    pr.type === 'max_weight'
                                        ? 'Poids max'
                                        : pr.type === 'max_1rm'
                                          ? '1RM estimé'
                                          : 'Volume'
                                }}
                            </div>
                        </div>
                    </div>
                    <div class="shrink-0 text-right">
                        <div class="font-display text-text-main text-2xl font-semibold whitespace-nowrap tabular-nums">
                            {{ pr.type === 'max_volume_set' ? volume(pr.value) : poids(pr.value) }}
                        </div>
                    </div>
                </div>
            </GlassCard>
        </div>
    </section>
</template>
