<script setup>
import GlassCard from '@/Components/UI/GlassCard.vue'
import { entier, poids, volume } from '@/Utils/nombre'

defineProps({
    recentPRs: { type: Array, required: true },
})

const TYPES = {
    max_weight: 'Poids max',
    max_1rm: '1RM estimé',
    max_volume_set: 'Volume',
}

const jourDuRecord = (record) =>
    new Date(record.achieved_at).toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' })

/** La charge d'un record : « 80 kg × 5 » pour le poids max, la valeur seule pour les deux autres. */
const chargeDuRecord = (record) => {
    if (record.type === 'max_volume_set') {
        return volume(record.value)
    }

    if (record.type === 'max_weight' && Number(record.secondary_value) > 0) {
        return `${poids(record.value)} × ${entier(record.secondary_value)}`
    }

    return poids(record.value)
}
</script>

<template>
    <section v-if="recentPRs.length > 0" class="stagger-6 animate-slide-up" dusk="dashboard-records">
        <h3 class="titre-plat mb-4 px-1">Records récents</h3>

        <div class="space-y-3">
            <GlassCard v-for="record in recentPRs.slice(0, 2)" :key="record.id" variant="fin" padding="p-4">
                <div class="flex items-center justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-text-main truncate font-medium" :title="record.exercise?.name">
                            {{ record.exercise?.name }}
                        </div>
                        <div class="text-text-muted text-xs tabular-nums">
                            {{ jourDuRecord(record) }} · {{ TYPES[record.type] ?? 'Record' }}
                        </div>
                    </div>
                    <div class="text-text-main shrink-0 text-lg font-medium whitespace-nowrap tabular-nums">
                        {{ chargeDuRecord(record) }}
                    </div>
                </div>
            </GlassCard>
        </div>
    </section>
</template>
