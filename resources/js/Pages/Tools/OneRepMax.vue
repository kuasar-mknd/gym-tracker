<template>
    <AuthenticatedLayout>
        <template #header>
            <PageHeader title="1RM Calculator" :show-back="true" :back-route="route('tools.index')" />
        </template>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Calculator Input -->
            <div class="space-y-6">
                <GlassCard>
                    <div class="space-y-6 p-6">
                        <h2 class="text-xl font-bold text-white">Calculate</h2>

                        <div class="space-y-4">
                            <div class="space-y-2">
                                <InputLabel value="Lifted Weight" />
                                <GlassInput
                                    type="number"
                                    v-model="weight"
                                    placeholder="e.g. 100"
                                    min="0"
                                    step="0.5"
                                    class="w-full"
                                />
                            </div>
                            <div class="space-y-2">
                                <InputLabel value="Repetitions" />
                                <GlassInput
                                    type="number"
                                    v-model="reps"
                                    placeholder="e.g. 5"
                                    min="1"
                                    max="100"
                                    step="1"
                                    class="w-full"
                                />
                            </div>
                        </div>

                        <div class="pt-4 text-sm text-white/50">
                            <p>Uses the Epley formula: <span class="font-mono">w * (1 + r / 30)</span></p>
                            <p v-if="reps > 10" class="mt-2 text-yellow-400">
                                Note: 1RM calculations are less accurate for high repetition sets (>10 reps).
                            </p>
                        </div>
                    </div>
                </GlassCard>

                <!-- Result Card (Mobile/Desktop split) -->
                <GlassCard v-if="oneRepMax > 0" class="border-accent-primary/30 bg-accent-primary/10">
                    <div class="p-6 text-center">
                        <h3 class="text-lg font-medium text-white/80">Estimated One Rep Max</h3>
                        <div class="mt-2 text-5xl font-bold text-white">
                            {{ formatWeight(oneRepMax) }}
                        </div>
                        <div class="mt-1 text-sm text-white/50">Based on {{ weight }} x {{ reps }}</div>
                    </div>
                </GlassCard>
            </div>

            <!-- Percentages Table -->
            <div v-if="oneRepMax > 0">
                <GlassCard class="h-full">
                    <div class="p-6">
                        <h2 class="mb-4 text-xl font-bold text-white">Training Percentages</h2>
                        <div class="overflow-hidden rounded-xl border border-white/10">
                            <table class="w-full text-left text-sm text-white/70">
                                <thead class="bg-white/10 text-xs uppercase text-white">
                                    <tr>
                                        <th class="px-6 py-3 font-medium">Percentage</th>
                                        <th class="px-6 py-3 font-medium">Weight</th>
                                        <th class="px-6 py-3 font-medium">Est. Reps</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-white/5 bg-white/5">
                                    <tr v-for="p in percentages" :key="p.percent" class="hover:bg-white/5">
                                        <td class="px-6 py-4 font-medium text-white">{{ p.percent }}%</td>
                                        <td class="px-6 py-4 text-white">{{ formatWeight(p.value) }}</td>
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
                class="flex items-center justify-center rounded-xl border border-dashed border-white/20 p-12 text-white/30"
            >
                Enter weight and reps to see results
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
