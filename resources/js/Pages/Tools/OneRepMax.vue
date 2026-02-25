<template>
    <Head title="Calculatrice 1RM" />

    <AuthenticatedLayout page-title="Calculatrice 1RM" show-back back-route="tools.index">
        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Calculator Input -->
            <div class="space-y-6">
                <GlassCard>
                    <div class="space-y-6 p-6">
                        <h2 class="text-text-main text-xl font-bold">Calculer</h2>

                        <div class="space-y-4">
                            <div class="space-y-2">
                                <InputLabel value="Poids Soulevé" />
                                <GlassInput
                                    type="number"
                                    v-model="weight"
                                    placeholder="ex: 100"
                                    min="0"
                                    step="0.5"
                                    class="w-full"
                                />
                            </div>
                            <div class="space-y-2">
                                <InputLabel value="Répétitions" />
                                <GlassInput
                                    type="number"
                                    v-model="reps"
                                    placeholder="ex: 5"
                                    min="1"
                                    max="100"
                                    step="1"
                                    class="w-full"
                                />
                            </div>
                        </div>

                        <div class="text-text-muted pt-4 text-sm">
                            <p>Utilise la formule d'Epley : <span class="font-mono">w * (1 + r / 30)</span></p>
                            <p v-if="reps > 10" class="mt-2 text-amber-600">
                                Note : les calculs 1RM sont moins précis pour les séries à hautes répétitions (>10
                                reps).
                            </p>
                        </div>
                    </div>
                </GlassCard>

                <!-- Result Card (Mobile/Desktop split) -->
                <GlassCard v-if="oneRepMax > 0" class="border-accent-primary/30 bg-accent-primary/10">
                    <div class="p-6 text-center">
                        <h3 class="text-text-main/80 text-lg font-medium">1RM Estimé</h3>
                        <div class="text-text-main mt-2 text-5xl font-bold">
                            {{ formatWeight(oneRepMax) }}
                        </div>
                        <div class="text-text-muted mt-1 text-sm">Basé sur {{ weight }} x {{ reps }}</div>
                    </div>
                </GlassCard>
            </div>

            <!-- Percentages Table -->
            <div v-if="oneRepMax > 0">
                <GlassCard class="h-full">
                    <div class="p-6">
                        <h2 class="text-text-main mb-4 text-xl font-bold">Pourcentages d'Entraînement</h2>
                        <div class="overflow-hidden rounded-3xl border border-white/20 bg-white/10 backdrop-blur-md">
                            <table class="text-text-muted w-full text-left text-sm">
                                <thead class="text-text-main border-b border-white/20 bg-white/10 text-xs uppercase">
                                    <tr>
                                        <th class="px-6 py-3 font-medium">Pourcentage</th>
                                        <th class="px-6 py-3 font-medium">Poids</th>
                                        <th class="px-6 py-3 font-medium">Reps Est.</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/10 bg-transparent">
                                    <tr
                                        v-for="p in percentages"
                                        :key="p.percent"
                                        class="transition-colors duration-200 hover:bg-white/20"
                                    >
                                        <td class="text-text-main px-6 py-4 font-medium">{{ p.percent }}%</td>
                                        <td class="text-text-main px-6 py-4">{{ formatWeight(p.value) }}</td>
                                        <td class="px-6 py-4">{{ p.reps }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </GlassCard>
            </div>
            <div
                v-else
                class="text-text-muted flex items-center justify-center rounded-3xl border border-dashed border-white/20 bg-white/10 p-12 backdrop-blur-sm"
            >
                Entre un poids et des répétitions pour voir les résultats
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import PageHeader from '@/Components/Navigation/PageHeader.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import InputLabel from '@/Components/InputLabel.vue'

const weight = ref('')
const reps = ref('')

const oneRepMax = computed(() => {
    const w = parseFloat(weight.value)
    const r = parseFloat(reps.value)

    if (!w || !r || w <= 0 || r <= 0) return 0

    if (r === 1) return w

    // Epley Formula
    return w * (1 + r / 30)
})

const percentages = computed(() => {
    const max = oneRepMax.value
    if (!max) return []

    const percents = [100, 95, 90, 85, 80, 75, 70, 65, 60, 55, 50]

    // Invert Brzycki to estimate reps for a given %: Reps = 37 - (36 * %1RM / 100)?
    // Or just lookup table.
    // Commonly: 100% = 1, 95% = 2, 93% = 3, 90% = 4, 87% = 5, 85% = 6, 83% = 7, 80% = 8, 77% = 9, 75% = 10, 70% = 12, 65% = 15, 60% = 20
    // Let's use a rough mapping for "Est. Reps" column.

    const repMap = {
        100: '1',
        95: '2',
        90: '4',
        85: '6',
        80: '8',
        75: '10',
        70: '12',
        65: '15',
        60: '20',
        55: '25+',
        50: '30+',
    }

    return percents.map((p) => ({
        percent: p,
        value: max * (p / 100),
        reps: repMap[p] || '-',
    }))
})

const formatWeight = (val) => {
    // Round to nearest 0.5 or 1? usually 1RM is kept somewhat precise or rounded to nearest plate fraction.
    // Let's keep 1 decimal if needed, but remove .0
    return parseFloat(val.toFixed(1)).toString()
}
</script>
