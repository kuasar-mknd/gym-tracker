<script setup>
/**
 * Fasting Tracker Component.
 *
 * This component provides an interface for users to track their intermittent fasting.
 * It displays the currently active fast with a live progress timer, allows starting
 * new fasts by selecting different duration types (e.g., 16:8, 20:4), and shows a
 * history of past completed fasts.
 *
 * Props:
 * @prop {Object} activeFast - The currently ongoing fast object, if any. Null otherwise.
 *                             Expected shape: { id, type, start_time, target_duration_minutes, ... }
 * @prop {Object} history - Paginated object containing historical fasting records.
 *
 * Emits:
 * None directly. Uses Inertia forms for POST/PATCH/DELETE requests.
 */

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link, useForm, router } from '@inertiajs/vue3'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassSelect from '@/Components/UI/GlassSelect.vue'
import { ref, computed, onMounted, onUnmounted, watch, defineAsyncComponent } from 'vue'

const FastingHistoryChart = defineAsyncComponent(() => import('@/Components/Stats/FastingHistoryChart.vue'))
import dayjs from 'dayjs'
import relativeTime from 'dayjs/plugin/relativeTime'
import duration from 'dayjs/plugin/duration'

dayjs.extend(relativeTime)
dayjs.extend(duration)

const props = defineProps({
    activeFast: Object,
    history: Object,
})

// `type` is validated server-side against a fixed list — StoreFastRequest:
// in:16:8,18:6,20:4,24:0,36:0,48:0,custom — so it is data, not something to
// carve out of the label. It used to be derived with label.split(' ')[0],
// which yields '16:8', '18:6' and '20:4' correctly and then '24h' and '36h',
// neither of which the server accepts: two of the five options could never
// start a fast, and the page rendered no error to say why.
const fastingTypes = [
    { value: '16:8', label: '16:8 (Leangains)', hours: 16 },
    { value: '18:6', label: '18:6', hours: 18 },
    { value: '20:4', label: '20:4 (Warrior)', hours: 20 },
    { value: '24:0', label: '24h (OMAD)', hours: 24 },
    { value: '36:0', label: '36h (Monk)', hours: 36 },
]

// A <select> can only ever hand back a string. Binding the objects directly made
// every option render value="[object Object]", so picking anything other than the
// default left selectedType as that string — .hours became NaN and .label.split()
// threw, silently killing the "Commencer" button.
const selectedTypeValue = ref(fastingTypes[0].value)

const selectedType = computed(
    () => fastingTypes.find((type) => type.value === selectedTypeValue.value) ?? fastingTypes[0],
)

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

/**
 * Arms and disarms the clock as the fast itself comes and goes.
 *
 * `onMounted` alone was not enough. Starting a fast posts through Inertia, and
 * `router.post` preserves state by default, so this component is never
 * remounted — the hook does not run a second time. Anyone opening the page
 * without a fast in progress, which is the ordinary case, then pressed
 * "Commencer" and watched the card appear stuck at 00:00:00 with an empty ring,
 * for as long as they left it open.
 */
watch(
    () => props.activeFast,
    (fast) => {
        clearInterval(timerInterval.value)
        timerInterval.value = null

        if (!fast) {
            elapsedSeconds.value = 0
            progressPercentage.value = 0

            return
        }

        updateTimer()
        timerInterval.value = setInterval(updateTimer, 1000)
    },
    { immediate: true },
)

onMounted(() => {
    startForm.start_time = dayjs().format('YYYY-MM-DDTHH:mm')
})

onUnmounted(() => {
    if (timerInterval.value) clearInterval(timerInterval.value)
})

const startFast = () => {
    startForm
        .transform((data) => ({
            ...data,
            target_duration_minutes: selectedType.value.hours * 60,
            type: selectedType.value.value,
        }))
        .post(route('tools.fasting.store'))
}

const endFast = () => {
    endForm.end_time = dayjs().format('YYYY-MM-DD HH:mm:ss')
    endForm.patch(route('tools.fasting.update', props.activeFast.id))
}

