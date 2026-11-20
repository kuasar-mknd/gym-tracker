<script setup>
import { Deferred } from '@inertiajs/vue3'
import IndisponibleHorsLigne from '@/Components/UI/IndisponibleHorsLigne.vue'
import { graphiqueAsynchrone } from '@/Utils/graphiqueAsynchrone'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'
import { nombre } from '@/Utils/nombre'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'

const BodyFatChart = graphiqueAsynchrone(() => import('@/Components/Stats/BodyFatChart.vue'))

defineProps({
    bodyFat: Number,
    bodyFatHistory: Array,
})
</script>

<template>
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

        <div class="mt-4 h-32 w-full">
            <Deferred data="bodyStats">
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
</template>
