<template>
    <AuthenticatedLayout page-title="Suivi de Jeûne" show-back back-route="tools.index">
        <template #header>
            <div class="flex items-center gap-4">
                <Link
                    :href="route('tools.index')"
                    class="text-text-muted hover:text-electric-orange flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white shadow-sm transition-colors"
                >
                    <span class="material-symbols-outlined">arrow_back</span>
                </Link>
                <h2 class="text-text-main text-xl font-semibold">Suivi de Jeûne</h2>
            </div>
        </template>

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Timer / Control Panel -->
            <GlassCard class="p-6">
                <div v-if="activeFast" class="flex flex-col items-center space-y-6">
                    <h3 class="text-text-muted text-lg font-medium">Jeûne en cours</h3>

                    <!-- Circular Progress -->
                    <div class="relative flex h-64 w-64 items-center justify-center">
                        <svg class="h-full w-full -rotate-90 transform" viewBox="0 0 100 100">
                            <!-- Background Circle -->
                            <circle
                                cx="50"
                                cy="50"
                                r="45"
                                fill="none"
                                stroke="currentColor"
                                class="text-slate-200 dark:text-slate-700"
                                stroke-width="8"
                            />
                            <!-- Progress Circle -->
                            <circle
                                cx="50"
                                cy="50"
                                r="45"
                                fill="none"
                                stroke="currentColor"
                                class="text-electric-orange transition-all duration-1000 ease-linear"
                                stroke-width="8"
                                stroke-dasharray="283"
                                :stroke-dashoffset="dashOffset"
                                stroke-linecap="round"
                            />
                        </svg>

                        <!-- Time Display -->
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-text-main text-4xl font-bold tracking-tight">
                                {{ formattedElapsedTime }}
                            </span>
                            <span class="text-text-muted mt-1 text-sm">
                                Objectif: {{ activeFast.target_duration_hours }}h
                            </span>
                        </div>
                    </div>

                    <div class="w-full space-y-3">
                        <div class="flex justify-between text-sm">
                            <span class="text-text-muted">Début</span>
                            <span class="text-text-main font-medium">
                                {{ formatDate(activeFast.start_time) }}
                            </span>
                        </div>
                        <div class="flex justify-between text-sm">
                            <span class="text-text-muted">Fin estimée</span>
                            <span class="text-text-main font-medium">
                                {{ formatEstimatedEnd(activeFast.start_time, activeFast.target_duration_hours) }}
                            </span>
                        </div>
                    </div>

                    <GlassButton @click="endFast" variant="danger" class="w-full"> Terminer le jeûne </GlassButton>
                </div>

                <div v-else class="flex flex-col items-center space-y-6">
                    <div class="rounded-full bg-purple-500/20 p-6 text-purple-500">
                        <span class="material-symbols-outlined text-4xl">timer</span>
                    </div>

                    <h3 class="text-text-main text-xl font-bold">Commencer un jeûne</h3>

                    <div class="w-full space-y-4">
                        <div class="space-y-2">
                            <label class="text-text-muted text-sm font-medium">Objectif</label>
                            <div class="grid grid-cols-2 gap-3">
                                <button
                                    v-for="option in fastingOptions"
                                    :key="option.value"
                                    @click="
                                        form.target_duration_hours = option.value
                                        form.method = option.label
                                    "
                                    :class="[
                                        'rounded-xl border p-3 text-center text-sm transition-all',
                                        form.target_duration_hours === option.value
                                            ? 'border-electric-orange bg-electric-orange/10 text-electric-orange font-semibold'
                                            : 'text-text-muted border-slate-200 bg-white/50 hover:bg-white',
                                    ]"
                                >
                                    {{ option.label }}
                                </button>
                            </div>
                        </div>

                        <div v-if="form.method === 'Custom'" class="space-y-2">
                            <label class="text-text-muted text-sm font-medium">Heures</label>
                            <GlassInput type="number" v-model="form.target_duration_hours" min="1" max="168" />
                        </div>
                    </div>

                    <GlassButton
                        @click="startFast"
                        :disabled="form.processing"
                        class="bg-electric-orange w-full text-white"
                    >
                        Démarrer
                    </GlassButton>
                </div>
            </GlassCard>

            <!-- History -->
            <GlassCard class="flex h-full flex-col">
                <div class="flex items-center justify-between border-b border-slate-100 p-6">
                    <h3 class="text-text-main text-lg font-bold">Historique récent</h3>
                </div>

                <div class="flex-1 overflow-auto p-6">
                    <div
                        v-if="history.length === 0"
                        class="text-text-muted flex h-full flex-col items-center justify-center text-center"
                    >
                        <span class="material-symbols-outlined mb-2 text-3xl opacity-50">history</span>
                        <p>Aucun historique de jeûne.</p>
                    </div>

                    <div v-else class="space-y-4">
                        <div
                            v-for="log in history"
                            :key="log.id"
                            class="flex items-center justify-between rounded-xl bg-white/50 p-4 transition-colors hover:bg-white"
                        >
                            <div class="flex flex-col">
                                <span class="text-text-main font-medium">
                                    {{ calculateDuration(log.start_time, log.end_time) }}
                                </span>
                                <span class="text-text-muted text-xs">
                                    {{ formatDate(log.end_time) }} • {{ log.method }}
                                </span>
                            </div>

                            <div class="flex items-center gap-2">
                                <span
                                    class="rounded-full px-2 py-1 text-xs font-medium"
                                    :class="
                                        reachedTarget(log)
                                            ? 'bg-emerald-100 text-emerald-700'
                                            : 'bg-orange-100 text-orange-700'
                                    "
                                >
                                    {{ reachedTarget(log) ? 'Objectif atteint' : 'Incomplet' }}
                                </span>
                                <button
                                    @click="deleteLog(log)"
                                    class="text-text-muted transition-colors hover:text-red-500"
                                >
                                    <span class="material-symbols-outlined text-lg">delete</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </GlassCard>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Link, useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'