const goToPage = (url) => {
    if (url) {
        router.visit(url)
    }
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
                    class="text-text-muted hover:text-electric-orange border-border bg-surface-card flex h-10 w-10 items-center justify-center rounded-full border shadow-sm transition-colors"
                >
                    <span class="material-symbols-outlined" aria-hidden="true">arrow_back</span>
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
            <GlassCard v-else class="mx-auto max-w-md">
                <h3 class="text-text-main mb-6 text-lg font-semibold">Démarrer un jeûne</h3>

                <form @submit.prevent="startFast" class="space-y-4">
                    <GlassSelect
                        v-model="selectedTypeValue"
                        label="Type de jeûne"
                        dusk="fasting-type-select"
                        :options="fastingTypes.map((t) => ({ value: t.value, label: t.label }))"
                        :error="startForm.errors.type"
                    />

                    <div>
                        <GlassInput
                            type="datetime-local"
                            v-model="startForm.start_time"
                            label="Début"
                            :error="startForm.errors.start_time"
                        />
                    </div>

                    <!-- The request adds a `base` error when a fast is already
                         running and the controller a `message` one; neither maps
                         to a field, so without this the rejection is invisible. -->
                    <p
                        v-if="Object.keys(startForm.errors).length"
                        class="text-accent-danger text-sm font-bold"
                        role="alert"
                        dusk="fasting-error"
                    >
                        {{ Object.values(startForm.errors)[0] }}
                    </p>

                    <GlassButton type="submit" variant="primary" :loading="startForm.processing" class="mt-4 w-full">
                        Commencer
                    </GlassButton>
                </form>
            </GlassCard>

            <!-- History Analytics Chart Section -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.1s">
                <div class="mb-4">
                    <h3 class="font-display text-text-main text-lg font-black uppercase italic">Durée des jeûnes</h3>
                    <p class="text-text-muted text-xs font-semibold">Historique récent (heures)</p>
                </div>

                <div v-if="history.data.filter((f) => f.end_time).length === 0" class="py-8 text-center">
                    <span class="material-symbols-outlined mb-2 text-4xl text-slate-200" aria-hidden="true"
                        >show_chart</span
                    >
                    <p class="text-text-muted text-sm font-medium">Pas assez de données pour afficher le graphique.</p>
                </div>
                <div v-else>
                    <FastingHistoryChart :data="history.data" />
                </div>
            </GlassCard>

            <!-- History Details Section -->
            <GlassCard>
                <h3 class="text-text-main mb-4 text-lg font-semibold">Historique détaillé</h3>
                <div v-if="history.data.length === 0" class="text-text-muted py-4 text-center">
                    Aucun historique de jeûne.
                </div>
                <div v-else class="space-y-3">
                    <div
                        v-for="fast in history.data"
                        :key="fast.id"
                        class="border-border bg-surface-card/50 flex items-center justify-between rounded-2xl border p-3 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-lg active:scale-[0.99]"
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
                            <!-- Icon-only and destructive: it had no name at all. -->
                            <button
                                type="button"
                                @click="deleteFast(fast.id)"
                                :aria-label="`Supprimer le jeûne du ${formatDate(fast.start_time)}`"
                                class="focus-visible:ring-electric-orange text-accent-danger hover:text-accent-danger/70 relative rounded-lg p-1 transition-colors before:absolute before:-inset-2.5 before:content-[''] focus-visible:ring-2 focus-visible:outline-none"
                            >
                                <span class="material-symbols-outlined text-lg" aria-hidden="true">delete</span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Paginated 10 to a page server-side, with nothing here to reach
                     page 2: every fast older than the tenth was unreachable. -->
                <nav
                    v-if="history.last_page > 1"
                    class="mt-6 flex items-center justify-center gap-4"
                    aria-label="Pagination de l'historique des jeûnes"
                >
                    <GlassButton
                        :disabled="!history.prev_page_url"
                        @click="goToPage(history.prev_page_url)"
                        aria-label="Page précédente"
                        dusk="fasting-prev"
                    >
                        <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
                    </GlassButton>

                    <span class="text-text-muted text-sm font-bold" aria-live="polite">
                        Page {{ history.current_page }} sur {{ history.last_page }}
                    </span>

                    <GlassButton
                        :disabled="!history.next_page_url"
                        @click="goToPage(history.next_page_url)"
                        aria-label="Page suivante"
                        dusk="fasting-next"
                    >
                        <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
                    </GlassButton>
                </nav>
            </GlassCard>
        </div>
    </AuthenticatedLayout>
</template>
