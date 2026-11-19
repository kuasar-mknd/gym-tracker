<script setup>
import { computed } from 'vue'
import { graphiqueAsynchrone } from '@/Utils/graphiqueAsynchrone'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { nombre } from '@/Utils/nombre'
import { variation } from '@/Utils/nombre'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'

const WeeklyVolumeChart = graphiqueAsynchrone(() => import('@/Components/Stats/WeeklyVolumeChart.vue'))

const props = defineProps({
    weeklyVolumeStats: { type: Object, required: true },
    weeklyVolumeTrend: { type: Array, required: true },
})

/**
 * La variation a afficher, ou null s'il n'y en a pas.
 *
 * `undefined` et `null` sont ramenes au meme cas : le premier arrive quand la
 * prop n'a pas la cle du tout, le second quand le serveur dit explicitement
 * qu'il n'y a rien a comparer. Les distinguer ici ne servirait a rien — dans les
 * deux cas il n'y a pas de comparaison a montrer.
 */
const comparison = computed(() => props.weeklyVolumeStats?.percentage ?? null)
</script>

<template>
    <section class="stagger-3 animate-slide-up carte-plate rounded-2xl p-6">
        <div class="relative z-10 mb-6 flex items-start justify-between">
            <div>
                <p class="text-text-muted etiquette mb-1">Aperçu</p>
                <p class="titre-plat">Volume hebdo</p>
            </div>
            <div class="text-right">
                <p class="font-display text-text-main text-4xl font-semibold tracking-tight tabular-nums">
                    {{ nombre(weeklyVolumeStats?.current_week_volume ?? 0, 0) }}
                </p>
                <!--
                    Trois situations, et non deux.

                    La condition n'ecartait que la valeur 0, donc une comparaison
                    absente — `undefined` — passait le test et la ligne s'affichait
                    avec un pourcentage vide. Et une variation nulle, elle, etait
                    cachee alors qu'elle a un sens : le volume n'a pas bouge.

                    Les deux cas etaient inverses. Le serveur envoie desormais null
                    quand il n'y a rien a comparer, ce qui les separe (#1388).
                -->
                <p
                    v-if="comparison !== null"
                    :class="[
                        'mt-1 flex items-center justify-end gap-1 text-xs font-medium tabular-nums',
                        comparison > 0 ? 'text-trend-up' : comparison < 0 ? 'text-trend-down' : 'text-text-muted',
                    ]"
                >
                    <GlassIcon
                        :name="comparison > 0 ? 'trending_up' : comparison < 0 ? 'trending_down' : 'trending_flat'"
                        size="xs"
                    />
                    <template v-if="comparison === 0">Stable vs semaine dernière</template>
                    <template v-else>{{ variation(comparison, '%') }} vs semaine dernière</template>
                </p>
            </div>
        </div>

        <!-- Weekly Volume Chart -->
        <div class="relative -mx-2 mt-2 h-48 w-auto">
            <WeeklyVolumeChart v-if="weeklyVolumeTrend && weeklyVolumeTrend.length > 0" :data="weeklyVolumeTrend" />
            <GlassEmptyState v-else taille="ligne" icon="bar_chart" title="Pas de données cette semaine" />
        </div>
    </section>
</template>
