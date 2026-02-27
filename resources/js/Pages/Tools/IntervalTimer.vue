<script setup>
import { ref, computed, onUnmounted, onMounted } from 'vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'

const props = defineProps({
    timers: {
        type: Array,
        default: () => [],
    },
})

// State for tabs
const activeTab = ref('timer') // 'timer' | 'config'

// Form for creating/editing
const form = useForm({
    id: null,
    name: 'Tabata',
    work_seconds: 20,
    rest_seconds: 10,
    rounds: 8,
    warmup_seconds: 10,
})

// Timer Runner State
const timerConfig = ref({
    name: 'Tabata',
    work: 20,
    rest: 10,
    rounds: 8,
    warmup: 10,
})

const status = ref('idle') // 'idle', 'running', 'paused', 'finished'
const phase = ref('idle') // 'idle', 'warmup', 'work', 'rest', 'finished'
const timeLeft = ref(0)
const currentRound = ref(1)
const intervalId = ref(null)

const isEditing = computed(() => !!form.id)

// --- Actions ---

const submitForm = () => {
    if (isEditing.value) {
        form.patch(route('tools.interval-timer.update', form.id), {
            onSuccess: () => {
                resetForm()
                activeTab.value = 'config' // Stay on config list
            },
        })
    } else {
        form.post(route('tools.interval-timer.store'), {
            onSuccess: () => {
                resetForm()
                activeTab.value = 'config'
            },
        })
    }
}

const resetForm = () => {
    form.reset()
    form.id = null
    form.name = 'Tabata'
    form.work_seconds = 20
    form.rest_seconds = 10
    form.rounds = 8
    form.warmup_seconds = 10
}

const editTimer = (timer) => {
    form.id = timer.id
    form.name = timer.name
    form.work_seconds = timer.work_seconds
    form.rest_seconds = timer.rest_seconds
    form.rounds = timer.rounds
    form.warmup_seconds = timer.warmup_seconds
    activeTab.value = 'config'
    // Scroll to top of form
    window.scrollTo({ top: 0, behavior: 'smooth' })
}

const deleteTimer = (timer) => {
    if (confirm('Voulez-vous vraiment supprimer ce minuteur ?')) {
        router.delete(route('tools.interval-timer.destroy', timer.id))
    }
}

const loadTimer = (timer) => {
    timerConfig.value = {
        name: timer.name,
        work: timer.work_seconds,
        rest: timer.rest_seconds,
        rounds: timer.rounds,
        warmup: timer.warmup_seconds || 0,
    }
    resetRunner()
    activeTab.value = 'timer'
}

const previewFromForm = () => {
    timerConfig.value = {
        name: form.name || 'Custom',
        work: form.work_seconds,
        rest: form.rest_seconds,
        rounds: form.rounds,
        warmup: form.warmup_seconds || 0,
    }
    resetRunner()
    activeTab.value = 'timer'
}

// --- Runner Logic ---

const resetRunner = () => {
    stopInterval()
    status.value = 'idle'
    phase.value = 'idle'
    timeLeft.value = timerConfig.value.warmup > 0 ? timerConfig.value.warmup : timerConfig.value.work
    currentRound.value = 1
}

const toggleTimer = () => {
    if (status.value === 'running') {
        pauseTimer()
    } else {
        startTimer()
    }
}

const startTimer = () => {
    // Initialize audio context on user gesture
    getAudioCtx()

    if (status.value === 'finished') {
        resetRunner()
    }

    if (status.value === 'idle') {
        // Initialize
        if (timerConfig.value.warmup > 0) {
            phase.value = 'warmup'
            timeLeft.value = timerConfig.value.warmup
        } else {
            phase.value = 'work'
            timeLeft.value = timerConfig.value.work
        }
    }

    status.value = 'running'

    // Check if we need to resume or start fresh tick
    intervalId.value = setInterval(tick, 1000)
}

const pauseTimer = () => {
    status.value = 'paused'
    stopInterval()
}

const stopInterval = () => {
    if (intervalId.value) {
        clearInterval(intervalId.value)
        intervalId.value = null
    }
}

const tick = () => {
    if (timeLeft.value > 1) {
        timeLeft.value--
        if (timeLeft.value <= 3) {
            playBeep(440, 100) // Countdown beep
        }
    } else {
        // Phase transition
        handlePhaseTransition()
    }
}

const handlePhaseTransition = () => {
    playBeep(880, 400) // Phase change beep

    if (phase.value === 'warmup') {
        phase.value = 'work'
        timeLeft.value = timerConfig.value.work
    } else if (phase.value === 'work') {
        if (currentRound.value < timerConfig.value.rounds) {
            phase.value = 'rest'
            timeLeft.value = timerConfig.value.rest
        } else {
            finishTimer()
        }
    } else if (phase.value === 'rest') {
        currentRound.value++
        phase.value = 'work'
        timeLeft.value = timerConfig.value.work
    }
}

