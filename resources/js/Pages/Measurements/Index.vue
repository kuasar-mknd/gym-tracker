<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { computed, ref, defineAsyncComponent } from 'vue'

const WeightHistoryChart = defineAsyncComponent(() => import('@/Components/Stats/WeightHistoryChart.vue'))

const props = defineProps({
    measurements: Array,
    weightHistory: Array,
})

const showAddForm = ref(false)

const form = useForm({
    weight: '',
    body_fat: '',
    measured_at: new Date().toISOString().substr(0, 10),
    notes: '',
})

const submit = () => {
    form.post(route('body-measurements.store'), {
        onSuccess: () => {
            form.reset('weight', 'body_fat', 'notes')
            showAddForm.value = false
        },
    })
}

const deleteMeasurement = (id) => {
    if (confirm('Supprimer cette entrée ?')) {
        useForm({}).delete(route('body-measurements.destroy', { body_measurement: id }))
    }
}

const latestWeight = computed(() => {
    if (props.measurements.length === 0) return null
    return props.measurements[props.measurements.length - 1].weight
})

const previousWeight = computed(() => {
    if (props.measurements.length < 2) return null
    return props.measurements[props.measurements.length - 2].weight
})

const weightDiff = computed(() => {
    if (!latestWeight.value || !previousWeight.value) return null
    return (latestWeight.value - previousWeight.value).toFixed(1)
})

const latestBodyFat = computed(() => {
    if (props.measurements.length === 0) return null
    const latest = [...props.measurements].reverse().find((m) => m.body_fat !== null)
    return latest ? latest.body_fat : null
})

const bodyFatDiff = computed(() => {
    const measurementsWithFat = props.measurements.filter((m) => m.body_fat !== null)
    if (measurementsWithFat.length < 2) return null
    const latest = measurementsWithFat[measurementsWithFat.length - 1].body_fat
    const previous = measurementsWithFat[measurementsWithFat.length - 2].body_fat
    return (latest - previous).toFixed(1)
})

