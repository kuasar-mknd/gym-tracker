<template>
    <Head title="Sleep Tracker" />

    <AuthenticatedLayout page-title="Sleep Tracker" show-back back-route="tools.index">
        <div class="space-y-6">
            <!-- Header -->
            <header class="animate-fade-in">
                <h1 class="font-display text-text-main text-4xl leading-none font-black tracking-tighter uppercase italic">
                    Sleep<br />
                    <span class="text-gradient from-indigo-400 to-purple-500">Tracker</span>
                </h1>
                <p class="text-text-muted mt-2 text-sm font-semibold tracking-wider uppercase">
                    Recover & Recharge
                </p>
            </header>

            <!-- Input Section -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.05s">
                <div class="p-4 space-y-6">
                    <h2 class="font-display text-text-main text-lg font-black uppercase italic">Log Sleep</h2>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="space-y-2">
                            <InputLabel value="Bed Time" />
                            <input
                                type="datetime-local"
                                v-model="form.started_at"
                                class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 font-bold text-text-main focus:border-indigo-500 focus:ring-indigo-500/20 outline-none transition-all focus:ring-2"
                            />
                            <InputError :message="form.errors.started_at" />
                        </div>

                        <div class="space-y-2">
                            <InputLabel value="Wake Time" />
                            <input
                                type="datetime-local"
                                v-model="form.ended_at"
                                class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 font-bold text-text-main focus:border-indigo-500 focus:ring-indigo-500/20 outline-none transition-all focus:ring-2"
                            />
                            <InputError :message="form.errors.ended_at" />
                        </div>
                    </div>

                    <div class="space-y-2">
                         <InputLabel value="Quality" />
                         <div class="flex gap-2">
                            <button
                                v-for="star in 5"
                                :key="star"
                                @click="form.quality = star"
                                type="button"
                                class="text-3xl transition-transform hover:scale-110 focus:outline-none"
                                :class="form.quality >= star ? 'text-yellow-400' : 'text-slate-200'"
                            >
                                ★
                            </button>
                         </div>
                         <InputError :message="form.errors.quality" />
                    </div>

                    <div class="space-y-2">
                        <InputLabel value="Notes" />
                        <textarea
                            v-model="form.notes"
                            rows="2"
                            class="w-full rounded-xl border border-slate-200 bg-white px-4 py-3 font-bold text-text-main focus:border-indigo-500 focus:ring-indigo-500/20 outline-none transition-all focus:ring-2"
                            placeholder="Dreams, interruptions, how you feel..."
                        ></textarea>
                         <InputError :message="form.errors.notes" />
                    </div>

                    <GlassButton
                        @click="submit"
                        :disabled="form.processing"
                        class="w-full justify-center"
                        variant="primary"
                    >
                        <span v-if="form.processing">Saving...</span>
                        <span v-else>Save Sleep Log</span>
                    </GlassButton>
                </div>
            </GlassCard>

             <!-- History & Logs Grid -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <!-- Weekly History -->
                <GlassCard class="animate-slide-up h-full" style="animation-delay: 0.1s">
                    <h2 class="font-display text-text-main mb-4 text-lg font-black uppercase italic">Last 7 Days</h2>

                    <div class="flex h-[200px] items-end justify-between gap-2 pt-4">
                        <div
                            v-for="day in history"
                            :key="day.date"
                            class="group relative flex flex-1 flex-col items-center justify-end"
                        >
                            <!-- Tooltip -->
                            <div class="absolute -top-10 opacity-0 transition-opacity group-hover:opacity-100 bg-slate-800 text-white text-xs rounded px-2 py-1 mb-2 whitespace-nowrap z-10">
                                {{ day.total_hours }} hrs
                            </div>
                             <!-- Max height based on 10 hours for bar scaling -->
                            <div
                                class="w-full rounded-t-lg bg-slate-100 transition-all duration-500 group-hover:bg-indigo-100 relative overflow-hidden"
                                :style="{ height: `${Math.min((day.total_hours / 10) * 100, 100)}%` }"
                            >
                                <div class="absolute bottom-0 left-0 right-0 top-0 bg-indigo-500/20 group-hover:bg-indigo-500/30 transition-colors"></div>
                            </div>
                            <span class="text-text-muted mt-2 text-xs font-bold uppercase truncate w-full text-center">
                                {{ day.day_name.substring(0, 3) }}
                            </span>
                        </div>
                    </div>
                </GlassCard>

                <!-- Recent Logs -->
                <GlassCard class="animate-slide-up h-full" style="animation-delay: 0.15s">
                    <h2 class="font-display text-text-main mb-4 text-lg font-black uppercase italic">Recent Logs</h2>

                    <div v-if="logs.length === 0" class="py-8 text-center">
                         <span class="material-symbols-outlined mb-2 text-4xl text-slate-200">bed</span>
                        <p class="text-text-muted text-sm font-medium">No sleep tracked yet.</p>
                    </div>

                    <div v-else class="max-h-[300px] space-y-3 overflow-y-auto pr-2">
                        <div
                            v-for="log in logs"
                            :key="log.id"
                            class="flex items-center justify-between rounded-xl border border-slate-100 bg-white p-3 transition-all hover:border-slate-200"
                        >
                            <div class="flex items-center gap-3">
                                <div class="flex h-10 w-10 items-center justify-center rounded-full bg-indigo-50 text-indigo-500">
                                    <span class="material-symbols-outlined text-xl">bedtime</span>
                                </div>
                                <div>
                                    <p class="text-text-main font-bold">{{ calculateDuration(log) }} hrs</p>
                                    <p class="text-text-muted text-xs">
                                        {{ formatDate(log.started_at) }} - {{ formatTime(log.ended_at) }}
                                    </p>
                                </div>
                            </div>
                             <div class="flex items-center gap-2">
                                <span v-if="log.quality" class="text-xs font-bold text-yellow-500 flex items-center">
                                    {{ log.quality }} <span class="text-[10px] ml-0.5">★</span>
                                </span>
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
        </div>
    </AuthenticatedLayout>
