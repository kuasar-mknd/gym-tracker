<script setup>
import { Deferred } from '@inertiajs/vue3'
import { defineAsyncComponent } from 'vue'

const WeeklyVolumeChart = defineAsyncComponent(() => import('@/Components/Stats/WeeklyVolumeChart.vue'))

defineProps({
    weeklyVolumeStats: { type: Object, required: true },
    weeklyVolumeTrend: { type: Array, required: true },
})
</script>

<template>
    <section
        class="animate-slide-up relative overflow-hidden rounded-3xl border border-white/20 bg-white/10 p-6 backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:bg-white/20 hover:shadow-xl active:scale-95"
        style="animation-delay: 0.15s"
    >
        <div class="relative z-10 mb-6 flex items-start justify-between">
            <div>
                <h3 class="text-electric-orange mb-1 text-[10px] font-black tracking-[0.2em] uppercase">Aperçu</h3>
                <p class="font-display text-text-main text-2xl font-black uppercase italic dark:text-white">
                    Volume Hebdo
                </p>
            </div>
            <div class="text-right">
                <p
                    class="from-electric-orange to-vivid-violet font-display bg-linear-to-r bg-clip-text text-4xl font-black tracking-tighter text-transparent"
                >
                    {{ weeklyVolumeStats?.current_week_volume?.toLocaleString() || 0 }}
                </p>
                <p
                    v-if="weeklyVolumeStats?.percentage !== 0"
                    :class="[
                        'mt-1 flex items-center justify-end gap-1 text-xs font-bold tracking-wide uppercase',
                        weeklyVolumeStats?.percentage > 0 ? 'text-emerald-600' : 'text-red-500',
                    ]"
                >
                    <span class="material-symbols-outlined text-sm font-bold">
                        {{ weeklyVolumeStats?.percentage > 0 ? 'trending_up' : 'trending_down' }}
                    </span>
                    {{ weeklyVolumeStats?.percentage > 0 ? '+' : '' }}{{ weeklyVolumeStats?.percentage }}% vs sem.
                    passée
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
