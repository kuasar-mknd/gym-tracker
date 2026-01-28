<template>
    <AuthenticatedLayout page-title="Fasting Tracker" show-back back-route="tools.index">
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

        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
            <!-- Active Fast / Start Fast Card -->
            <div class="lg:col-span-2">
                <GlassCard class="h-full p-6">
                    <div v-if="activeFast" class="flex flex-col items-center justify-center space-y-8 py-8">
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
                                    class="text-slate-200"
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
                                    stroke-linecap="round"
                                    :stroke-dasharray="283"
                                    :stroke-dashoffset="progressOffset"
                                />
                            </svg>
                            <div class="absolute flex flex-col items-center">
                                <span class="text-4xl font-bold text-slate-800">{{ formattedElapsedTime }}</span>
                                <span class="text-sm text-slate-500">écoulé</span>
                            </div>
                        </div>

                        <div class="grid w-full grid-cols-2 gap-4 text-center">
                            <div>
                                <p class="text-xs text-slate-500">DÉBUT</p>
                                <p class="font-semibold text-slate-700">{{ formatTime(activeFast.start_time) }}</p>
                            </div>
                            <div>
                                <p class="text-xs text-slate-500">OBJECTIF</p>
                                <p class="font-semibold text-slate-700">{{ formatTarget(activeFast.target_duration_minutes) }}</p>
                            </div>
                        </div>

                        <div class="w-full">
                            <div class="mb-2 flex justify-between text-sm">
                                <span class="text-slate-600">Objectif: {{ (progressPercentage).toFixed(1) }}%</span>
                                <span class="text-slate-600">Reste: {{ remainingTime }}</span>
                            </div>
                            <div class="h-2 w-full overflow-hidden rounded-full bg-slate-200">
                                <div
                                    class="h-full bg-gradient-to-r from-electric-orange to-hot-pink transition-all duration-1000"
                                    :style="{ width: `${Math.min(progressPercentage, 100)}%` }"
                                ></div>
                            </div>
                        </div>

                        <GlassButton @click="endFast" :disabled="form.processing" variant="primary" size="lg" class="w-full justify-center">
                            Terminer le Jeûne
                        </GlassButton>
                    </div>

                    <div v-else class="flex flex-col space-y-6 py-8">
                        <div class="text-center">
                            <div class="mx-auto mb-4 flex h-16 w-16 items-center justify-center rounded-full bg-electric-orange/10 text-electric-orange">
                                <span class="material-symbols-outlined text-3xl">timer</span>
                            </div>
                            <h3 class="text-xl font-bold text-slate-800">Prêt à jeûner ?</h3>
                            <p class="text-slate-500">Choisissez votre objectif et commencez.</p>
                        </div>

                        <form @submit.prevent="startFast" class="space-y-6">
                            <div>
                                <InputLabel for="type" value="Type de Jeûne" />
                                <div class="mt-2 grid grid-cols-3 gap-3">
                                    <button
                                        type="button"
                                        v-for="option in fastOptions"
                                        :key="option.value"
                                        @click="form.type = option.value; form.target_duration_minutes = option.minutes"
                                        class="rounded-lg border p-3 text-center transition-all hover:bg-slate-50"
                                        :class="form.type === option.value ? 'border-electric-orange bg-electric-orange/5 ring-1 ring-electric-orange' : 'border-slate-200'"
                                    >
                                        <div class="font-bold text-slate-800">{{ option.label }}</div>
                                        <div class="text-xs text-slate-500">{{ option.minutes / 60 }}h</div>
                                    </button>
                                </div>
                            </div>

                            <div>
                                <InputLabel for="start_time" value="Heure de début" />
                                <TextInput
                                    id="start_time"
                                    type="datetime-local"
                                    class="mt-1 block w-full"
                                    v-model="form.start_time"
                                    required
                                />
                                <InputError :message="form.errors.start_time" class="mt-2" />
                            </div>

                            <GlassButton type="submit" :disabled="form.processing" variant="primary" size="lg" class="w-full justify-center">
                                Commencer le Jeûne
                            </GlassButton>
                        </form>
                    </div>
                </GlassCard>
            </div>

            <!-- History Sidebar -->
            <div class="lg:col-span-1">
                <GlassCard class="h-full p-6">
                    <h3 class="mb-4 text-lg font-bold text-slate-800">Historique Récent</h3>
                    <div v-if="history.length > 0" class="space-y-4">
                        <div v-for="fast in history" :key="fast.id" class="relative overflow-hidden rounded-xl border border-slate-100 bg-white p-4 shadow-sm transition-all hover:shadow-md">
                            <div class="flex items-center justify-between">
                                <div>
                                    <span class="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{{ fast.type }}</span>
                                    <p class="mt-1 font-semibold text-slate-800">{{ formatDuration(fast.start_time, fast.end_time) }}</p>
                                </div>
                                <div class="text-right">
                                    <p class="text-xs text-slate-500">{{ formatDate(fast.end_time) }}</p>
                                    <p class="text-xs font-medium" :class="getAchievementColor(fast)">
                                        {{ getAchievementText(fast) }}
                                    </p>
                                </div>
                            </div>
                             <div class="absolute bottom-0 left-0 h-1 w-full bg-slate-100">
                                <div
                                    class="h-full"
                                    :class="getProgressBarColor(fast)"
                                    :style="{ width: `${Math.min((calculateDurationMinutes(fast.start_time, fast.end_time) / fast.target_duration_minutes) * 100, 100)}%` }"
                                ></div>
                            </div>
                             <!-- Delete Button -->
                             <button
                                @click="deleteFast(fast)"
                                class="absolute top-2 right-2 text-slate-400 opacity-0 hover:text-red-500 group-hover:opacity-100 transition-opacity"
                             >
                                <span class="material-symbols-outlined text-sm">close</span>
                             </button>
                        </div>
                    </div>
                    <div v-else class="flex h-40 flex-col items-center justify-center text-center text-slate-400">
                        <span class="material-symbols-outlined mb-2 text-3xl">history</span>
                        <p>Aucun historique</p>
                    </div>
                </GlassCard>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Link, useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import InputLabel from '@/Components/InputLabel.vue'
