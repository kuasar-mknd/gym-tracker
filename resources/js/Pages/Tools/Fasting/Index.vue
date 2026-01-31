<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link, useForm, router } from '@inertiajs/vue3'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import { ref, computed, onMounted, onUnmounted } from 'vue'
import dayjs from 'dayjs'
import relativeTime from 'dayjs/plugin/relativeTime'
import duration from 'dayjs/plugin/duration'

dayjs.extend(relativeTime)
dayjs.extend(duration)

const props = defineProps({
    activeFast: Object,
    history: Object,
})

const fastingTypes = [
    { label: '16:8 (Leangains)', hours: 16 },
    { label: '18:6', hours: 18 },
    { label: '20:4 (Warrior)', hours: 20 },
    { label: '24h (OMAD)', hours: 24 },
    { label: '36h (Monk)', hours: 36 },
]

const selectedType = ref(fastingTypes[0])

const startForm = useForm({
    start_time: '',
    target_duration_minutes: 16 * 60,
    type: '16:8',
})

const endForm = useForm({
    end_time: '',
    status: 'completed',
})

// Timer Logic
const elapsedSeconds = ref(0)
const progressPercentage = ref(0)
const timerInterval = ref(null)

// Format seconds into HH:mm:ss
const formatDuration = (seconds) => {
    const h = Math.floor(seconds / 3600)
    const m = Math.floor((seconds % 3600) / 60)
    const s = Math.floor(seconds % 60)
    return `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`
}

const formattedElapsedTime = computed(() => {
    if (!props.activeFast) return '00:00:00'
    return formatDuration(elapsedSeconds.value)
})

const remainingTime = computed(() => {
    if (!props.activeFast) return ''
    const targetSeconds = props.activeFast.target_duration_minutes * 60
    const remaining = targetSeconds - elapsedSeconds.value
    if (remaining <= 0) return 'Objectif atteint !'
    return formatDuration(remaining) + ' restants'
})

const updateTimer = () => {
    if (!props.activeFast) return
    const start = dayjs(props.activeFast.start_time)
    const now = dayjs()
    const diff = now.diff(start, 'second')
    elapsedSeconds.value = diff

    const targetSeconds = props.activeFast.target_duration_minutes * 60
    progressPercentage.value = Math.min((diff / targetSeconds) * 100, 100)
}

onMounted(() => {
    startForm.start_time = dayjs().format('YYYY-MM-DDTHH:mm')

    if (props.activeFast) {
        updateTimer()
        timerInterval.value = setInterval(updateTimer, 1000)
    }
})

onUnmounted(() => {
    if (timerInterval.value) clearInterval(timerInterval.value)
})

const startFast = () => {
    startForm
        .transform((data) => ({
            ...data,
            target_duration_minutes: selectedType.value.hours * 60,
            type: selectedType.value.label.split(' ')[0],
        }))
        .post(route('tools.fasting.store'))
}

const endFast = () => {
    endForm.end_time = dayjs().format('YYYY-MM-DD HH:mm:ss')
    endForm.patch(route('tools.fasting.update', props.activeFast.id))
}

const deleteFast = (id) => {
    if (confirm('Êtes-vous sûr de vouloir supprimer ce jeûne ?')) {
        router.delete(route('tools.fasting.destroy', id))
    }
}

const formatDate = (date) => dayjs(date).format('DD/MM/YYYY HH:mm')
const formatHistoryDuration = (start, end) => {
    const diff = dayjs(end).diff(dayjs(start), 'second')
    return formatDuration(diff)
}
</script>

