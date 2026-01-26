<template>
    <Head title="Interval Timer" />

    <AuthenticatedLayout page-title="Interval Timer" show-back back-route="tools.index">
        <template #header>
            <div class="flex items-center gap-4">
                <Link
                    :href="route('tools.index')"
                    class="text-text-muted hover:text-electric-orange flex h-10 w-10 items-center justify-center rounded-full border border-slate-200 bg-white shadow-sm transition-colors"
                >
                    <span class="material-symbols-outlined">arrow_back</span>
                </Link>
                <h2 class="text-text-main text-xl font-semibold">Interval Timer</h2>
            </div>
        </template>

        <!-- Active Timer View -->
        <div v-if="activePreset" class="flex flex-col items-center justify-center space-y-8 py-8 min-h-[60vh]">
            <!-- Timer Display -->
            <div class="relative flex h-80 w-80 items-center justify-center rounded-full border-8 border-white/10 bg-black/40 backdrop-blur-md shadow-2xl">
                <!-- Circular Progress Ring -->
                <svg class="absolute inset-0 h-full w-full -rotate-90 transform" viewBox="0 0 100 100">
                     <circle
                        cx="50"
                        cy="50"
                        r="45"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="8"
                        class="text-white/5"
                    />
                    <circle
                        cx="50"
                        cy="50"
                        r="45"
                        fill="none"
                        :stroke="phaseColor"
                        stroke-width="8"
                        stroke-linecap="round"
                        :stroke-dasharray="283"
                        :stroke-dashoffset="283 - (283 * progress / 100)"
                        class="transition-all duration-1000 ease-linear"
                    />
                </svg>

                <div class="z-10 text-center flex flex-col items-center">
                    <div class="text-2xl font-bold uppercase tracking-widest mb-2" :style="{ color: phaseColor }">
                        {{ phaseLabel }}
                    </div>
                    <div class="font-mono text-7xl font-black text-white tabular-nums leading-none">
                        {{ formatTime(timeLeft) }}
                    </div>
                    <div class="mt-4 text-white/60 text-lg font-medium bg-white/10 px-4 py-1 rounded-full">
                        Round {{ currentRound }} / {{ activePreset.rounds }}
                    </div>
                </div>
            </div>

            <!-- Controls -->
            <div class="flex gap-6">
                <GlassButton @click="toggleTimer" size="lg" :variant="isRunning ? 'secondary' : 'primary'" class="h-16 w-16 !rounded-full !p-0 flex items-center justify-center">
                    <span class="material-symbols-outlined text-4xl">{{ isRunning ? 'pause' : 'play_arrow' }}</span>
                </GlassButton>
                <GlassButton @click="stopTimer" size="lg" variant="danger" class="h-16 w-16 !rounded-full !p-0 flex items-center justify-center">
                    <span class="material-symbols-outlined text-4xl">stop</span>
                </GlassButton>
            </div>

            <!-- Next Up Indicator -->
            <div v-if="phase !== 'FINISHED'" class="text-text-muted text-sm">
                Next: {{ nextPhaseLabel }}
            </div>
        </div>

        <!-- Presets List View -->
        <div v-else class="space-y-8">
            <!-- New Preset Form -->
            <GlassCard>
                <div class="p-6">
                    <div class="mb-4 flex cursor-pointer items-center justify-between" @click="showForm = !showForm">
                        <h3 class="text-text-main text-lg font-bold flex items-center gap-2">
                             <span class="material-symbols-outlined">add_circle</span>
                             Create New Preset
                        </h3>
                        <span class="material-symbols-outlined text-text-muted transition-transform duration-300" :class="{ 'rotate-180': showForm }">expand_more</span>
                    </div>

                    <form v-if="showForm" @submit.prevent="createPreset" class="space-y-4 animate-fadeIn">
                        <div class="grid gap-4 md:grid-cols-2">
                            <div class="col-span-2">
                                <InputLabel value="Preset Name" />
                                <GlassInput v-model="form.name" placeholder="e.g. Tabata" class="w-full" required />
                            </div>
                            <div>
                                <InputLabel value="Work (seconds)" />
                                <GlassInput type="number" v-model="form.work_seconds" min="1" class="w-full" required />
                            </div>
                            <div>
                                <InputLabel value="Rest (seconds)" />
                                <GlassInput type="number" v-model="form.rest_seconds" min="0" class="w-full" required />
                            </div>
                            <div>
                                <InputLabel value="Rounds" />
                                <GlassInput type="number" v-model="form.rounds" min="1" class="w-full" required />
                            </div>
                            <div>
                                <InputLabel value="Warmup (seconds)" />
                                <GlassInput type="number" v-model="form.warmup_seconds" min="0" class="w-full" />
                            </div>
                            <div>
                                <InputLabel value="Cooldown (seconds)" />
                                <GlassInput type="number" v-model="form.cooldown_seconds" min="0" class="w-full" />
                            </div>
                        </div>
                        <div class="flex justify-end pt-2">
                            <GlassButton type="submit" variant="primary" :disabled="form.processing">
                                Save Preset
                            </GlassButton>
                        </div>
                    </form>
                </div>
            </GlassCard>

            <!-- Presets Grid -->
            <div class="grid gap-6 md:grid-cols-2 lg:grid-cols-3">
                <GlassCard v-for="preset in presets" :key="preset.id" class="group relative overflow-hidden transition hover:bg-white/5">
                    <div class="p-6 flex flex-col h-full">
                        <div class="flex items-start justify-between mb-4">
                            <div>
                                <h3 class="text-text-main text-xl font-bold">{{ preset.name }}</h3>
                                <div class="text-text-muted mt-1 text-sm flex items-center gap-1">
                                    <span class="material-symbols-outlined text-sm">repeat</span>
                                    {{ preset.rounds }} rounds
                                </div>
                            </div>
                            <button @click="deletePreset(preset)" class="text-white/20 hover:text-red-500 transition-colors p-1">
                                <span class="material-symbols-outlined">delete</span>
                            </button>
                        </div>

                        <div class="grid grid-cols-2 gap-3 text-sm mb-6">
                            <div class="bg-white/5 rounded-xl p-3 text-center border border-white/5">
                                <div class="text-accent-primary font-bold text-lg">{{ formatTime(preset.work_seconds) }}</div>
                                <div class="text-xs text-white/50 uppercase tracking-wider">Work</div>
                            </div>
                            <div class="bg-white/5 rounded-xl p-3 text-center border border-white/5">
                                <div class="text-blue-400 font-bold text-lg">{{ formatTime(preset.rest_seconds) }}</div>
                                <div class="text-xs text-white/50 uppercase tracking-wider">Rest</div>
                            </div>
                        </div>

                        <div class="mt-auto">
                             <GlassButton @click="startPreset(preset)" variant="primary" class="w-full justify-center group-hover:scale-[1.02] transition-transform">
                                <span class="material-symbols-outlined mr-2">play_arrow</span>
                                Start Timer
                             </GlassButton>
                        </div>
                    </div>
                </GlassCard>
            </div>

            <div v-if="presets.length === 0" class="text-center py-12 text-text-muted flex flex-col items-center gap-4">
                <div class="bg-white/5 p-6 rounded-full">
                    <span class="material-symbols-outlined text-4xl">timer</span>
                </div>
                <p>No presets found. Create one to get started!</p>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, watch, onUnmounted } from 'vue'