const chartData = computed(() => {
    const sorted = [...props.measurements].sort((a, b) => new Date(a.measured_at) - new Date(b.measured_at))
    return {
        labels: sorted.map((m) =>
            new Date(m.measured_at + 'T00:00:00').toLocaleDateString('fr-FR', { day: 'numeric', month: 'short' }),
        ),
        datasets: [
            {
                label: 'Poids',
                backgroundColor: 'rgba(129, 140, 248, 0.1)',
                borderColor: '#818cf8',
                pointBackgroundColor: '#818cf8',
                pointBorderColor: '#818cf8',
                pointRadius: 4,
                pointHoverRadius: 6,
                data: sorted.map((m) => m.weight),
                tension: 0.4,
                fill: true,
            },
        ],
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: { display: false },
        tooltip: {
            backgroundColor: 'rgba(255, 255, 255, 0.95)',
            titleColor: '#0F172A',
            bodyColor: '#0F172A',
            borderColor: 'rgba(0, 0, 0, 0.1)',
            borderWidth: 1,
            cornerRadius: 12,
            padding: 12,
        },
    },
    scales: {
        x: {
            ticks: { color: '#64748B', font: { size: 10, weight: 'bold' } },
            grid: { display: false },
            border: { display: false },
        },
        y: {
            ticks: { color: '#64748B', font: { size: 10, weight: 'bold' } },
            grid: { color: 'rgba(0, 0, 0, 0.05)' },
            border: { display: false },
        },
    },
}
</script>

<template>
    <Head title="Mesures" />

    <AuthenticatedLayout page-title="Mesures">
        <template #header-actions>
            <GlassButton size="sm" @click="showAddForm = !showAddForm" aria-label="Ajouter une mesure">
                <svg
                    class="h-4 w-4"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    aria-hidden="true"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
            </GlassButton>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-text-main text-xl font-semibold">Mesures</h2>
                <GlassButton @click="showAddForm = !showAddForm">
                    <svg
                        class="mr-2 h-4 w-4"
                        xmlns="http://www.w3.org/2000/svg"
                        fill="none"
                        viewBox="0 0 24 24"
                        stroke="currentColor"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                    </svg>
                    Ajouter
                </GlassButton>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Quick Stats -->
            <div class="animate-slide-up grid grid-cols-1 gap-3 sm:grid-cols-3">
                <GlassCard padding="p-4">
                    <div class="text-center">
                        <div class="text-gradient text-3xl font-bold">
                            {{ latestWeight ? `${latestWeight}` : '—' }}
                        </div>
                        <div class="text-text-muted mt-1 text-sm font-semibold">kg actuel</div>
                    </div>
                </GlassCard>
                <GlassCard padding="p-4">
                    <div class="text-center">
                        <div
                            :class="[
                                'text-3xl font-bold',
                                weightDiff > 0
                                    ? 'text-accent-warning'
                                    : weightDiff < 0
                                      ? 'text-accent-success'
                                      : 'text-text-muted',
                            ]"
                        >
                            {{ weightDiff ? `${weightDiff > 0 ? '+' : ''}${weightDiff}` : '—' }}
                        </div>
                        <div class="text-text-muted mt-1 text-sm font-semibold">kg évolution</div>
                    </div>
                </GlassCard>
                <GlassCard padding="p-4">
                    <div class="text-center">
                        <div class="text-3xl font-bold text-pink-600">
                            {{ latestBodyFat ? `${latestBodyFat}%` : '—' }}
                        </div>
                        <div class="text-text-muted mt-1 text-sm font-semibold">Masse Grasse</div>
                    </div>
                </GlassCard>
            </div>

            <!-- Add Form (collapsible) -->
            <GlassCard v-if="showAddForm" class="animate-slide-up">
                <h3 class="text-text-main mb-4 font-semibold">Nouvelle entrée</h3>
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-3 gap-4">
                        <GlassInput
                            v-model="form.weight"
                            type="number"
                            step="0.1"
                            label="Poids (kg)"
                            placeholder="75.5"
                            :error="form.errors.weight"
                            inputmode="decimal"
                            required
                        />
                        <GlassInput
                            v-model="form.body_fat"
                            type="number"
                            step="0.1"
                            label="Gras (%)"
                            placeholder="15.0"
                            :error="form.errors.body_fat"
                            inputmode="decimal"
                        />
                        <GlassInput
                            v-model="form.measured_at"
                            type="date"
                            label="Date"
                            :error="form.errors.measured_at"
                            required
                        />
                    </div>
                    <GlassInput
                        v-model="form.notes"
                        label="Notes (optionnel)"
                        placeholder="Matin, à jeun..."
                        :error="form.errors.notes"
                    />
                    <GlassButton type="submit" variant="primary" class="w-full" :loading="form.processing">
                        Enregistrer
                    </GlassButton>
                </form>
            </GlassCard>

            <!-- Chart -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.1s">
                <h3 class="font-display mb-4 text-xs font-black tracking-[0.2em] text-sky-600 uppercase">Évolution</h3>
                <div class="h-64">
                    <WeightHistoryChart v-if="weightHistory && weightHistory.length > 0" :data="weightHistory" />
                    <div v-else class="text-text-muted/50 flex h-full items-center justify-center font-medium">
                        Aucune donnée disponible
                    </div>
                </div>
            </GlassCard>

            <!-- History -->
            <div class="animate-slide-up" style="animation-delay: 0.2s">
                <h3 class="font-display mb-3 text-xs font-black tracking-[0.2em] text-sky-600 uppercase">Historique</h3>

                <div v-if="measurements.length === 0">
                    <GlassCard>
                        <div class="py-8 text-center">
                            <div class="mb-2 text-4xl">⚖️</div>
                            <p class="text-text-muted">Aucune mesure pour l'instant</p>
                        </div>
                    </GlassCard>
                </div>

                <div v-else class="space-y-2">
                    <GlassCard
                        v-for="measurement in [...measurements].reverse()"
                        :key="measurement.id"
                        padding="p-4"
                        class="group"
                    >
                        <div class="flex items-center justify-between">
                            <div>
                                <div class="flex items-baseline gap-2">
                                    <span class="text-text-main text-xl font-bold">{{ measurement.weight }} kg</span>
                                    <span
                                        v-if="measurement.body_fat"
                                        class="rounded-full bg-pink-50 px-2 py-0.5 text-xs font-bold text-pink-600"
                                        >{{ measurement.body_fat }}% BF</span
                                    >
                                </div>
                                <div class="text-text-muted text-sm font-medium">
                                    {{
                                        new Date(measurement.measured_at + 'T00:00:00').toLocaleDateString('fr-FR', {
                                            weekday: 'short',
                                            day: 'numeric',
                                            month: 'short',
                                            year: 'numeric',
                                        })
                                    }}
                                </div>
                                <div v-if="measurement.notes" class="text-text-muted/70 mt-1 text-xs italic">
                                    {{ measurement.notes }}
                                </div>
                            </div>
                            <button
                                @click="deleteMeasurement(measurement.id)"
                                class="text-text-muted/30 rounded-lg p-2 opacity-0 transition group-hover:opacity-100 hover:text-red-400 focus:opacity-100"
                                :aria-label="`Supprimer la mesure du ${new Date(measurement.measured_at).toLocaleDateString('fr-FR')}`"
                            >
                                <svg
                                    class="h-5 w-5"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
                                    aria-hidden="true"
                                >
                                    <path
                                        stroke-linecap="round"
                                        stroke-linejoin="round"
                                        stroke-width="2"
                                        d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"
                                    />
                                </svg>
                            </button>
                        </div>
                    </GlassCard>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
