<script setup>
import { computed, defineAsyncComponent } from 'vue'

const WeeklyVolumeChart = defineAsyncComponent(() => import('@/Components/Stats/WeeklyVolumeChart.vue'))

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
    <section
        class="animate-slide-up border-surface-card/20 bg-surface-card/10 hover:bg-surface-card/20 relative overflow-hidden rounded-3xl border p-6 backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:shadow-xl active:scale-95"
        style="animation-delay: 0.15s"
    >
        <div class="relative z-10 mb-6 flex items-start justify-between">
            <div>
                <h3 class="text-accent-primary-deep mb-1 text-[10px] font-black tracking-[0.2em] uppercase">Aperçu</h3>
                <p class="font-display text-text-main text-2xl font-black uppercase italic">Volume Hebdo</p>
            </div>
            <div class="text-right">
                <p
                    class="from-accent-primary to-accent-tertiary font-display bg-linear-to-r bg-clip-text text-4xl font-black tracking-tighter text-transparent"
                >
                    {{ weeklyVolumeStats?.current_week_volume?.toLocaleString() || 0 }}
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
                        'mt-1 flex items-center justify-end gap-1 text-xs font-bold tracking-wide uppercase',
                        comparison > 0 ? 'text-trend-up' : comparison < 0 ? 'text-trend-down' : 'text-text-muted',
                    ]"
                >
                    <span class="material-symbols-outlined text-sm font-bold" aria-hidden="true">
                        {{ comparison > 0 ? 'trending_up' : comparison < 0 ? 'trending_down' : 'trending_flat' }}
                    </span>
                    <template v-if="comparison === 0">Stable vs sem. passée</template>
                    <template v-else>{{ comparison > 0 ? '+' : '' }}{{ comparison }}% vs sem. passée</template>
                </p>
            </div>
        </div>

        <!-- Weekly Volume Chart -->
        <div class="relative -mx-2 mt-2 h-48 w-auto">
            <WeeklyVolumeChart v-if="weeklyVolumeTrend && weeklyVolumeTrend.length > 0" :data="weeklyVolumeTrend" />
            <div v-else class="text-text-muted flex h-full items-center justify-center">
                <p class="text-sm">Pas de données cette semaine</p>
            </div>
        </div>
    </section>
</template>
