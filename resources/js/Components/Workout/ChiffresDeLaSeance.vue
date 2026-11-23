<script setup>
/**
 * Les trois chiffres d'une séance, les mêmes avant l'enregistrement (la feuille
 * de fin) et après (le bilan) : durée, volume des séries validées, séries
 * validées. Ils viennent de la copie locale, avec ou sans réseau.
 */
import { computed } from 'vue'
import { seriesValidees, volumeDesSeriesValidees } from '@/Utils/seriesValidees'
import { entier } from '@/Utils/nombre'

const props = defineProps({
    /** La séance : son début et ses séries. */
    seance: { type: Object, default: null },
    /** La fin de la durée, en date ISO ou en `Date` ; sans elle, la fin de la séance. */
    fin: { type: [String, Date, Number], default: null },
})

const resume = computed(() => {
    const series = (props.seance?.workout_lines ?? []).flatMap((ligne) => ligne.sets ?? [])
    const minutes = Math.max(
        0,
        Math.round((new Date(props.fin ?? props.seance?.ended_at) - new Date(props.seance?.started_at)) / 60000),
    )

    return {
        duree: isNaN(minutes)
            ? '—'
            : minutes >= 60
              ? `${entier(Math.floor(minutes / 60))} h ${String(minutes % 60).padStart(2, '0')}`
              : `${entier(minutes)} min`,
        volume: `${entier(volumeDesSeriesValidees(series))} kg`,
        series: entier(seriesValidees(series).length),
    }
})
</script>

<template>
    <dl class="mb-4 grid grid-cols-3 gap-2" dusk="finish-workout-bilan">
        <div>
            <dt class="text-text-muted text-xs">Durée</dt>
            <dd class="text-text-main text-xl font-medium whitespace-nowrap tabular-nums" dusk="finish-workout-duree">
                {{ resume.duree }}
            </dd>
        </div>
        <div>
            <dt class="text-text-muted text-xs">Volume</dt>
            <dd class="text-text-main text-xl font-medium whitespace-nowrap tabular-nums" dusk="finish-workout-volume">
                {{ resume.volume }}
            </dd>
        </div>
        <div>
            <dt class="text-text-muted text-xs">Séries</dt>
            <dd class="text-text-main text-xl font-medium whitespace-nowrap tabular-nums" dusk="finish-workout-series">
                {{ resume.series }}
            </dd>
        </div>
    </dl>
</template>