const finishTimer = () => {
    status.value = 'finished'
    phase.value = 'finished'
    timeLeft.value = 0
    stopInterval()
    playBeep(1200, 600) // Finish beep
}

// --- Audio ---
const audioCtx = ref(null)

const getAudioCtx = () => {
    if (!audioCtx.value) {
        const AudioContext = window.AudioContext || window.webkitAudioContext
        if (AudioContext) {
            audioCtx.value = new AudioContext()
        }
    }
    // Resume if suspended (browser policy)
    if (audioCtx.value && audioCtx.value.state === 'suspended') {
        audioCtx.value.resume()
    }
    return audioCtx.value
}

const playBeep = (freq = 440, duration = 200) => {
    try {
        const ctx = getAudioCtx()
        if (!ctx) return

        const osc = ctx.createOscillator()
        const gain = ctx.createGain()

        osc.connect(gain)
        gain.connect(ctx.destination)

        osc.type = 'sine'
        osc.frequency.value = freq

        osc.start()
        gain.gain.exponentialRampToValueAtTime(0.00001, ctx.currentTime + duration / 1000)
        osc.stop(ctx.currentTime + duration / 1000)
    } catch (e) {
        console.error('Audio error', e)
    }
}

// Format time mm:ss
const formattedTime = computed(() => {
    const minutes = Math.floor(timeLeft.value / 60)
    const seconds = timeLeft.value % 60
    return `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`
})

// Visuals
const phaseColor = computed(() => {
    switch (phase.value) {
        case 'warmup':
            return 'text-blue-400'
        case 'work':
            return 'text-electric-orange'
        case 'rest':
            return 'text-emerald-400'
        case 'finished':
            return 'text-text-main'
        default:
            return 'text-text-main'
    }
})

const phaseBg = computed(() => {
    switch (phase.value) {
        case 'warmup':
            return 'bg-blue-500/10 border-blue-500/20'
        case 'work':
            return 'bg-electric-orange/10 border-electric-orange/20'
        case 'rest':
            return 'bg-emerald-500/10 border-emerald-500/20'
        case 'finished':
            return 'bg-white/10 border-white/20'
        default:
            return 'bg-white/5 border-white/10'
    }
})

const phaseLabel = computed(() => {
    switch (phase.value) {
        case 'warmup':
            return 'ÉCHAUFFEMENT'
        case 'work':
            return 'TRAVAIL'
        case 'rest':
            return 'REPOS'
        case 'finished':
            return 'TERMINÉ'
        default:
            return 'PRÊT'
    }
})

onMounted(() => {
    resetRunner()
})

onUnmounted(() => {
    stopInterval()
    if (audioCtx.value) {
        audioCtx.value.close()
    }
})
</script>