const props = defineProps({
    activeFast: Object,
    history: Array,
})

const now = ref(new Date())
let timerInterval = null

const form = useForm({
    target_duration_hours: 16,
    method: '16:8',
    start_time: null,
})

const updateForm = useForm({
    end_time: null,
})

const fastingOptions = [
    { label: '16:8', value: 16 },
    { label: '18:6', value: 18 },
    { label: '20:4', value: 20 },
    { label: 'OMAD', value: 23 },
]

const startFast = () => {
    form.post(route('fasting.store'))
}

const endFast = () => {
    updateForm.end_time = new Date().toISOString()
    updateForm.put(route('fasting.update', props.activeFast.id))
}

const deleteLog = (log) => {
    if (confirm('Supprimer cette entrée ?')) {
        router.delete(route('fasting.destroy', log.id))
    }
}

// Timer Logic
const elapsedTimeMs = computed(() => {
    if (!props.activeFast) return 0
    const start = new Date(props.activeFast.start_time)
    return Math.max(0, now.value - start)
})

const formattedElapsedTime = computed(() => {
    const totalSeconds = Math.floor(elapsedTimeMs.value / 1000)
    const h = Math.floor(totalSeconds / 3600)
    const m = Math.floor((totalSeconds % 3600) / 60)
    const s = totalSeconds % 60
    return `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`
})

const dashOffset = computed(() => {
    if (!props.activeFast) return 283
    const targetMs = props.activeFast.target_duration_hours * 3600 * 1000
    const progress = Math.min(1, elapsedTimeMs.value / targetMs)
    return 283 - 283 * progress
})

// Helpers
const formatDate = (dateString) => {
    if (!dateString) return ''
    return new Date(dateString).toLocaleString('fr-FR', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}

const formatEstimatedEnd = (startString, hours) => {
    const start = new Date(startString)
    const end = new Date(start.getTime() + hours * 3600 * 1000)
    return end.toLocaleString('fr-FR', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    })
}

const calculateDuration = (start, end) => {
    const s = new Date(start)
    const e = new Date(end)
    const diffHours = (e - s) / (1000 * 3600)
    return diffHours.toFixed(1) + 'h'
}

const reachedTarget = (log) => {
    const s = new Date(log.start_time)
    const e = new Date(log.end_time)
    const diffHours = (e - s) / (1000 * 3600)
    return diffHours >= log.target_duration_hours
}

onMounted(() => {
    timerInterval = setInterval(() => {
        now.value = new Date()
    }, 1000)
})

onUnmounted(() => {
    if (timerInterval) clearInterval(timerInterval)
})
</script>
