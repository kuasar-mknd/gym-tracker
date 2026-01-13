<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { computed } from 'vue'
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
  Filler
} from 'chart.js'

ChartJS.register(
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Title,
  Tooltip,
  Legend,
  Filler
)

const props = defineProps({
    measurements: Array,
})

const form = useForm({
    weight: '',
    measured_at: new Date().toISOString().substr(0, 10),
    notes: '',
})

const submit = () => {
    form.post(route('body-measurements.store'), {
        onSuccess: () => form.reset('weight', 'notes'),
    })
}

const deleteMeasurement = (id) => {
    if (confirm('Are you sure you want to delete this entry?')) {
        useForm({}).delete(route('body-measurements.destroy', id))
    }
}

const chartData = computed(() => {
    // Sort just in case, though controller does it too
    const sorted = [...props.measurements].sort((a, b) => new Date(a.measured_at) - new Date(b.measured_at))
    return {
        labels: sorted.map(m => new Date(m.measured_at + 'T00:00:00').toLocaleDateString()),
        datasets: [
            {
                label: 'Body Weight',
                backgroundColor: 'rgba(255, 255, 255, 0.2)',
                borderColor: '#ffffff',
                data: sorted.map(m => m.weight),
                tension: 0.4,
                fill: true,
            }
        ]
    }
})

const chartOptions = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
        legend: {
            labels: {
                color: '#ffffff'
            }
        }
    },
    scales: {
        x: {
            ticks: { color: '#e5e7eb' },
            grid: { color: 'rgba(255, 255, 255, 0.1)' }
        },
        y: {
            ticks: { color: '#e5e7eb' },
            grid: { color: 'rgba(255, 255, 255, 0.1)' }
        }
    }
}
</script>

<template>
    <Head title="Body Weight Tracker" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Body Weight Tracker</h2>
        </template>

        <div class="py-12 bg-gradient-to-br from-indigo-500 via-purple-500 to-pink-500 min-h-screen">
            <div class="mx-auto max-w-7xl sm:px-6 lg:px-8 space-y-6">

                <!-- Chart Section -->
                <div class="overflow-hidden rounded-2xl bg-white/10 p-6 backdrop-blur-lg shadow-xl border border-white/20">
                    <h3 class="text-lg font-medium text-white mb-4">Progress Chart</h3>
                    <div class="h-96 w-full">
                        <Line :data="chartData" :options="chartOptions" v-if="measurements.length > 0" />
                        <div v-else class="flex h-full items-center justify-center text-white/50">
                            No data available yet. Start logging your weight below!
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Add Measurement Form -->
                    <div class="rounded-2xl bg-white/10 p-6 backdrop-blur-lg shadow-xl border border-white/20">
                        <h3 class="text-lg font-medium text-white mb-4">Log Weight</h3>
                        <form @submit.prevent="submit" class="space-y-4">
                            <div>
                                <label for="weight" class="block text-sm font-medium text-white/80">Weight</label>
                                <input
                                    id="weight"
                                    v-model="form.weight"
                                    type="number"
                                    step="0.01"
                                    class="mt-1 block w-full rounded-xl border-none bg-white/20 py-2 px-3 text-white placeholder-white/50 shadow-inner focus:ring-2 focus:ring-white/50"
                                    placeholder="e.g. 75.5"
                                    required
                                />
                                <div v-if="form.errors.weight" class="mt-1 text-sm text-red-300">{{ form.errors.weight }}</div>
                            </div>

                            <div>
                                <label for="measured_at" class="block text-sm font-medium text-white/80">Date</label>
                                <input
                                    id="measured_at"
                                    v-model="form.measured_at"
                                    type="date"
                                    class="mt-1 block w-full rounded-xl border-none bg-white/20 py-2 px-3 text-white shadow-inner focus:ring-2 focus:ring-white/50"
                                    required
                                />
                                <div v-if="form.errors.measured_at" class="mt-1 text-sm text-red-300">{{ form.errors.measured_at }}</div>
                            </div>

                            <div>
                                <label for="notes" class="block text-sm font-medium text-white/80">Notes (Optional)</label>
                                <textarea
                                    id="notes"
                                    v-model="form.notes"
                                    class="mt-1 block w-full rounded-xl border-none bg-white/20 py-2 px-3 text-white placeholder-white/50 shadow-inner focus:ring-2 focus:ring-white/50"
                                    placeholder="Morning weigh-in..."
                                ></textarea>
                                <div v-if="form.errors.notes" class="mt-1 text-sm text-red-300">{{ form.errors.notes }}</div>
                            </div>

                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="w-full rounded-xl bg-white/20 py-2 px-4 font-bold text-white shadow-lg backdrop-blur-md transition hover:bg-white/30 disabled:opacity-50"
                            >
                                Save Entry
                            </button>
                        </form>
                    </div>

                    <!-- History List -->
                    <div class="rounded-2xl bg-white/10 p-6 backdrop-blur-lg shadow-xl border border-white/20 max-h-[500px] overflow-y-auto">
                        <h3 class="text-lg font-medium text-white mb-4">History</h3>
                        <ul class="space-y-3">
                            <li v-for="measurement in measurements.slice().reverse()" :key="measurement.id" class="flex items-center justify-between rounded-xl bg-white/5 p-3 hover:bg-white/10 transition group">
                                <div>
                                    <div class="text-xl font-bold text-white">{{ measurement.weight }}</div>
                                    <div class="text-xs text-white/60">{{ new Date(measurement.measured_at + 'T00:00:00').toLocaleDateString() }}</div>
                                    <div v-if="measurement.notes" class="text-xs text-white/50 mt-1 italic">{{ measurement.notes }}</div>
                                </div>
                                <button
                                    @click="deleteMeasurement(measurement.id)"
                                    class="rounded-full p-2 text-white/40 opacity-0 group-hover:opacity-100 hover:bg-white/10 hover:text-red-300 transition"
                                    title="Delete"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 000-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </li>
                            <li v-if="measurements.length === 0" class="text-center text-white/50 py-4">
                                No history yet.
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
