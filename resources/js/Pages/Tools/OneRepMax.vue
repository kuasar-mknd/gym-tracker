<template>
    <Head title="Calculatrice 1RM" />

    <AuthenticatedLayout page-title="Calculatrice 1RM" show-back back-route="tools.index">
        <div class="space-y-6">
            <!-- Header -->
            <header class="animate-fade-in">
                <h1
                    class="font-display text-text-main text-4xl leading-none font-black tracking-tighter uppercase italic"
                >
                    Calculatrice<br />
                    <span class="text-gradient">1RM</span>
                </h1>
                <p class="text-text-muted mt-2 text-sm font-semibold tracking-wider uppercase">
                    Estime ton maximum sur une répétition
                </p>
            </header>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Calculator Input -->
                <div class="space-y-6">
                    <GlassCard class="animate-slide-up shadow-2xl" style="animation-delay: 0.05s">
                        <div class="space-y-6 p-6">
                            <div class="grid grid-cols-2 gap-4">
                                <GlassBigNumber
                                    id="orm-weight"
                                    v-model="weight"
                                    label="Poids Soulevé"
                                    unite="kg"
                                    placeholder="100"
                                    min="0"
                                    step="0.5"
                                />
                                <GlassBigNumber
                                    id="orm-reps"
                                    v-model="reps"
                                    label="Répétitions"
                                    unite="réps"
                                    placeholder="5"
                                    min="1"
                                    max="100"
                                    step="1"
                                />
                            </div>

                            <div class="text-text-muted pt-4 text-sm">
                                <p>Utilise la formule d'Epley : <span class="font-mono">w * (1 + r / 30)</span></p>
                                <p v-if="reps > 10" class="text-accent-warning-deep mt-2">
                                    Note : les calculs 1RM sont moins précis pour les séries à hautes répétitions (>10
                                    reps).
                                </p>
                            </div>
                        </div>
                    </GlassCard>

                    <!-- Result Card -->
                    <GlassCard
                        v-if="oneRepMax > 0"
                        class="animate-slide-up mt-6 flex flex-col items-center justify-center p-8 text-center"
                        style="animation-delay: 0.08s"
                    >
                        <p class="text-text-muted text-sm font-bold tracking-wider uppercase">1RM Estimé</p>
                        <div
                            class="from-accent-primary to-accent-secondary font-display mt-2 bg-linear-to-r bg-clip-text text-6xl font-black tracking-tighter text-transparent italic"
                        >
                            {{ formatWeight(oneRepMax) }}
                        </div>
                        <div class="text-text-muted mt-2 text-sm font-semibold tracking-wider uppercase">
                            Basé sur {{ weight }} x {{ reps }}
                        </div>
                    </GlassCard>
                </div>

                <!-- Percentages Table -->
                <div v-if="oneRepMax > 0">
                    <GlassCard class="animate-slide-up h-full shadow-2xl" style="animation-delay: 0.1s">
                        <div class="p-6">
                            <h2 class="font-display text-text-main mb-4 text-lg font-black uppercase italic">
                                Pourcentages d'Entraînement
                            </h2>

                            <!-- Chart Component -->
                            <div class="mb-6 h-48 w-full">
                                <OneRepMaxPercentagesChart :data="percentages" />
                            </div>

                            <div
                                class="border-border bg-surface-card/50 overflow-hidden rounded-3xl border shadow-inner"
                            >
                                <table class="text-text-muted w-full text-left text-sm">
                                    <thead
                                        class="text-text-main border-border bg-surface-sunken/80 border-b text-xs uppercase"
                                    >
                                        <tr>
                                            <th class="px-6 py-3 font-medium">Pourcentage</th>
                                            <th class="px-6 py-3 font-medium">Poids</th>
                                            <th class="px-6 py-3 font-medium">Reps Est.</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-border divide-y bg-transparent">
                                        <tr
                                            v-for="p in percentages"
                                            :key="p.percent"
                                            class="hover:bg-surface-sunken transition-colors duration-200"
                                        >
                                            <td class="text-text-main px-6 py-4 font-medium">{{ p.percent }}%</td>
                                            <td class="text-text-main px-6 py-4">{{ formatWeight(p.value) }} kg</td>
                                            <td class="px-6 py-4">{{ p.reps }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </GlassCard>
                </div>
                <GlassCard v-else class="animate-slide-up mt-8 py-12 text-center shadow-2xl">
                    <span class="material-symbols-outlined text-text-muted mb-3 text-5xl" aria-hidden="true"
                        >calculate</span
                    >
                    <p class="text-text-muted font-medium">Entre un poids et des répétitions pour voir les résultats</p>
                </GlassCard>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, defineAsyncComponent } from 'vue'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassBigNumber from '@/Components/UI/GlassBigNumber.vue'
import { oneRepMax as epley } from '@/Utils/formulas'

const OneRepMaxPercentagesChart = defineAsyncComponent(() => import('@/Components/Stats/OneRepMaxPercentagesChart.vue'))

const weight = ref('')
const reps = ref('')

const oneRepMax = computed(() => epley(weight.value, reps.value))

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