</template>

<script setup>
import { useForm, router, Head } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import InputLabel from '@/Components/InputLabel.vue'
import InputError from '@/Components/InputError.vue'

defineProps({
    logs: Array,
    history: Array,
    lastLog: Object,
})

const form = useForm({
    started_at: '',
    ended_at: '',
    quality: 3,
    notes: '',
})

// Initialize form with defaults
const initForm = () => {
    const end = new Date()
    end.setHours(7, 0, 0, 0)

    // If it's already past 7am, default to today's morning.
    // If it's earlier, maybe default to "today" morning as well.
    // Let's just default to [Yesterday 23:00] -> [Today 07:00]

    const start = new Date(end)
    start.setDate(start.getDate() - 1)
    start.setHours(23, 0, 0, 0)

    // Format for datetime-local: YYYY-MM-DDTHH:mm
    // Need to handle timezone offset to ensure local time is put into input
    const toLocalISO = (date) => {
        // Adjust for timezone offset
        const tzOffset = date.getTimezoneOffset() * 60000; // in ms
        const localISOTime = (new Date(date - tzOffset)).toISOString().slice(0, 16);
        return localISOTime;
    }

    form.started_at = toLocalISO(start)
    form.ended_at = toLocalISO(end)
    form.quality = 3
    form.notes = ''
}

initForm()

const submit = () => {
    form.post(route('tools.sleep.store'), {
        preserveScroll: true,
        onSuccess: () => {
            initForm()
        },
    })
}

const deleteLog = (log) => {
    if (confirm('Delete this sleep log?')) {
        router.delete(route('tools.sleep.destroy', log.id), {
            preserveScroll: true,
        })
    }
}

const calculateDuration = (log) => {
    const start = new Date(log.started_at)
    const end = new Date(log.ended_at)
    const diffMs = end - start
    return (diffMs / (1000 * 60 * 60)).toFixed(1)
}

const formatDate = (dateStr) => {
    return new Date(dateStr).toLocaleDateString([], { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })
}

const formatTime = (dateStr) => {
    return new Date(dateStr).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
}
</script>

<style scoped>
.text-gradient {
    @apply bg-clip-text text-transparent bg-gradient-to-r;
}
</style>
