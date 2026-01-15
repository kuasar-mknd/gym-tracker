<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { Line } from 'vue-chartjs'
import {
    Chart as ChartJS,
    CategoryScale,
    LinearScale,
    PointElement,
    LineElement,
    Title,
    Tooltip,
    Legend,
    Filler,
} from 'chart.js'

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Title, Tooltip, Legend, Filler)

const props = defineProps({
    measurements: Array,
})

const showAddForm = ref(false)

const form = useForm({
    weight: '',
    measured_at: new Date().toISOString().substr(0, 10),
    notes: '',
})

const submit = () => {
    form.post(route('body-measurements.store'), {
        onSuccess: () => {
            form.reset('weight', 'notes')
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
            backgroundColor: 'rgba(26, 26, 46, 0.95)',
            titleColor: '#fff',
            bodyColor: '#fff',
            borderColor: 'rgba(255, 255, 255, 0.15)',
            borderWidth: 1,
            cornerRadius: 12,
            padding: 12,
        },
    },
    scales: {
        x: {
            ticks: { color: 'rgba(255, 255, 255, 0.5)', font: { size: 11 } },
            grid: { display: false },
            border: { display: false },
        },
        y: {
            ticks: { color: 'rgba(255, 255, 255, 0.5)', font: { size: 11 } },
            grid: { color: 'rgba(255, 255, 255, 0.05)' },
            border: { display: false },
        },
    },
}
</script>

<template>
    <Head title="Mesures" />

    <AuthenticatedLayout page-title="Mesures">
        <template #header-actions>
            <GlassButton size="sm" @click="showAddForm = !showAddForm">
                <svg
                    class="h-4 w-4"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                >
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
            </GlassButton>
        </template>

        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold text-white">Mesures</h2>
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
            <div class="grid animate-slide-up grid-cols-2 gap-3">
                <GlassCard padding="p-4">
                    <div class="text-center">
                        <div class="text-gradient text-3xl font-bold">
                            {{ latestWeight ? `${latestWeight}` : '—' }}
                        </div>
                        <div class="mt-1 text-sm text-white/60">kg actuel</div>
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
                                      : 'text-white/60',
                            ]"
                        >
                            {{ weightDiff ? `${weightDiff > 0 ? '+' : ''}${weightDiff}` : '—' }}
                        </div>
                        <div class="mt-1 text-sm text-white/60">kg évolution</div>
                    </div>
                </GlassCard>
            </div>

            <!-- Add Form (collapsible) -->
            <GlassCard v-if="showAddForm" class="animate-slide-up">
                <h3 class="mb-4 font-semibold text-white">Nouvelle entrée</h3>
                <form @submit.prevent="submit" class="space-y-4">
                    <div class="grid grid-cols-2 gap-4">
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
                <h3 class="mb-4 font-semibold text-white">Évolution</h3>
                <div class="h-64">
                    <Line v-if="measurements.length > 0" :data="chartData" :options="chartOptions" />
                    <div v-else class="flex h-full items-center justify-center text-white/40">
                        Aucune donnée disponible
                    </div>
                </div>
            </GlassCard>

            <!-- History -->
            <div class="animate-slide-up" style="animation-delay: 0.2s">
                <h3 class="mb-3 font-semibold text-white">Historique</h3>

                <div v-if="measurements.length === 0">
                    <GlassCard>
                        <div class="py-8 text-center">
                            <div class="mb-2 text-4xl">⚖️</div>
                            <p class="text-white/60">Aucune mesure pour l'instant</p>
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
                                <div class="text-xl font-bold text-white">{{ measurement.weight }} kg</div>
                                <div class="text-sm text-white/50">
                                    {{
                                        new Date(measurement.measured_at + 'T00:00:00').toLocaleDateString('fr-FR', {
                                            weekday: 'short',
                                            day: 'numeric',
                                            month: 'short',
                                            year: 'numeric',
                                        })
                                    }}
                                </div>
                                <div v-if="measurement.notes" class="mt-1 text-xs italic text-white/40">
                                    {{ measurement.notes }}
                                </div>
                            </div>
                            <button
                                @click="deleteMeasurement(measurement.id)"
                                class="rounded-lg p-2 text-white/30 opacity-0 transition hover:text-red-400 group-hover:opacity-100"
                            >
                                <svg
                                    class="h-5 w-5"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                    stroke="currentColor"
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

        <!-- FAB -->
        <button @click="showAddForm = !showAddForm" class="glass-fab sm:hidden">
            <svg
                class="h-6 w-6 text-white"
                xmlns="http://www.w3.org/2000/svg"
                fill="none"
                viewBox="0 0 24 24"
                stroke="currentColor"
            >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
        </button>
    </AuthenticatedLayout>
</template>
