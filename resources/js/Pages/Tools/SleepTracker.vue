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
                    <span class="text-gradient from-violet-400 to-purple-500">Tracker</span>
                </h1>
                <p class="text-text-muted mt-2 text-sm font-semibold tracking-wider uppercase">
                    Monitor Recovery
                </p>
            </header>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
                <!-- Add Sleep Log Form -->
                <GlassCard class="animate-slide-up col-span-1" style="animation-delay: 0.05s">
                    <h2 class="font-display text-text-main mb-4 text-lg font-black uppercase italic">Add Sleep</h2>
                    <form @submit.prevent="submit" class="space-y-4">
                        <!-- Date -->
                        <div>
                            <InputLabel value="Date" />
                            <input
                                type="date"
                                v-model="form.date"
                                class="w-full rounded-xl border-slate-200 bg-white/50 px-4 py-2 font-bold text-text-main outline-none focus:border-violet-500 focus:ring-violet-500/20"
                            />
                            <InputError :message="form.errors.date" class="mt-1" />
                        </div>

                        <!-- Duration -->
                        <div>
                            <InputLabel value="Duration" />
                            <div class="flex gap-2">
                                <div class="relative w-full">
                                    <input
                                        type="number"
                                        v-model="hours"
                                        min="0"
                                        max="24"
                                        placeholder="Hours"
                                        class="w-full rounded-xl border-slate-200 bg-white/50 px-4 py-2 font-bold text-text-main outline-none focus:border-violet-500 focus:ring-violet-500/20"
                                    />
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-text-muted">h</span>
                                </div>
                                <div class="relative w-full">
                                    <input
                                        type="number"
                                        v-model="minutes"
                                        min="0"
                                        max="59"
                                        placeholder="Mins"
                                        class="w-full rounded-xl border-slate-200 bg-white/50 px-4 py-2 font-bold text-text-main outline-none focus:border-violet-500 focus:ring-violet-500/20"
                                    />
                                    <span class="absolute right-3 top-1/2 -translate-y-1/2 text-xs font-bold text-text-muted">m</span>
                                </div>
                            </div>
                            <InputError :message="form.errors.duration_minutes" class="mt-1" />
                        </div>

                        <!-- Quality -->
                        <div>
                            <InputLabel value="Quality" />
                            <div class="flex gap-2 mt-1">
                                <button
                                    v-for="i in 5"
                                    :key="i"
                                    type="button"
                                    @click="form.quality = i"
                                    class="text-2xl transition-transform hover:scale-110 focus:outline-none"
                                    :class="form.quality >= i ? 'text-yellow-400' : 'text-slate-300'"
                                >
                                    ★
                                </button>
                            </div>
                             <InputError :message="form.errors.quality" class="mt-1" />
                        </div>

                        <!-- Notes -->
                        <div>
                            <InputLabel value="Notes" />
                            <textarea
                                v-model="form.notes"
                                class="w-full rounded-xl border-slate-200 bg-white/50 px-4 py-2 font-bold text-text-main outline-none focus:border-violet-500 focus:ring-violet-500/20"
                                rows="2"
                                placeholder="How did you feel?"
                            ></textarea>
                            <InputError :message="form.errors.notes" class="mt-1" />
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
                </GlassCard>

                <!-- Stats & History Chart -->
                <div class="col-span-1 space-y-6 lg:col-span-2">
                    <!-- Average Stat -->
                    <div class="grid grid-cols-2 gap-4">
                        <GlassCard class="animate-slide-up flex flex-col items-center justify-center p-6" style="animation-delay: 0.1s">
                            <span class="text-text-muted text-xs font-bold uppercase">Average Sleep (30d)</span>
                            <span class="font-display text-text-main mt-2 text-3xl font-black italic">
                                {{ formatDuration(averageDuration) }}
                            </span>
                        </GlassCard>
                         <GlassCard class="animate-slide-up flex flex-col items-center justify-center p-6" style="animation-delay: 0.15s">
                            <span class="text-text-muted text-xs font-bold uppercase">Avg Quality (7d)</span>
                            <div class="mt-2 flex items-center gap-1">
                                <span class="font-display text-text-main text-3xl font-black italic">
                                    {{ averageQuality7d }}
                                </span>
                                <span class="text-yellow-400 text-xl">★</span>
                            </div>
                        </GlassCard>
                    </div>

                    <!-- Chart -->
                    <GlassCard class="animate-slide-up min-h-[300px]" style="animation-delay: 0.2s">
                        <h2 class="font-display text-text-main mb-4 text-lg font-black uppercase italic">Last 7 Days</h2>
                        <div class="flex h-[250px] items-end justify-between gap-2 pt-4">
                             <div
                                v-for="day in history"
                                :key="day.date"
                                class="group relative flex flex-1 flex-col items-center justify-end"
                            >
                                <!-- Tooltip -->
                                <div class="absolute -top-12 opacity-0 transition-opacity group-hover:opacity-100 bg-slate-800 text-white text-xs rounded px-2 py-1 mb-2 whitespace-nowrap z-10 flex flex-col items-center pointer-events-none">
                                    <span>{{ formatDuration(day.total) }}</span>
                                    <span class="text-yellow-400 text-[10px]">{{ day.quality ? '★'.repeat(Math.round(day.quality)) : '-' }}</span>
                                </div>

                                <!-- Bar -->
                                <div
                                    class="w-full rounded-t-lg transition-all duration-500 relative overflow-hidden"
                                    :class="day.total > 0 ? 'bg-violet-200 group-hover:bg-violet-300' : 'bg-slate-100'"
                                    :style="{ height: `${Math.min((day.total / 600) * 100, 100)}%` }"
                                >
                                     <!-- Gradient overlay -->
                                    <div class="absolute bottom-0 left-0 right-0 top-0 bg-violet-500/20 group-hover:bg-violet-500/30 transition-colors"></div>
                                </div>

                                <span class="text-text-muted mt-2 text-xs font-bold uppercase truncate w-full text-center">
                                    {{ day.day_name.substring(0, 3) }}
                                </span>
                            </div>
                        </div>
                    </GlassCard>
                </div>
            </div>

            <!-- Recent Logs List -->
             <GlassCard class="animate-slide-up" style="animation-delay: 0.25s">
                <h2 class="font-display text-text-main mb-4 text-lg font-black uppercase italic">Recent Logs</h2>
                <div v-if="logs.length === 0" class="py-8 text-center">
                        <span class="material-symbols-outlined mb-2 text-4xl text-slate-200">bed</span>
                    <p class="text-text-muted text-sm font-medium">No sleep logs found.</p>
                </div>
                 <div v-else class="space-y-3">
                    <div
                        v-for="log in logs"
                        :key="log.id"
                        class="flex items-center justify-between rounded-xl border border-slate-100 bg-white p-3 transition-all hover:border-slate-200"
                    >
                         <div class="flex items-center gap-4">
                            <div class="flex h-10 w-10 items-center justify-center rounded-full bg-violet-50 text-violet-500">
                                <span class="material-symbols-outlined text-xl">bedtime</span>
                            </div>
                            <div>
                                <p class="text-text-main font-bold">{{ new Date(log.date).toLocaleDateString(undefined, { timeZone: 'UTC', weekday: 'short', month: 'short', day: 'numeric' }) }}</p>
                                <div class="flex items-center gap-2 text-xs text-text-muted">
                                    <span>{{ formatDuration(log.duration_minutes) }}</span>
                                    <span v-if="log.quality" class="flex text-yellow-500">
                                         {{ '★'.repeat(log.quality) }}
                                    </span>
                                </div>
                            </div>
                        </div>

                         <div class="flex items-center gap-2">
                             <p v-if="log.notes" class="text-text-muted hidden text-xs italic sm:block mr-4 truncate max-w-[200px]">
                                {{ log.notes }}
                             </p>
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
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { ref, watch, computed } from 'vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'