<template>
    <Head title="Minuteur d'Intervalle" />

    <AuthenticatedLayout page-title="Minuteur d'Intervalle" show-back back-route="tools.index">
        <template #header-actions>
            <!-- Mobile actions -->
        </template>

        <div class="space-y-6">
            <!-- Tabs -->
            <div class="glass-panel-light flex space-x-1 rounded-xl p-1">
                <button
                    v-for="tab in ['timer', 'config']"
                    :key="tab"
                    @click="activeTab = tab"
                    class="w-full rounded-lg py-2.5 text-sm leading-5 font-medium transition-all duration-200"
                    :class="[
                        activeTab === tab
                            ? 'text-text-main bg-white shadow'
                            : 'text-text-muted hover:bg-white/[0.12] hover:text-white',
                    ]"
                >
                    {{ tab === 'timer' ? 'Minuteur' : 'Préréglages' }}
                </button>
            </div>

            <!-- Timer Tab -->
            <div v-if="activeTab === 'timer'" class="space-y-6">
                <GlassCard
                    class="flex flex-col items-center justify-center border-2 px-4 py-12 transition-colors duration-500"
                    :class="phaseBg"
                >
                    <div class="mb-4 text-sm font-black tracking-[0.2em] uppercase" :class="phaseColor">
                        {{ phaseLabel }}
                    </div>

                    <div
                        role="timer"
                        aria-atomic="true"
                        class="font-display text-[6rem] leading-none font-black tracking-tighter tabular-nums"
                        :class="phaseColor"
                    >
                        {{ formattedTime }}
                    </div>

                    <div class="text-text-muted mt-8 flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">repeat</span>
                        <span class="font-bold">{{ currentRound }}</span>
                        <span class="text-xs">/ {{ timerConfig.rounds }}</span>
                    </div>

                    <div class="text-text-muted mt-2 text-xs">
                        {{ timerConfig.name }}
                    </div>

                    <!-- Controls -->
                    <div class="mt-12 flex gap-4">
                        <button
                            @click="toggleTimer"
                            class="text-text-main flex h-16 w-16 items-center justify-center rounded-full bg-white shadow-lg transition-transform hover:scale-110 active:scale-95"
                            :aria-label="status === 'running' ? 'Pause' : 'Démarrer'"
                        >
                            <span class="material-symbols-outlined text-3xl" aria-hidden="true">
                                {{ status === 'running' ? 'pause' : 'play_arrow' }}
                            </span>
                        </button>

                        <button
                            @click="resetRunner"
                            class="flex h-16 w-16 items-center justify-center rounded-full border border-white/20 bg-white/10 text-white shadow-lg backdrop-blur-md transition-transform hover:scale-110 active:scale-95"
                            aria-label="Réinitialiser"
                        >
                            <span class="material-symbols-outlined text-3xl" aria-hidden="true">restart_alt</span>
                        </button>
                    </div>
                </GlassCard>

                <!-- Legend/Info -->
                <div class="grid grid-cols-3 gap-4 text-center">
                    <div class="glass-panel p-3">
                        <div class="text-text-muted text-xs tracking-wider uppercase">Travail</div>
                        <div class="text-electric-orange text-xl font-bold">{{ timerConfig.work }}s</div>
                    </div>
                    <div class="glass-panel p-3">
                        <div class="text-text-muted text-xs tracking-wider uppercase">Repos</div>
                        <div class="text-xl font-bold text-emerald-400">{{ timerConfig.rest }}s</div>
                    </div>
                    <div class="glass-panel p-3">
                        <div class="text-text-muted text-xs tracking-wider uppercase">Échauff.</div>
                        <div class="text-xl font-bold text-blue-400">{{ timerConfig.warmup }}s</div>
                    </div>
                </div>
            </div>

            <!-- Config/Presets Tab -->
            <div v-else class="space-y-6">
                <!-- Form -->
                <GlassCard class="p-6">
                    <h3 class="text-text-main mb-4 text-lg font-bold">
                        {{ isEditing ? 'Modifier le minuteur' : 'Nouveau minuteur' }}
                    </h3>
                    <form @submit.prevent="submitForm" class="space-y-4">
                        <GlassInput
                            v-model="form.name"
                            label="Nom"
                            placeholder="ex. Tabata"
                            required
                            :error="form.errors.name"
                        />
                        <div class="grid grid-cols-2 gap-4">
                            <GlassInput
                                v-model.number="form.work_seconds"
                                type="number"
                                label="Travail (s)"
                                required
                                :error="form.errors.work_seconds"
                            />
                            <GlassInput
                                v-model.number="form.rest_seconds"
                                type="number"
                                label="Repos (s)"
                                required
                                :error="form.errors.rest_seconds"
                            />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <GlassInput
                                v-model.number="form.rounds"
                                type="number"
                                label="Tours"
                                required
                                :error="form.errors.rounds"
                            />
                            <GlassInput
                                v-model.number="form.warmup_seconds"
                                type="number"
                                label="Échauffement (s)"
                                :error="form.errors.warmup_seconds"
                            />
                        </div>

                        <div class="mt-4 flex justify-end gap-2">
                            <GlassButton v-if="!isEditing" type="button" variant="secondary" @click="previewFromForm">
                                Lancer
                            </GlassButton>
                            <GlassButton v-if="isEditing" type="button" variant="secondary" @click="resetForm">
                                Annuler
                            </GlassButton>
                            <GlassButton type="submit" :loading="form.processing">
                                {{ isEditing ? 'Mettre à jour' : 'Enregistrer' }}
                            </GlassButton>
                        </div>
                    </form>
                </GlassCard>

                <!-- List of Timers -->
                <div class="space-y-4">
                    <h3 class="text-text-main px-2 text-lg font-bold">Mes Minuteurs</h3>
                    <div v-if="timers.length === 0" class="text-text-muted py-8 text-center">
                        Aucun minuteur enregistré.
                    </div>
                    <GlassCard
                        v-for="timer in timers"
                        :key="timer.id"
                        class="group relative flex items-center justify-between overflow-hidden p-4"
                    >
                        <div class="relative z-10 cursor-pointer" @click="loadTimer(timer)">
                            <h4 class="text-text-main group-hover:text-electric-orange font-bold transition-colors">
                                {{ timer.name }}
                            </h4>
                            <p class="text-text-muted text-xs">
                                {{ timer.rounds }}x {{ timer.work_seconds }}s Travail / {{ timer.rest_seconds }}s Repos
                            </p>
                        </div>
                        <div class="relative z-10 flex items-center gap-2">
                            <button
                                @click="loadTimer(timer)"
                                class="text-text-muted hover:text-electric-orange p-2 transition-colors"
                                title="Charger & Lancer"
                            >
                                <span class="material-symbols-outlined">play_circle</span>
                            </button>
                            <button
                                @click="editTimer(timer)"
                                class="text-text-muted p-2 transition-colors hover:text-blue-500"
                            >
                                <span class="material-symbols-outlined">edit</span>
                            </button>
                            <button
                                @click="deleteTimer(timer)"
                                class="text-text-muted p-2 transition-colors hover:text-red-500"
                            >
                                <span class="material-symbols-outlined">delete</span>
                            </button>
                        </div>
                    </GlassCard>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