import TextInput from '@/Components/TextInput.vue'
import InputError from '@/Components/InputError.vue'

const props = defineProps({
    activeFast: Object,
    history: Array,
})

// Current time for timer
const now = ref(new Date())
let timerInterval = null

onMounted(() => {
    timerInterval = setInterval(() => {
        now.value = new Date()
    }, 1000)

    // Set default start time to now formatted for datetime-local
    if (!props.activeFast) {
        setNowAsStart()
    }
})

onUnmounted(() => {
    clearInterval(timerInterval)
})

const setNowAsStart = () => {
    const date = new Date()
    date.setMinutes(date.getMinutes() - date.getTimezoneOffset())
    form.start_time = date.toISOString().slice(0, 16)
}

// Form logic
const fastOptions = [
    { label: '16:8', value: '16:8', minutes: 16 * 60 },
    { label: '18:6', value: '18:6', minutes: 18 * 60 },
    { label: '20:4', value: '20:4', minutes: 20 * 60 },
    { label: '24h', value: '24h', minutes: 24 * 60 },
    { label: 'OMAD', value: 'OMAD', minutes: 23 * 60 },
    { label: 'Custom', value: 'Custom', minutes: 12 * 60 },
]

const form = useForm({
    start_time: '',
    target_duration_minutes: 16 * 60,
    type: '16:8',
})

const startFast = () => {
    form.post(route('tools.fasting.store'), {
        onSuccess: () => form.reset(),
    })
}

const endFast = () => {
    if (confirm('Voulez-vous vraiment terminer ce jeûne ?')) {
        router.patch(route('tools.fasting.update', props.activeFast.id), {
            end_time: new Date().toISOString(), // Use client time? Better use server time logic or send current time
            // Actually sending current time is fine
        })
    }
}

const deleteFast = (fast) => {
    if (confirm('Supprimer cet enregistrement ?')) {
         router.delete(route('tools.fasting.destroy', fast.id))
    }
}

// Timer Logic
const elapsedTimeSeconds = computed(() => {
    if (!props.activeFast) return 0
    const start = new Date(props.activeFast.start_time).getTime()
    const current = now.value.getTime()
    return Math.floor((current - start) / 1000)
})

const formattedElapsedTime = computed(() => {
    const totalSeconds = elapsedTimeSeconds.value
    if (totalSeconds < 0) return '00:00:00' // Should not happen usually

    const hours = Math.floor(totalSeconds / 3600)
    const minutes = Math.floor((totalSeconds % 3600) / 60)
    const seconds = totalSeconds % 60

    return `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`
})

const progressPercentage = computed(() => {
    if (!props.activeFast) return 0
    const totalSeconds = elapsedTimeSeconds.value
    const targetSeconds = props.activeFast.target_duration_minutes * 60
    return (totalSeconds / targetSeconds) * 100
})

const progressOffset = computed(() => {
    // 283 is circumference of circle radius 45 (2 * pi * 45)
    const circumference = 283
    const progress = Math.min(progressPercentage.value / 100, 1)
    return circumference - (progress * circumference)
})

const remainingTime = computed(() => {
    if (!props.activeFast) return '0h 00m'
    const targetSeconds = props.activeFast.target_duration_minutes * 60
    const totalSeconds = elapsedTimeSeconds.value
    const remaining = targetSeconds - totalSeconds

    if (remaining <= 0) return 'Objectif atteint !'

    const hours = Math.floor(remaining / 3600)
    const minutes = Math.floor((remaining % 3600) / 60)
    return `${hours}h ${minutes}m`
})

// Formatting Helpers
const formatTime = (dateString) => {
    return new Date(dateString).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}

const formatTarget = (minutes) => {
    return `${Math.floor(minutes / 60)}h`
}

const formatDate = (dateString) => {
    return new Date(dateString).toLocaleDateString([], { day: 'numeric', month: 'short' })
}

const formatDuration = (start, end) => {
    const diff = (new Date(end) - new Date(start)) / 1000 / 3600
    return `${diff.toFixed(1)}h`
}

const calculateDurationMinutes = (start, end) => {
    return (new Date(end) - new Date(start)) / 1000 / 60
}

const getAchievementColor = (fast) => {
    const duration = calculateDurationMinutes(fast.start_time, fast.end_time)
    if (duration >= fast.target_duration_minutes) return 'text-emerald-500'
    return 'text-amber-500'
}

const getAchievementText = (fast) => {
    const duration = calculateDurationMinutes(fast.start_time, fast.end_time)
    if (duration >= fast.target_duration_minutes) return 'Objectif atteint'
    return 'Objectif non atteint'
}

const getProgressBarColor = (fast) => {
    const duration = calculateDurationMinutes(fast.start_time, fast.end_time)
    if (duration >= fast.target_duration_minutes) return 'bg-emerald-500'
    return 'bg-amber-500'
}
</script>
