<template>
    <Head title="Sleep Tracker" />

    <AuthenticatedLayout page-title="Sleep Tracker" show-back back-route="tools.index">
        <div class="space-y-6">
            <!-- Header -->
            <header class="animate-fade-in">
                <h1
                    class="font-display text-text-main text-4xl leading-none font-black tracking-tighter uppercase italic"
                >
                    Sleep<br />
                    <span class="text-gradient from-indigo-400 to-purple-500">Tracker</span>
                </h1>
                <p class="text-text-muted mt-2 text-sm font-semibold tracking-wider uppercase">Track Your Recovery</p>
            </header>

            <!-- Main Tracker Section -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.05s">
                <div class="flex flex-col space-y-6 p-4">
                    <!-- Form -->
                    <form @submit.prevent="submit" class="space-y-4">
                        <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                            <!-- Date -->
                            <div class="space-y-2">
                                <label class="text-text-muted text-xs font-bold uppercase">Date</label>
                                <input
                                    type="date"
                                    v-model="form.date"
                                    class="font-display text-text-main w-full rounded-xl border border-slate-200 bg-white px-4 py-3 font-bold transition-all outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                                />
                                <div v-if="form.errors.date" class="text-xs text-red-500">{{ form.errors.date }}</div>
                            </div>

                            <!-- Duration -->
                            <div class="space-y-2">
                                <label class="text-text-muted text-xs font-bold uppercase">Duration</label>
                                <div class="flex gap-2">
                                    <div class="relative w-full">
                                        <input
                                            type="number"
                                            v-model="hours"
                                            placeholder="Hrs"
                                            min="0"
                                            max="24"
                                            class="font-display text-text-main w-full rounded-xl border border-slate-200 bg-white px-4 py-3 font-bold transition-all outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                                        />
                                        <span
                                            class="text-text-muted absolute top-1/2 right-3 -translate-y-1/2 text-xs font-bold"
                                            >H</span
                                        >
                                    </div>
                                    <div class="relative w-full">
                                        <input
                                            type="number"
                                            v-model="minutes"
                                            placeholder="Min"
                                            min="0"
                                            max="59"
                                            class="font-display text-text-main w-full rounded-xl border border-slate-200 bg-white px-4 py-3 font-bold transition-all outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                                        />
                                        <span
                                            class="text-text-muted absolute top-1/2 right-3 -translate-y-1/2 text-xs font-bold"
                                            >M</span
                                        >
                                    </div>
                                </div>
                                <div v-if="form.errors.duration_minutes" class="text-xs text-red-500">
                                    {{ form.errors.duration_minutes }}
                                </div>
                            </div>
                        </div>

                        <!-- Quality -->
                        <div class="space-y-2">
                            <label class="text-text-muted text-xs font-bold uppercase">Quality</label>
                            <div class="flex gap-2">
                                <button
                                    v-for="i in 5"
                                    :key="i"
                                    type="button"
                                    @click="form.quality = i"
                                    class="flex h-12 flex-1 items-center justify-center rounded-xl border transition-all"
                                    :class="
                                        form.quality === i
                                            ? 'border-indigo-500 bg-indigo-500 text-white'
                                            : 'border-slate-200 bg-white text-slate-400 hover:border-indigo-300'
                                    "
                                >
                                    <span class="material-symbols-outlined" :class="{ filled: form.quality >= i }"
                                        >star</span
                                    >
                                </button>
                            </div>
                            <div v-if="form.errors.quality" class="text-xs text-red-500">{{ form.errors.quality }}</div>
                        </div>

                        <!-- Notes -->
                        <div class="space-y-2">
                            <label class="text-text-muted text-xs font-bold uppercase">Notes (Optional)</label>
                            <textarea
                                v-model="form.notes"
                                rows="2"
                                class="font-display text-text-main w-full resize-none rounded-xl border border-slate-200 bg-white px-4 py-3 font-bold transition-all outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-500/20"
                            ></textarea>
                        </div>

                        <GlassButton
                            type="submit"
                            :disabled="form.processing"
                            class="w-full justify-center"
                            variant="primary"
                        >
                            Log Sleep
                        </GlassButton>
                    </form>
                </div>
            </GlassCard>

            <!-- History Section -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <!-- Recent Logs -->
                <GlassCard class="animate-slide-up h-full" style="animation-delay: 0.1s">
                    <h2 class="font-display text-text-main mb-4 text-lg font-black uppercase italic">Recent Logs</h2>

                    <div v-if="logs.length === 0" class="py-8 text-center">
                        <span class="material-symbols-outlined mb-2 text-4xl text-slate-200">bed</span>
                        <p class="text-text-muted text-sm font-medium">No sleep logged recently.</p>
                    </div>

                    <div v-else class="max-h-[300px] space-y-3 overflow-y-auto pr-2">
                        <div
                            v-for="log in logs"
                            :key="log.id"
                            class="flex items-center justify-between rounded-xl border border-slate-100 bg-white p-3 transition-all hover:border-slate-200"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-50 text-indigo-500"
                                >
                                    <span class="material-symbols-outlined text-xl">bedtime</span>
                                </div>
                                <div>
                                    <p class="text-text-main font-bold">{{ formatDuration(log.duration_minutes) }}</p>
                                    <p class="text-text-muted text-xs">
                                        {{ new Date(log.date).toLocaleDateString() }}
                                    </p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <div class="flex text-yellow-400">
                                    <span
                                        class="material-symbols-outlined text-sm"
                                        v-for="i in log.quality"
                                        :key="i"
                                        :class="{ filled: true }"
                                        >star</span
                                    >
                                </div>
                                <button
                                    @click="deleteLog(log)"
                                    class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition-colors hover:bg-red-50 hover:text-red-500"
                                >
                                    <span class="material-symbols-outlined text-lg">delete</span>
                                </button>
                            </div>
                        </div>
                    </div>
                </GlassCard>

                <!-- Weekly History -->
                <GlassCard class="animate-slide-up h-full" style="animation-delay: 0.15s">
                    <h2 class="font-display text-text-main mb-4 text-lg font-black uppercase italic">
                        Last 7 Days (Hours)
                    </h2>

                    <div class="flex h-[300px] items-end justify-between gap-2 pt-4">
                        <div
                            v-for="day in history"
                            :key="day.date"
                            class="group relative flex flex-1 flex-col items-center justify-end"
                        >
                            <!-- Tooltip -->
                            <div
                                class="absolute -top-10 z-10 mb-2 rounded bg-slate-800 px-2 py-1 text-xs whitespace-nowrap text-white opacity-0 transition-opacity group-hover:opacity-100"
                            >
                                {{ formatDuration(day.duration) }} ({{ day.quality }}/5)
                            </div>

                            <!-- Bar -->
                            <div
                                class="relative w-full overflow-hidden rounded-t-lg bg-slate-100 transition-all duration-500 group-hover:bg-indigo-100"
                                :style="{ height: `${Math.min((day.duration / 600) * 100, 100)}%` }"
                            >
                                <div
                                    class="absolute top-0 right-0 bottom-0 left-0 bg-indigo-500/20 transition-colors group-hover:bg-indigo-500/30"
                                ></div>
                                <!-- Fill based on 10 hours max (600 min) -->
                            </div>

                            <span class="text-text-muted mt-2 w-full truncate text-center text-xs font-bold uppercase">
                                {{ day.day_name.substring(0, 3) }}
                            </span>
                        </div>
                    </div>
                </GlassCard>
            </div>
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'

