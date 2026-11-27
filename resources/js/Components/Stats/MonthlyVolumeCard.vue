<script setup>
import { computed } from 'vue'
import { Deferred } from '@inertiajs/vue3'
import IndisponibleHorsLigne from '@/Components/UI/IndisponibleHorsLigne.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'
import { graphiqueAsynchrone } from '@/Utils/graphiqueAsynchrone'
import { nombre, variation } from '@/Utils/nombre'

const MonthlyVolumeChart = graphiqueAsynchrone(() => import('@/Components/Stats/MonthlyVolumeChart.vue'))

const props = defineProps({
    monthlyComparison: Object,
    monthlyVolume: Array,
})

/** Le serveur envoie `null` quand le mois précédent est vide : il n'y a rien à comparer. */
const variationDuMois = computed(() => props.monthlyComparison?.percentage ?? null)
</script>

<template>
    <GlassCard padding="p-5">
        <Deferred data="deferredData">
            <template #rescue>
                <IndisponibleHorsLigne />
            </template>
            <template #fallback>
                <div class="space-y-4">
                    <GlassSkeleton height="1rem" width="6rem" />
                    <GlassSkeleton height="2.5rem" width="8rem" />
                    <GlassSkeleton height="12rem" width="100%" class="rounded-xl" />
                </div>
            </template>
            <div class="space-y-4">
                <div class="bureau:pe-0 flex items-start justify-between pe-12">
                    <div>
                        <h4 class="etiquette mb-1">Volume mensuel</h4>
                        <p class="font-display text-text-main text-3xl font-semibold">
                            {{ nombre(monthlyComparison?.current_volume ?? 0, 0) }}
                            <span class="text-text-muted text-sm">kg</span>
                        </p>
                    </div>
                    <div
                        v-if="variationDuMois !== null"
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
                <MonthlyVolumeChart v-if="monthlyVolume?.length > 0" :data="monthlyVolume" />
            </div>
        </Deferred>
    </GlassCard>
</template>