<template>
    <Head title="Suivi de Jeûne" />

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

        <div class="space-y-6">
            <!-- Active Fast Section -->
            <div v-if="activeFast" class="flex justify-center">
                <GlassCard class="relative flex w-full max-w-md flex-col items-center overflow-hidden p-8 text-center">
                    <!-- Circular Progress -->
                    <div class="relative mb-6 h-64 w-64">
                        <svg class="h-full w-full -rotate-90 transform" viewBox="0 0 100 100">
                            <!-- Background Circle -->
                            <circle
                                cx="50"
                                cy="50"
                                r="45"
                                fill="none"
                                stroke="rgba(255,255,255,0.1)"
                                stroke-width="8"
                            />
                            <!-- Progress Circle -->
                            <circle
                                cx="50"
                                cy="50"
                                r="45"
                                fill="none"
                                stroke="url(#gradient)"
                                stroke-width="8"
                                :stroke-dasharray="2 * Math.PI * 45"
                                :stroke-dashoffset="2 * Math.PI * 45 * (1 - progressPercentage / 100)"
                                stroke-linecap="round"
                                class="transition-all duration-1000 ease-linear"
                            />
                            <defs>
                                <linearGradient id="gradient" x1="0%" y1="0%" x2="100%" y2="0%">
                                    <stop offset="0%" stop-color="#FF9F43" />
                                    <stop offset="100%" stop-color="#FF5252" />
                                </linearGradient>
                            </defs>
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-text-main font-mono text-4xl font-bold">{{ formattedElapsedTime }}</span>
                            <span class="text-text-muted mt-2 text-sm">{{ remainingTime }}</span>
                        </div>
                    </div>

                    <div class="mb-6 space-y-2">
                        <p class="text-text-main font-medium">Débuté : {{ formatDate(activeFast.start_time) }}</p>
                        <p class="text-text-muted text-sm">Objectif : {{ activeFast.type }}</p>
                    </div>

                    <GlassButton @click="endFast" variant="accent" :loading="endForm.processing" class="w-full">
                        Terminer le jeûne
                    </GlassButton>
                </GlassCard>
            </div>

            <!-- Start Fast Section -->
            <GlassCard v-else class="mx-auto max-w-md p-6">
                <h3 class="text-text-main mb-6 text-lg font-semibold">Démarrer un jeûne</h3>

                <form @submit.prevent="startFast" class="space-y-4">
                    <div>
                        <label class="text-text-muted mb-2 block text-sm font-medium">Type de jeûne</label>
                        <select
                            v-model="selectedType"
                            class="text-text-main focus:border-electric-orange focus:ring-electric-orange w-full rounded-xl border-white/10 bg-white/5"
                        >
                            <option v-for="type in fastingTypes" :key="type.label" :value="type">
                                {{ type.label }}
                            </option>
                        </select>
                    </div>

                    <div>
                        <label class="text-text-muted mb-2 block text-sm font-medium">Début</label>
                        <GlassInput type="datetime-local" v-model="startForm.start_time" />
                    </div>

                    <GlassButton type="submit" variant="primary" :loading="startForm.processing" class="mt-4 w-full">
                        Commencer
                    </GlassButton>
                </form>
            </GlassCard>

            <!-- History Section -->
            <GlassCard class="p-6">
                <h3 class="text-text-main mb-4 text-lg font-semibold">Historique</h3>
                <div v-if="history.data.length === 0" class="text-text-muted py-4 text-center">
                    Aucun historique de jeûne.
                </div>
                <div v-else class="space-y-3">
                    <div
                        v-for="fast in history.data"
                        :key="fast.id"
                        class="flex items-center justify-between rounded-xl bg-white/5 p-3 transition-colors hover:bg-white/10"
                    >
                        <div>
                            <p class="text-text-main font-medium">{{ fast.type }}</p>
                            <p class="text-text-muted text-xs">
                                {{ formatDate(fast.start_time) }} -
                                {{ fast.end_time ? formatDate(fast.end_time) : 'En cours' }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3 text-right">
                            <span class="text-text-main font-mono text-sm" v-if="fast.end_time">
                                {{ formatHistoryDuration(fast.start_time, fast.end_time) }}
                            </span>
                            <button
                                @click="deleteFast(fast.id)"
                                class="text-red-400 transition-colors hover:text-red-300"
                            >
                                <span class="material-symbols-outlined text-lg">delete</span>
                            </button>
                        </div>
                    </div>
                </div>
            </GlassCard>
        </div>
    </AuthenticatedLayout>
</template>
