<template>
    <Head title="Fasting Tracker" />

    <AuthenticatedLayout page-title="Fasting Tracker" show-back back-route="tools.index">
        <div class="space-y-6">
            <!-- Header -->
            <header class="animate-fade-in">
                <h1
                    class="font-display text-text-main text-4xl leading-none font-black tracking-tighter uppercase italic"
                >
                    Fasting<br />
                    <span class="text-gradient from-purple-400 to-pink-500">Tracker</span>
                </h1>
                <p class="text-text-muted mt-2 text-sm font-semibold tracking-wider uppercase">
                    Intermittent Fasting
                </p>
            </header>

            <!-- Main Tracker Section -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.05s">
                <div class="flex flex-col items-center space-y-8 p-4">
                    <!-- Timer Display -->
                    <div class="relative flex h-72 w-72 items-center justify-center">
                        <svg class="h-full w-full rotate-[-90deg]" viewBox="0 0 100 100">
                            <!-- Background Circle -->
                            <circle
                                cx="50"
                                cy="50"
                                r="45"
                                fill="transparent"
                                stroke="#e2e8f0"
                                stroke-width="6"
                                stroke-linecap="round"
                            />
                            <!-- Progress Circle -->
                            <circle
                                cx="50"
                                cy="50"
                                r="45"
                                fill="transparent"
                                stroke="url(#gradient)"
                                stroke-width="6"
                                stroke-linecap="round"
                                stroke-dasharray="283"
                                :stroke-dashoffset="dashOffset"
                                class="transition-all duration-1000 ease-out"
                            />
                            <defs>
                                <linearGradient id="gradient" x1="0%" y1="0%" x2="100%" y2="0%">
                                    <stop offset="0%" stop-color="#a855f7" /> <!-- Purple-500 -->
                                    <stop offset="100%" stop-color="#ec4899" /> <!-- Pink-500 -->
                                </linearGradient>
                            </defs>
                        </svg>

                        <!-- Center Info -->
                        <div class="absolute flex flex-col items-center text-center">
                            <template v-if="activeFast">
                                <span class="text-text-muted text-xs font-bold uppercase mb-1">Elapsed</span>
                                <span class="font-display text-5xl font-black italic tracking-tighter text-text-main">
                                    {{ formattedElapsedTime }}
                                </span>
                                <span class="text-purple-500 mt-2 text-sm font-bold">
                                    {{ currentProgress }}% of {{ formattedTarget }}
                                </span>
                            </template>
                            <template v-else>
                                <span class="text-text-muted text-sm font-bold uppercase">Ready to fast?</span>
                                <span class="font-display text-4xl font-black italic tracking-tighter text-text-main mt-2">
                                    START
                                </span>
                            </template>
                        </div>
                    </div>

                    <!-- Controls -->
                    <div class="w-full max-w-md space-y-4">
                        <div v-if="!activeFast" class="grid grid-cols-3 gap-3">
                            <button
                                v-for="preset in presets"
                                :key="preset.label"
                                @click="selectPreset(preset)"
                                :class="[
                                    'flex flex-col items-center justify-center rounded-xl border p-3 transition-all active:scale-95',
                                    selectedPreset.label === preset.label
                                        ? 'border-purple-400 bg-purple-50 text-purple-600'
                                        : 'border-slate-200 bg-white hover:border-purple-300 hover:bg-purple-50 text-text-muted'
                                ]"
                            >
                                <span class="font-black italic">{{ preset.label }}</span>
                                <span class="text-xs">{{ preset.hours }}h</span>
                            </button>
                        </div>

                        <div v-if="!activeFast">
                             <GlassButton
                                @click="startFast"
                                :disabled="form.processing"
                                class="w-full h-14 text-lg"
                                variant="primary"
                            >
                                Start Fasting ({{ selectedPreset.label }})
                            </GlassButton>
                        </div>

                        <div v-else class="space-y-4">
                             <GlassButton
                                @click="endFast"
                                :disabled="form.processing"
                                class="w-full h-14 text-lg bg-gradient-to-r from-red-500 to-pink-500 border-none text-white shadow-lg shadow-red-500/30"
                            >
                                End Fast
                            </GlassButton>

                             <div class="text-center">
                                <p class="text-xs text-text-muted">
                                    Started: {{ new Date(activeFast.start_time).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' }) }}
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </GlassCard>

             <!-- History Section -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.1s">
                <div class="p-4">
                    <h2 class="font-display text-text-main mb-4 text-lg font-black uppercase italic">Recent Fasts</h2>

                    <div v-if="history.length === 0" class="py-8 text-center">
                         <span class="material-symbols-outlined mb-2 text-4xl text-slate-200">timer_off</span>
                        <p class="text-text-muted text-sm font-medium">No completed fasts yet.</p>
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="fast in history"
                            :key="fast.id"
                            class="flex items-center justify-between rounded-xl border border-slate-100 bg-white p-3 transition-all hover:border-slate-200"
                        >
                            <div class="flex items-center gap-4">
                                <div
                                    class="flex h-10 w-10 items-center justify-center rounded-full"
                                    :class="fast.progress_percent >= 100 ? 'bg-green-100 text-green-600' : 'bg-slate-100 text-slate-500'"
                                >
                                    <span class="material-symbols-outlined text-xl">
                                        {{ fast.progress_percent >= 100 ? 'check_circle' : 'timelapse' }}
                                    </span>
                                </div>
                                <div>
                                    <p class="text-text-main font-bold">{{ Math.floor(fast.duration_minutes / 60) }}h {{ fast.duration_minutes % 60 }}m</p>
                                    <p class="text-text-muted text-xs">
                                        {{ new Date(fast.start_time).toLocaleDateString() }} • {{ fast.type }}
                                    </p>
                                </div>
                            </div>
                             <div class="flex items-center gap-3">
                                <span class="text-xs font-bold px-2 py-1 rounded bg-slate-100 text-slate-600">
                                    {{ fast.progress_percent }}%
                                </span>
                                <button
                                    @click="deleteFast(fast)"
                                    class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition-colors hover:bg-red-50 hover:text-red-500"
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
import { Head, useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'

const props = defineProps({
    activeFast: Object,
    history: Array,
})

const presets = [
    { label: '16:8', hours: 16, minutes: 16 * 60 },
    { label: '18:6', hours: 18, minutes: 18 * 60 },
    { label: '20:4', hours: 20, minutes: 20 * 60 },
    { label: 'OMAD', hours: 23, minutes: 23 * 60 },
    { label: '24H', hours: 24, minutes: 24 * 60 },
    { label: '36H', hours: 36, minutes: 36 * 60 },
]

const selectedPreset = ref(presets[0])

const elapsedMinutes = ref(props.activeFast ? props.activeFast.elapsed_minutes : 0)
let timerInterval = null

const formattedElapsedTime = computed(() => {
    const totalMinutes = elapsedMinutes.value
    const hours = Math.floor(totalMinutes / 60)
    const minutes = totalMinutes % 60
    return `${hours}:${minutes.toString().padStart(2, '0')}`
})

const formattedTarget = computed(() => {
    if (!props.activeFast) return ''
    const totalMinutes = props.activeFast.target_duration_minutes
    return `${Math.floor(totalMinutes / 60)}h`
})

const currentProgress = computed(() => {
    if (!props.activeFast) return 0
    return Math.min(100, Math.round((elapsedMinutes.value / props.activeFast.target_duration_minutes) * 100))
})

const dashOffset = computed(() => {
    // 2 * PI * 45 = approx 283
    return 283 - (283 * currentProgress.value) / 100
})

const form = useForm({
    start_time: '',
    target_duration_minutes: '',
    type: '',
})

const selectPreset = (preset) => {
    selectedPreset.value = preset
}

const startFast = () => {
    form.start_time = new Date().toISOString()
    form.target_duration_minutes = selectedPreset.value.minutes
    form.type = selectedPreset.value.label

    form.post(route('tools.fasting.store'), {
        preserveScroll: true,
        onSuccess: () => {
            elapsedMinutes.value = 0
            startTimer()
        }
    })
}

const endFast = () => {
    router.post(route('tools.fasting.update', { fast: props.activeFast.id }), {
        action: 'end'
    }, {
        preserveScroll: true
    })
}

const deleteFast = (fast) => {
    if (confirm('Delete this fasting record?')) {
        router.delete(route('tools.fasting.destroy', { fast: fast.id }), {
            preserveScroll: true,
        })
    }
}

const updateTimer = () => {
    if (props.activeFast) {
        const start = new Date(props.activeFast.start_time)
        const now = new Date()
        const diffMs = now - start
        elapsedMinutes.value = Math.max(0, Math.floor(diffMs / 60000))
    }
}

const startTimer = () => {
    updateTimer()
    timerInterval = setInterval(updateTimer, 60000) // Update every minute
}

onMounted(() => {
    if (props.activeFast) {
        startTimer()
    }
})

onUnmounted(() => {
    if (timerInterval) clearInterval(timerInterval)
})
</script>

<style scoped>
.text-gradient {
    @apply bg-clip-text text-transparent bg-gradient-to-r;
}
</style>
