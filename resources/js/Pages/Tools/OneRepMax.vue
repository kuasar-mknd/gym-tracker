<template>
    <Head title="Calculatrice 1RM" />

    <AuthenticatedLayout show-back back-route="tools.index" largeur="etroite">
        <div class="space-y-6">
            <!-- Header -->
            <header class="animate-fade-in">
                <h1 class="titre-ecran">Calculatrice 1RM</h1>
                <p class="etiquette mt-2">Estime ton maximum sur une répétition</p>
            </header>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <!-- Calculator Input -->
                <div class="space-y-6">
                    <GlassCard class="stagger-1 animate-slide-up">
                        <div class="space-y-6 p-6">
                            <div class="grid grid-cols-2 gap-4">
                                <GlassBigNumber
                                    id="orm-weight"
                                    v-model="weight"
                                    label="Poids soulevé"
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
                        class="stagger-2 animate-slide-up mt-6 flex flex-col items-center justify-center p-8 text-center"
                    >
                        <p class="etiquette">1RM estimé</p>
                        <div class="font-display text-text-main mt-2 text-6xl font-semibold tabular-nums">
                            {{ nombre(oneRepMax) }}
                        </div>
                        <div class="etiquette mt-2">Basé sur {{ poids(weight) }} × {{ entier(reps) }}</div>
                    </GlassCard>
                </div>

                <!-- Percentages Table -->
                <div v-if="oneRepMax > 0">
                    <GlassCard class="stagger-2 animate-slide-up h-full">
                        <div class="p-6">
                            <h2 class="titre-plat mb-4">Pourcentages d'entraînement</h2>

                            <!-- Chart Component -->
                            <div class="mb-6 h-48 w-full">
                                <OneRepMaxPercentagesChart :data="percentages" />
                            </div>

                            <div
                                class="border-border bg-surface-card/50 overflow-x-auto rounded-3xl border"
                                tabindex="0"
                                role="region"
                                aria-label="Pourcentages et poids"
                            >
                                <table class="text-text-muted w-full text-left text-sm">
                                    <thead class="text-text-main border-border bg-surface-sunken/80 border-b text-xs">
                                        <tr>
                                            <th class="px-3 py-3 font-medium sm:px-6">Pourcentage</th>
                                            <th class="px-3 py-3 font-medium sm:px-6">Poids</th>
                                            <th class="px-3 py-3 font-medium sm:px-6">Reps est.</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-border divide-y bg-transparent">
                                        <tr
                                            v-for="p in percentages"
                                            :key="p.percent"
                                            class="hover:bg-surface-sunken transition-colors duration-200"
                                        >
                                            <td class="text-text-main px-3 py-4 font-medium sm:px-6">
                                                {{ pourcentage(p.percent, 0) }}
                                            </td>
                                            <td class="text-text-main px-3 py-4 sm:px-6">{{ poids(p.value) }}</td>
                                            <td class="px-3 py-4 sm:px-6">{{ p.reps }}</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </GlassCard>
                </div>
                <GlassCard v-else class="animate-slide-up mt-8 py-12 text-center">
                    <GlassIcon name="calculate" size="2xl" class="text-text-muted mb-3" />
                    <p class="text-text-muted">Entre un poids et des répétitions pour voir les résultats</p>
                </GlassCard>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { graphiqueAsynchrone } from '@/Utils/graphiqueAsynchrone'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import GlassBigNumber from '@/Components/UI/GlassBigNumber.vue'
import { oneRepMax as epley } from '@/Utils/formulas'
import { entier, nombre, poids, pourcentage } from '@/Utils/nombre'

const OneRepMaxPercentagesChart = graphiqueAsynchrone(() => import('@/Components/Stats/OneRepMaxPercentagesChart.vue'))

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
</script>
