<script setup>
import { computed } from 'vue'
import VolumeTrendChart from '@/Components/Stats/VolumeTrendChart.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'

const props = defineProps({
    history: {
        type: Array,
        required: true,
    },
})

const chartData = computed(() => {
    // History comes newest first (descending), we need oldest first (ascending) for the chart
    const chronologicalHistory = [...props.history].reverse()

    return chronologicalHistory.map((session) => {
        const volume = session.sets.reduce((total, set) => {
            const weight = parseFloat(set.weight) || 0
            const reps = parseFloat(set.reps) || 0
            return total + weight * reps
        }, 0)

        // formatted_date is 'dd/mm/yyyy'. We want 'dd/mm' for cleaner chart labels.
        const dateLabel = session.formatted_date ? session.formatted_date.slice(0, 5) : '??/??'

        return {
            date: dateLabel,
            volume: Math.round(volume),
        }
    })
})
</script>

<template>
    <GlassCard class="animate-slide-up">
        <div class="mb-4">
            <h3 class="font-display text-text-main text-lg font-black uppercase italic">Volume par Séance</h3>
            <p class="text-text-muted text-xs font-semibold">Total (Poids × Reps) par entraînement</p>
        </div>

        <div v-if="history.length > 0" class="h-64">
            <VolumeTrendChart :data="chartData" />
        </div>

        <div v-else class="flex h-64 flex-col items-center justify-center text-center">
            <span class="material-symbols-outlined text-text-muted/30 mb-2 text-5xl">bar_chart</span>
            <p class="text-text-muted text-sm">Pas assez de données pour afficher le graphique</p>
        </div>
    </GlassCard>
</template>
