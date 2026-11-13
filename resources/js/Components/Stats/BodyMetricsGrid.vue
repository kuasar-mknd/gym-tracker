<script setup>
import { computed } from 'vue'
import { Deferred } from '@inertiajs/vue3'
import IndisponibleHorsLigne from '@/Components/UI/IndisponibleHorsLigne.vue'
import { graphiqueAsynchrone } from '@/Utils/graphiqueAsynchrone'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'
import { nombre, variation } from '@/Utils/nombre'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'

const BodyFatChart = graphiqueAsynchrone(() => import('@/Components/Stats/BodyFatChart.vue'))

const props = defineProps({
    bodyFat: Number,
    bodyFatHistory: Array,
    monthlyComparison: Object,
    deferredData: Object,
})

/** Sans volume le mois précédent, il n'y a rien à comparer : la pastille ne dirait que « 0 % ». */
const comparaisonPossible = computed(() => (props.monthlyComparison?.previous_volume ?? 0) > 0)

const variationDuMois = computed(() => props.monthlyComparison?.percentage ?? 0)
</script>

<template>
    <!-- Body Metrics Grid -->
    <div class="stagger-2 animate-slide-up grid grid-cols-2 gap-4">
        <!-- Body Fat -->
        <GlassCard padding="p-5">
            <div class="flex items-start justify-between">
                <div>
                    <h4 class="sur-titre text-accent-secondary-deep mb-1">Masse Grasse</h4>
                    <p class="font-display text-text-main text-3xl font-black">
                        {{ bodyFat ? nombre(bodyFat) : '—' }}
                        <span class="text-text-muted text-sm">%</span>
                    </p>
                </div>
                <div class="bg-category-core/10 flex size-12 items-center justify-center rounded-xl">
                    <GlassIcon name="water_drop" class="text-accent-secondary-deep" />
                </div>
            </div>

            <!-- Real Body Fat Chart (Deferred) -->
            <div class="mt-4 h-32 w-full">
                <Deferred :data="deferredData ? 'deferredData' : 'bodyStats'">
                    <template #rescue>
                        <IndisponibleHorsLigne />
                    </template>
                    <template #fallback>
                        <GlassSkeleton height="100%" width="100%" class="rounded-xl" />
                    </template>
                    <BodyFatChart v-if="bodyFatHistory?.length > 0" :data="bodyFatHistory" />
                    <GlassEmptyState v-else taille="ligne" icon="query_stats" title="Pas de données historiques" />
                </Deferred>
            </div>
        </GlassCard>

        <!-- This Month Volume -->
        <GlassCard padding="p-5">
            <Deferred :data="deferredData ? 'deferredData' : 'performanceStats'">
                <template #rescue>
                    <IndisponibleHorsLigne />
                </template>
                <template #fallback>
                    <div class="space-y-4">
                        <GlassSkeleton height="1rem" width="6rem" />
                        <GlassSkeleton height="2.5rem" width="8rem" />
                        <GlassSkeleton height="1.5rem" width="4rem" class="rounded-lg" />
                    </div>
                </template>
                <div class="flex items-start justify-between">
                    <div>
                        <h4 class="sur-titre text-accent-tertiary-deep mb-1">Volume Mois</h4>
                        <p class="font-display text-text-main text-3xl font-black">
                            {{ nombre(monthlyComparison?.current_volume ?? 0, 0) }}
                            <span class="text-text-muted text-sm">kg</span>
                        </p>
                    </div>
                    <div
                        v-if="comparaisonPossible"
                        :class="[
                            'flex shrink-0 items-center gap-0.5 rounded-lg px-2 py-1 text-xs font-bold whitespace-nowrap',
                            variationDuMois > 0
                                ? 'bg-trend-up/10 text-trend-up'
                                : variationDuMois < 0
                                  ? 'bg-trend-down/10 text-trend-down'
                                  : 'bg-surface-sunken text-text-muted',
                        ]"
                    >
                        <GlassIcon
                            v-if="variationDuMois !== 0"
                            :name="variationDuMois > 0 ? 'trending_up' : 'trending_down'"
                            size="xs"
                        />
                        {{ variation(variationDuMois, '%') }}
                    </div>
                </div>
            </Deferred>
        </GlassCard>
    </div>
</template>
