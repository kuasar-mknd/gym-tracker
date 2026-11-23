<script setup>
/** Les records d'une séance, une ligne par exercice : dans la feuille de fin avant l'appui, et dans le bilan après. */
import { poids } from '@/Utils/nombre'

defineProps({
    records: { type: Array, required: true },
    titre: { type: String, default: 'Records' },
    dusk: { type: String, default: 'bilan-de-fin-records' },
})
</script>

<template>
    <div v-if="records.length > 0" class="mb-4" :dusk="dusk">
        <p class="text-text-muted mb-1 px-1 text-sm">{{ titre }}</p>
        <ul class="bg-surface-sunken/60 rounded-2xl p-1">
            <li
                v-for="(record, index) in records"
                :key="`${index}-${record.exercice}`"
                class="flex items-baseline justify-between gap-3 px-3 py-2"
            >
                <span class="text-text-main min-w-0 text-sm break-words">{{ record.exercice }}</span>
                <span class="text-text-main shrink-0 font-medium tabular-nums">{{ poids(record.valeur) }}</span>
            </li>
        </ul>
    </div>
</template>
