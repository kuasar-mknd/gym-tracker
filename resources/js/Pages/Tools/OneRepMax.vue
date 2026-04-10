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
                    <GlassCard
                        class="animate-slide-up shadow-2xl"
                        style="animation-delay: 0.05s"
                    >
                        <div class="space-y-6 p-6">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="font-display-label text-text-muted mb-2 block">Poids Soulevé</label>
                                    <div class="relative">
                                        <input
                                            type="number"
                                            v-model="weight"
                                            placeholder="100"
                                            min="0"
                                            step="0.5"
                                            class="font-display text-text-main placeholder-text-muted/50 focus:border-electric-orange focus:ring-electric-orange/20 h-16 w-full rounded-2xl border border-slate-200 bg-white/50 px-4 text-center text-3xl font-black backdrop-blur-sm transition-all outline-none focus:bg-white/80 focus:ring-2 dark:border-slate-700 dark:bg-slate-800/50 dark:focus:bg-slate-800"
                                        />
                                        <span
                                            class="text-text-muted absolute top-1/2 right-4 -translate-y-1/2 font-bold"
                                            >kg</span
                                        >
                                    </div>
                                </div>
                                <div>
                                    <label class="font-display-label text-text-muted mb-2 block">Répétitions</label>
                                    <div class="relative">
                                        <input
                                            type="number"
                                            v-model="reps"
                                            placeholder="5"
                                            min="1"
                                            max="100"
                                            step="1"
                                            class="font-display text-text-main placeholder-text-muted/50 focus:border-electric-orange focus:ring-electric-orange/20 h-16 w-full rounded-2xl border border-slate-200 bg-white/50 px-4 text-center text-3xl font-black backdrop-blur-sm transition-all outline-none focus:bg-white/80 focus:ring-2 dark:border-slate-700 dark:bg-slate-800/50 dark:focus:bg-slate-800"
                                        />
                                        <span
                                            class="text-text-muted absolute top-1/2 right-4 -translate-y-1/2 font-bold"
                                            >reps</span
                                        >
                                    </div>
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

                    <!-- Result Card -->
                    <GlassCard
                        v-if="oneRepMax > 0"
                        class="animate-slide-up mt-6 flex flex-col items-center justify-center p-8 text-center"
                        style="animation-delay: 0.08s"
                    >
                        <p class="text-text-muted text-sm font-bold tracking-wider uppercase">1RM Estimé</p>
                        <div
                            class="from-electric-orange to-hot-pink font-display mt-2 bg-linear-to-r bg-clip-text text-6xl font-black tracking-tighter text-transparent italic"
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
                    <GlassCard
                        class="animate-slide-up h-full shadow-2xl"
                        style="animation-delay: 0.1s"
                    >
                        <div class="p-6">
                            <h2 class="font-display text-text-main mb-4 text-lg font-black uppercase italic">
                                Pourcentages d'Entraînement
                            </h2>

                            <!-- Chart Component -->
                            <div class="mb-6 h-48 w-full">
                                <OneRepMaxPercentagesChart :data="percentages" />
                            </div>

                            <div
                                class="overflow-hidden rounded-3xl border border-slate-200 bg-white/50 shadow-inner dark:border-slate-700 dark:bg-slate-800/30"
                            >
                                <table class="text-text-muted w-full text-left text-sm">
                                    <thead
                                        class="text-text-main border-b border-slate-200 bg-slate-50/80 text-xs uppercase dark:border-slate-700 dark:bg-slate-800/50 dark:text-white"
                                    >
                                        <tr>
                                            <th class="px-6 py-3 font-medium">Pourcentage</th>
                                            <th class="px-6 py-3 font-medium">Poids</th>
                                            <th class="px-6 py-3 font-medium">Reps Est.</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-slate-100 bg-transparent dark:divide-slate-700">
                                        <tr
                                            v-for="p in percentages"
                                            :key="p.percent"
                                            class="transition-colors duration-200 hover:bg-slate-50 dark:hover:bg-slate-800/50"
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
                <GlassCard
                    v-else
                    class="animate-slide-up mt-8 py-12 text-center shadow-2xl"
                >
                    <span class="material-symbols-outlined mb-3 text-5xl text-slate-300">calculate</span>
                    <p class="text-text-muted font-medium">Entre un poids et des répétitions pour voir les résultats</p>
                </GlassCard>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, defineAsyncComponent } from 'vue'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import PageHeader from '@/Components/Navigation/PageHeader.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import InputLabel from '@/Components/Form/InputLabel.vue'

const OneRepMaxPercentagesChart = defineAsyncComponent(() => import('@/Components/Stats/OneRepMaxPercentagesChart.vue'))

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