import { Link, useForm, router, Head } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import InputLabel from '@/Components/InputLabel.vue'

const props = defineProps({
    presets: Array,
})

// --- Form Logic ---
const showForm = ref(false)
const form = useForm({
    name: '',
    work_seconds: 30,
    rest_seconds: 10,
    rounds: 8,
    warmup_seconds: 10,
    cooldown_seconds: 0,
})

const createPreset = () => {
    form.post(route('tools.interval-timer.store'), {
        onSuccess: () => {
            showForm.value = false
            form.reset()
        },
    })
}

const deletePreset = (preset) => {
    if (confirm('Are you sure you want to delete this preset?')) {
        router.delete(route('tools.interval-timer.destroy', preset.id))
    }
}

// --- Timer Logic ---
const activePreset = ref(null)
const isRunning = ref(false)
const timeLeft = ref(0)
const currentRound = ref(1)
const phase = ref('IDLE') // WARMUP, WORK, REST, COOLDOWN, FINISHED
const timerInterval = ref(null)
const totalPhaseTime = ref(0) // Used for progress calculation

const phases = {
    WARMUP: 'WARMUP',
    WORK: 'WORK',
    REST: 'REST',
    COOLDOWN: 'COOLDOWN',
    FINISHED: 'FINISHED',
}

const startPreset = (preset) => {
    activePreset.value = preset
    currentRound.value = 1

    if (preset.warmup_seconds > 0) {
        setPhase(phases.WARMUP, preset.warmup_seconds)
    } else {
        setPhase(phases.WORK, preset.work_seconds)
    }

    startTimer()
}

const setPhase = (newPhase, seconds) => {
    phase.value = newPhase
    timeLeft.value = seconds
    totalPhaseTime.value = seconds
    playSound('phase_change')
}

const toggleTimer = () => {
    if (isRunning.value) {
        pauseTimer()
    } else {
        startTimer()
    }
}

const startTimer = () => {
    if (timerInterval.value) return
    if (phase.value === phases.FINISHED) return

    isRunning.value = true
    const endTime = Date.now() + timeLeft.value * 1000

    timerInterval.value = setInterval(() => {
        const now = Date.now()
        const diff = Math.ceil((endTime - now) / 1000)

        if (diff < timeLeft.value) {
            timeLeft.value = diff
        }

        if (timeLeft.value <= 0) {
            handlePhaseComplete()
        }
    }, 100) // Check more frequently for better precision
}

