<script setup>
import { computed } from 'vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import { compte, nombre, variation } from '@/Utils/nombre'

const props = defineProps({
    weeklyVolumeStats: { type: Object, default: () => ({}) },
    weeklyVolumeTrend: { type: Array, default: () => [] },
})

const INITIALES_DES_JOURS = ['L', 'M', 'M', 'J', 'V', 'S', 'D']
const NOMS_DES_JOURS = ['lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi', 'dimanche']

const volumeDeLaSemaine = computed(() => props.weeklyVolumeStats?.current_week_volume ?? 0)
const nombreDeSeances = computed(() => props.weeklyVolumeStats?.sessions_count ?? 0)

/**
 * L'écart à la même date, ou null : sans séance cette semaine, ou sans rien à
 * comparer sur la même durée de la semaine passée, il n'y a pas d'écart à dire.
 */
const ecart = computed(() => {
    const pourcentage = props.weeklyVolumeStats?.percentage ?? null

    return nombreDeSeances.value === 0 ? null : pourcentage
})

const jourCourant = () => {
    const maintenant = new Date()
    const mois = String(maintenant.getMonth() + 1).padStart(2, '0')
    const jour = String(maintenant.getDate()).padStart(2, '0')

    return `${maintenant.getFullYear()}-${mois}-${jour}`
}

const barres = computed(() => {
    const aujourdhui = jourCourant()
    const plusHaut = Math.max(0, ...props.weeklyVolumeTrend.map((point) => point.volume))

    return props.weeklyVolumeTrend.map((point, index) => ({
        cle: point.date,
        initiale: INITIALES_DES_JOURS[index],
        nom: NOMS_DES_JOURS[index],
        volume: point.volume,
        hauteur: plusHaut > 0 && point.volume > 0 ? Math.max((point.volume / plusHaut) * 100, 6) : 0,
        estAujourdhui: point.date === aujourdhui,
    }))
})

const resume = computed(() => barres.value.map((barre) => `${barre.nom} ${nombre(barre.volume, 0)} kg`).join(', '))
</script>

<template>
    <GlassCard class="stagger-3 animate-slide-up" dusk="dashboard-semaine">
        <h3 class="titre-plat">Cette semaine</h3>

        <p class="text-text-main mt-3 text-3xl leading-none font-medium tabular-nums" dusk="dashboard-semaine-volume">
            {{ nombre(volumeDeLaSemaine, 0) }} <span class="text-text-muted text-base font-normal">kg</span>
        </p>

        <p class="text-text-muted mt-2 text-sm tabular-nums" dusk="dashboard-semaine-resume">
            {{ compte(nombreDeSeances, 'séance', 'séances')
            }}<template v-if="ecart !== null">
                ·
                <span
                    dusk="dashboard-semaine-ecart"
                    :class="ecart > 0 ? 'text-trend-up' : ecart < 0 ? 'text-trend-down' : 'text-text-muted'"
                >
                    {{ ecart === 0 ? 'Stable' : variation(ecart, '%') }} à ce jour
                </span></template
            >
        </p>

        <div
            v-if="barres.length > 0"
            class="mt-5 grid grid-cols-7 gap-2"
            role="img"
            :aria-label="`Volume de chaque jour de la semaine : ${resume}`"
        >
            <div v-for="barre in barres" :key="barre.cle" class="flex flex-col items-center gap-1.5" aria-hidden="true">
                <div class="flex h-20 w-full items-end justify-center">
                    <div
                        class="w-full max-w-6 rounded-full"
                        :class="barre.estAujourdhui ? 'bg-text-main' : 'bg-text-main/25'"
                        :style="{ height: barre.hauteur > 0 ? `${barre.hauteur}%` : '4px' }"
                        :data-volume="barre.volume"
                    ></div>
                </div>
                <span
                    class="text-xs tabular-nums"
                    :class="barre.estAujourdhui ? 'text-text-main font-semibold' : 'text-text-muted'"
                    data-jour
                    :data-aujourdhui="barre.estAujourdhui ? 'oui' : null"
                >
                    {{ barre.initiale }}
                </span>
            </div>
        </div>
    </GlassCard>
</template>
