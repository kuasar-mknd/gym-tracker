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
                <h2 class="text-text-main text-xl font-semibold">Fasting Tracker</h2>
            </div>
        </template>

        <div class="space-y-6">
            <!-- Active Fast Timer -->
            <GlassCard class="p-8">
                <div class="flex flex-col items-center justify-center space-y-6">
                    <div v-if="!activeFast" class="text-center">
                        <h3 class="text-text-main text-2xl font-bold">Ready to Fast?</h3>
                        <p class="text-text-muted mb-6">Select a plan and start your timer.</p>

                        <div class="mb-6 flex flex-wrap justify-center gap-2">
                            <button
                                v-for="plan in plans"
                                :key="plan.label"
                                @click="form.type = plan.label; form.target_duration_hours = plan.hours"
                                :class="[
                                    'rounded-full px-4 py-2 text-sm font-medium transition-colors border',
                                    form.type === plan.label
                                        ? 'bg-electric-orange text-white border-electric-orange'
                                        : 'bg-white/5 text-text-muted border-white/10 hover:bg-white/10'
                                ]"
                            >
                                {{ plan.label }}
                            </button>
                        </div>

                        <GlassButton
                            @click="startFast"
                            :disabled="form.processing"
                            class="w-full md:w-auto"
                        >
                            Start Fasting
                        </GlassButton>
                    </div>

                    <div v-else class="text-center w-full max-w-md">
                        <div class="relative mx-auto mb-6 h-64 w-64">
                            <!-- Circular Progress -->
                            <svg class="h-full w-full -rotate-90 transform" viewBox="0 0 100 100">
                                <circle
                                    cx="50"
                                    cy="50"
                                    r="45"
                                    fill="transparent"
                                    stroke="currentColor"
                                    class="text-slate-200/20"
                                    stroke-width="6"
                                />
                                <circle
                                    cx="50"
                                    cy="50"
                                    r="45"
                                    fill="transparent"
                                    stroke="currentColor"
                                    class="text-electric-orange transition-all duration-1000 ease-linear"
                                    stroke-width="6"
                                    stroke-dasharray="283"
                                    :stroke-dashoffset="dashOffset"
                                    stroke-linecap="round"
                                />
                            </svg>

                            <!-- Timer Text Overlay -->
                            <div class="absolute inset-0 flex flex-col items-center justify-center">
                                <span class="text-text-muted text-sm font-medium uppercase tracking-wider">Elapsed Time</span>
                                <span class="text-text-main font-mono text-4xl font-bold tracking-tight">
                                    {{ elapsedTimeDisplay }}
                                </span>
                                <span class="text-text-muted mt-1 text-xs">
                                    Target: {{ activeFast.type }} ({{ activeFast.target_duration_hours }}h)
                                </span>
                            </div>
                        </div>

                        <div class="mb-4 flex items-center justify-between text-sm">
                            <div class="text-text-muted">
                                <p>Started</p>
                                <p class="font-medium text-text-main">{{ formatDate(activeFast.start_time) }}</p>
                            </div>
                             <div class="text-text-muted text-right">
                                <p>Goal</p>
                                <p class="font-medium text-text-main">{{ calculateEndTime(activeFast.start_time, activeFast.target_duration_hours) }}</p>
                            </div>
                        </div>

                        <div class="flex gap-4">
                             <GlassButton
                                @click="endFast"
                                variant="danger"
                                :disabled="updateForm.processing"
                                class="flex-1"
                            >
                                End Fast
                            </GlassButton>
                             <GlassButton
                                @click="cancelFast"
                                variant="secondary"
                                :disabled="updateForm.processing"
                                class="flex-1"
                            >
                                Cancel
                            </GlassButton>
                        </div>
                    </div>
                </div>
            </GlassCard>

            <!-- History -->
            <div v-if="history.length > 0">
                <h3 class="text-text-main mb-4 text-lg font-semibold">Recent Fasts</h3>
                <div class="grid gap-4 md:grid-cols-2">
                    <GlassCard
                        v-for="log in history"
                        :key="log.id"
                        class="flex flex-col justify-between p-4"
                    >
                        <div class="mb-2 flex items-start justify-between">
                            <div>
                                <span class="text-electric-orange text-sm font-bold">{{ log.type }}</span>
                                <p class="text-text-muted text-xs">{{ formatDate(log.start_time) }}</p>
                            </div>
                            <div :class="[
                                'rounded-full px-2 py-1 text-xs font-bold',
                                log.status === 'completed' ? 'bg-green-500/20 text-green-500' : 'bg-red-500/20 text-red-500'
                            ]">
                                {{ log.status }}
                            </div>
                        </div>

                        <div class="flex items-end justify-between">
                            <div>
                                <p class="text-text-main font-mono text-xl font-bold">
                                    {{ calculateDuration(log.start_time, log.end_time) }}
                                </p>
                                <p class="text-text-muted text-xs">Duration</p>
                            </div>
                            <button
                                @click="deleteLog(log)"
                                class="text-text-muted hover:text-red-500 transition-colors"
                            >
                                <span class="material-symbols-outlined text-sm">delete</span>
                            </button>
                        </div>
                    </GlassCard>
                </div>
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
import dayjs from 'dayjs'
import duration from 'dayjs/plugin/duration'
import relativeTime from 'dayjs/plugin/relativeTime'