const pauseTimer = () => {
    clearInterval(timerInterval.value)
    timerInterval.value = null
    isRunning.value = false
}

const stopTimer = () => {
    pauseTimer()
    activePreset.value = null
    phase.value = 'IDLE'
}

const handlePhaseComplete = () => {
    pauseTimer() // Pause momentarily to switch phase

    const p = activePreset.value

    if (phase.value === phases.WARMUP) {
        setPhase(phases.WORK, p.work_seconds)
    } else if (phase.value === phases.WORK) {
        if (currentRound.value < p.rounds) {
            setPhase(phases.REST, p.rest_seconds)
        } else {
            // Last round done
            if (p.cooldown_seconds > 0) {
                setPhase(phases.COOLDOWN, p.cooldown_seconds)
            } else {
                finish()
                return
            }
        }
    } else if (phase.value === phases.REST) {
        currentRound.value++
        setPhase(phases.WORK, p.work_seconds)
    } else if (phase.value === phases.COOLDOWN) {
        finish()
        return
    }

    startTimer()
}

const finish = () => {
    phase.value = phases.FINISHED
    timeLeft.value = 0
    playSound('finish')
    // Maybe show a "Complete!" screen or just stop
    setTimeout(() => {
        stopTimer()
    }, 2000)
}

// --- Helpers ---
const formatTime = (seconds) => {
    if (seconds < 0) return '0:00'
    const m = Math.floor(seconds / 60)
    const s = seconds % 60
    return `${m}:${s.toString().padStart(2, '0')}`
}

const progress = computed(() => {
    if (totalPhaseTime.value === 0) return 0
    return ((totalPhaseTime.value - timeLeft.value) / totalPhaseTime.value) * 100
})

const phaseLabel = computed(() => {
    switch (phase.value) {
        case phases.WARMUP: return 'Warmup'
        case phases.WORK: return 'Work'
        case phases.REST: return 'Rest'
        case phases.COOLDOWN: return 'Cooldown'
        case phases.FINISHED: return 'Done'
        default: return ''
    }
})

const phaseColor = computed(() => {
    switch (phase.value) {
        case phases.WARMUP: return '#fbbf24' // Amber
        case phases.WORK: return '#facc15' // Electric Orange equivalent (Yellow/Orange) - using Yellow for contrast
        // Actually Electric Orange is defined in Tailwind. Let's use hex similar to it.
        // But for "Work", maybe Green or Red? Usually Work = Green/Red, Rest = Blue.
        // Let's go: Work = Primary (Electric Orange), Rest = Blue.
        case phases.WORK: return '#FF5500' // Electric Orange approx
        case phases.REST: return '#60a5fa' // Blue
        case phases.COOLDOWN: return '#a78bfa' // Purple
        default: return '#ffffff'
    }
})

const nextPhaseLabel = computed(() => {
    const p = activePreset.value
    if (!p) return ''

    if (phase.value === phases.WARMUP) return 'Work'
    if (phase.value === phases.WORK) return currentRound.value < p.rounds ? 'Rest' : (p.cooldown_seconds > 0 ? 'Cooldown' : 'Finish')
    if (phase.value === phases.REST) return `Work (Round ${currentRound.value + 1})`
    if (phase.value === phases.COOLDOWN) return 'Finish'
    return ''
})

const playSound = (type) => {
    try {
        const AudioContext = window.AudioContext || window.webkitAudioContext
        if (!AudioContext) return

        const ctx = new AudioContext()
        const osc = ctx.createOscillator()
        const gain = ctx.createGain()

        osc.connect(gain)
        gain.connect(ctx.destination)

        if (type === 'phase_change') {
            // High beep
            osc.type = 'sine'
            osc.frequency.setValueAtTime(880, ctx.currentTime)
            gain.gain.setValueAtTime(0.1, ctx.currentTime)
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.3)
            osc.start()
            osc.stop(ctx.currentTime + 0.3)
        } else if (type === 'finish') {
            // Victory sound sequence
            osc.type = 'triangle'
            osc.frequency.setValueAtTime(523.25, ctx.currentTime) // C5
            osc.frequency.setValueAtTime(659.25, ctx.currentTime + 0.2) // E5
            osc.frequency.setValueAtTime(783.99, ctx.currentTime + 0.4) // G5

            gain.gain.setValueAtTime(0.1, ctx.currentTime)
            gain.gain.setValueAtTime(0.1, ctx.currentTime + 0.6)
            gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 1.0)

            osc.start()
            osc.stop(ctx.currentTime + 1.0)
        }
    } catch (e) {
        console.error(e)
    }
}

onUnmounted(() => {
    pauseTimer()
})
</script>

<style scoped>
.animate-fadeIn {
    animation: fadeIn 0.3s ease-out;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>