const props = defineProps({
    logs: {
        type: Array,
        required: true,
    },
    history: {
        type: Array,
        required: true,
    },
    averageDuration: {
        type: Number,
        default: 0,
    },
})

const today = new Date().toISOString().split('T')[0]
const hours = ref('')
const minutes = ref('')

const form = useForm({
    date: today,
    duration_minutes: 0,
    quality: 3,
    notes: '',
})

const submit = () => {
    form.duration_minutes = (parseInt(hours.value) || 0) * 60 + (parseInt(minutes.value) || 0)

    if (form.duration_minutes === 0) {
        alert('Please enter a duration')
        return
    }

    form.post(route('tools.sleep.store'), {
        preserveScroll: true,
        onSuccess: () => {
            hours.value = ''
            minutes.value = ''
            form.reset('notes')
            form.quality = 3
        },
    })
}

const deleteLog = (log) => {
    if (confirm('Remove this log?')) {
        router.delete(route('tools.sleep.destroy', { sleepLog: log.id }), {
            preserveScroll: true,
        })
    }
}

const formatDuration = (minutes) => {
    const h = Math.floor(minutes / 60)
    const m = minutes % 60
    if (h > 0 && m > 0) return `${h}h ${m}m`
    if (h > 0) return `${h}h`
    return `${m}m`
}
</script>

<style scoped>
.text-gradient {
    @apply bg-gradient-to-r bg-clip-text text-transparent;
}
.material-symbols-outlined.filled {
    font-variation-settings:
        'FILL' 1,
        'wght' 400,
        'GRAD' 0,
        'opsz' 24;
}
</style>