dayjs.extend(duration)
dayjs.extend(relativeTime)

const props = defineProps({
    activeFast: {
        type: Object,
        default: null
    },
    history: {
        type: Array,
        default: () => []
    }
})

const plans = [
    { label: '13:11', hours: 13 },
    { label: '16:8', hours: 16 },
    { label: '18:6', hours: 18 },
    { label: '20:4', hours: 20 },
    { label: 'OMAD', hours: 23 },
]

const form = useForm({
    start_time: '',
    target_duration_hours: 16,
    type: '16:8',
})

const updateForm = useForm({
    end_time: '',
    status: '',
})

const now = ref(dayjs())
const timerInterval = ref(null)

const elapsedTimeDisplay = computed(() => {
    if (!props.activeFast) return '00:00:00'
    const start = dayjs(props.activeFast.start_time)
    const diff = now.value.diff(start)
    return dayjs.duration(diff).format('HH:mm:ss')
})

const progressPercentage = computed(() => {
    if (!props.activeFast) return 0
    const start = dayjs(props.activeFast.start_time)
    const targetEnd = start.add(props.activeFast.target_duration_hours, 'hours')
    const totalDuration = targetEnd.diff(start)
    const currentDuration = now.value.diff(start)

    const pct = (currentDuration / totalDuration) * 100
    return Math.min(Math.max(pct, 0), 100)
})

const dashOffset = computed(() => {
    const circumference = 2 * Math.PI * 45 // r=45
    // 283 is approx circumference
    return 283 - (progressPercentage.value / 100) * 283
})

function startFast() {
    form.start_time = dayjs().format('YYYY-MM-DD HH:mm:ss')
    form.post(route('fasting.store'), {
        preserveScroll: true,
    })
}

function endFast() {
    updateForm.end_time = dayjs().format('YYYY-MM-DD HH:mm:ss')
    updateForm.status = 'completed'
    updateForm.put(route('fasting.update', props.activeFast.id), {
        preserveScroll: true,
    })
}

function cancelFast() {
    if(!confirm('Are you sure you want to cancel this fast? It will be marked as cancelled.')) return

    updateForm.end_time = dayjs().format('YYYY-MM-DD HH:mm:ss')
    updateForm.status = 'cancelled'
    updateForm.put(route('fasting.update', props.activeFast.id), {
        preserveScroll: true,
    })
}

function deleteLog(log) {
    if(!confirm('Delete this record?')) return
    router.delete(route('fasting.destroy', log.id), {
        preserveScroll: true,
    })
}

function formatDate(date) {
    return dayjs(date).format('MMM D, h:mm A')
}

function calculateEndTime(start, hours) {
    return dayjs(start).add(hours, 'hours').format('h:mm A')
}

function calculateDuration(start, end) {
    if(!end) return 'Ongoing'
    const diff = dayjs(end).diff(dayjs(start))
    const d = dayjs.duration(diff)
    const h = Math.floor(d.asHours())
    const m = d.minutes()
    return `${h}h ${m}m`
}

onMounted(() => {
    timerInterval.value = setInterval(() => {
        now.value = dayjs()
    }, 1000)
})

onUnmounted(() => {
    clearInterval(timerInterval.value)
})
</script>