const props = defineProps({
    logs: {
        type: Array,
        required: true,
    },
    history: {
        type: Array, // [{date, day_name, total, quality}]
        required: true,
    },
    averageDuration: {
        type: Number,
        default: 0,
    }
})

const hours = ref(8)
const minutes = ref(0)

const form = useForm({
    date: new Date().toISOString().split('T')[0],
    duration_minutes: 480,
    quality: 3,
    notes: '',
})

// Update form duration when inputs change
watch([hours, minutes], () => {
    form.duration_minutes = (hours.value * 60) + minutes.value
})

const submit = () => {
    form.post(route('tools.sleep.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('quality', 'notes')
            // keep date? maybe default to today again
            form.date = new Date().toISOString().split('T')[0]
            hours.value = 8
            minutes.value = 0
        },
    })
}

const deleteLog = (log) => {
    if (confirm('Delete this sleep log?')) {
        router.delete(route('tools.sleep.destroy', { sleepLog: log.id }), {
            preserveScroll: true,
        })
    }
}

const formatDuration = (mins) => {
    if (!mins) return '0h 0m'
    const h = Math.floor(mins / 60)
    const m = Math.round(mins % 60)
    return `${h}h ${m}m`
}

const averageQuality7d = computed(() => {
    // calculate from history
    const validDays = props.history.filter(d => d.quality > 0)
    if (validDays.length === 0) return '-'
    const sum = validDays.reduce((acc, curr) => acc + curr.quality, 0)
    return (sum / validDays.length).toFixed(1)
})
</script>

<style scoped>
.text-gradient {
    @apply bg-clip-text text-transparent bg-gradient-to-r;
}
</style>
