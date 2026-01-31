<template>
    <Head title="Suivi Hydratation" />

    <AuthenticatedLayout page-title="Suivi Hydratation" show-back back-route="tools.index">
        <div class="space-y-6">
            <!-- Header -->
            <header class="animate-fade-in">
                <h1
                    class="font-display text-text-main text-4xl leading-none font-black tracking-tighter uppercase italic"
                >
                    Suivi<br />
                    <span class="text-gradient from-blue-400 to-cyan-500">Hydratation</span>
                </h1>
                <p class="text-text-muted mt-2 text-sm font-semibold tracking-wider uppercase">Reste Hydraté</p>
            </header>

            <!-- Main Tracker Section -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.05s">
                <div class="flex flex-col items-center space-y-8 p-4">
                    <!-- Progress Circle/Tank -->
                    <div class="relative flex h-64 w-64 items-center justify-center">
                        <!-- Circular Progress SVG -->
                        <svg class="h-full w-full rotate-[-90deg]" viewBox="0 0 100 100">
                            <!-- Background Circle -->
                            <circle
                                cx="50"
                                cy="50"
                                r="45"
                                fill="transparent"
                                stroke="#e2e8f0"
                                stroke-width="8"
                                stroke-linecap="round"
                            />
                            <!-- Progress Circle -->
                            <circle
                                cx="50"
                                cy="50"
                                r="45"
                                fill="transparent"
                                stroke="url(#gradient)"
                                stroke-width="8"
                                stroke-linecap="round"
                                stroke-dasharray="283"
                                :stroke-dashoffset="dashOffset"
                                class="transition-all duration-1000 ease-out"
                            />
                            <defs>
                                <linearGradient id="gradient" x1="0%" y1="0%" x2="100%" y2="0%">
                                    <stop offset="0%" stop-color="#3b82f6" />
                                    <stop offset="100%" stop-color="#06b6d4" />
                                </linearGradient>
                            </defs>
                        </svg>

                        <!-- Center Text -->
                        <div class="absolute flex flex-col items-center text-center">
                            <span class="font-display text-text-main text-5xl font-black tracking-tighter italic">
                                {{ todayTotal }}
                            </span>
                            <span class="text-text-muted text-sm font-bold uppercase"> / {{ goal }} ml </span>
                            <span class="mt-2 text-sm font-bold text-blue-500"> {{ percentage }}% </span>
                        </div>
                    </div>

                    <!-- Quick Add Buttons -->
                    <div class="grid w-full grid-cols-3 gap-4">
                        <button
                            @click="addWater(250)"
                            :disabled="form.processing"
                            aria-label="Ajouter 250ml"
                            class="group flex flex-col items-center justify-center rounded-2xl border border-slate-200 bg-white py-4 transition-all hover:border-blue-300 hover:bg-blue-50 active:scale-95"
                        >
                            <span class="material-symbols-outlined mb-1 text-2xl text-blue-500">local_drink</span>
                            <span class="text-text-main text-xs font-bold">250ml</span>
                        </button>
                        <button
                            @click="addWater(500)"
                            :disabled="form.processing"
                            aria-label="Ajouter 500ml"
                            class="group flex flex-col items-center justify-center rounded-2xl border border-slate-200 bg-white py-4 transition-all hover:border-blue-300 hover:bg-blue-50 active:scale-95"
                        >
                            <span class="material-symbols-outlined mb-1 text-2xl text-blue-500">water_drop</span>
                            <span class="text-text-main text-xs font-bold">500ml</span>
                        </button>
                        <button
                            @click="addWater(1000)"
                            :disabled="form.processing"
                            aria-label="Ajouter 1L"
                            class="group flex flex-col items-center justify-center rounded-2xl border border-slate-200 bg-white py-4 transition-all hover:border-blue-300 hover:bg-blue-50 active:scale-95"
                        >
                            <span class="material-symbols-outlined mb-1 text-2xl text-blue-500">water_bottle</span>
                            <span class="text-text-main text-xs font-bold">1L</span>
                        </button>
                    </div>

                    <!-- Custom Input -->
                    <div class="flex w-full items-center gap-2">
                        <div class="relative grow">
                            <input
                                type="number"
                                v-model="customAmount"
                                placeholder="Quantité personnalisée"
                                class="font-display text-text-main h-12 w-full rounded-xl border border-slate-200 bg-white px-4 font-bold transition-all outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/20"
                            />
                            <span class="text-text-muted absolute top-1/2 right-4 -translate-y-1/2 text-xs font-bold"
                                >ML</span
                            >
                        </div>
                        <GlassButton
                            @click="addWater(customAmount)"
                            :disabled="!customAmount || form.processing"
                            class="h-12 !px-4"
                            variant="primary"
                        >
                            <span class="material-symbols-outlined">add</span>
                        </GlassButton>
                    </div>
                </div>
            </GlassCard>

            <!-- History Section -->
            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                <!-- Today's Logs -->
                <GlassCard class="animate-slide-up h-full" style="animation-delay: 0.1s">
                    <h2 class="font-display text-text-main mb-4 text-lg font-black uppercase italic">
                        Journal du jour
                    </h2>

                    <div v-if="logs.length === 0" class="py-8 text-center">
                        <span class="material-symbols-outlined mb-2 text-4xl text-slate-200">water_drop</span>
                        <p class="text-text-muted text-sm font-medium">Aucune consommation aujourd'hui.</p>
                    </div>

                    <div v-else class="max-h-[300px] space-y-3 overflow-y-auto pr-2">
                        <div
                            v-for="log in logs"
                            :key="log.id"
                            class="flex items-center justify-between rounded-xl border border-slate-100 bg-white p-3 transition-all hover:border-slate-200"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="flex h-10 w-10 items-center justify-center rounded-full bg-blue-50 text-blue-500"
                                >
                                    <span class="material-symbols-outlined text-xl">water_drop</span>
                                </div>
                                <div>
                                    <p class="text-text-main font-bold">{{ log.amount }} ml</p>
                                    <p class="text-text-muted text-xs">
                                        {{
                                            new Date(log.consumed_at).toLocaleTimeString([], {
                                                hour: '2-digit',
                                                minute: '2-digit',
                                            })
                                        }}
                                    </p>
                                </div>
                            </div>
                            <button
                                @click="deleteLog(log)"
                                :aria-label="'Supprimer l\'entrée de ' + log.amount + ' ml'"
                                class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition-colors hover:bg-red-50 hover:text-red-500"
                            >
                                <span class="material-symbols-outlined text-lg">delete</span>
                            </button>
                        </div>
                    </div>
                </GlassCard>

                <!-- Weekly History -->
                <GlassCard class="animate-slide-up h-full" style="animation-delay: 0.15s">
                    <h2 class="font-display text-text-main mb-4 text-lg font-black uppercase italic">
                        7 derniers jours
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
                                {{ day.total }} ml
                            </div>

                            <!-- Bar -->
                            <div
                                class="relative w-full overflow-hidden rounded-t-lg bg-slate-100 transition-all duration-500 group-hover:bg-blue-100"
                                :style="{ height: `${Math.min((day.total / goal) * 100, 100)}%` }"
                            >
                                <div
                                    class="absolute top-0 right-0 bottom-0 left-0 bg-blue-500/20 transition-colors group-hover:bg-blue-500/30"
                                ></div>
                                <!-- Fill based on goal cap? Actually visual height is mostly useful relative to goal -->
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
    todayTotal: {
        type: Number,
        required: true,
    },
    history: {
        type: Array,
        required: true,
    },
    goal: {
        type: Number,
        default: 2500,
    },
})

const customAmount = ref('')

const form = useForm({
    amount: '',
})

const percentage = computed(() => {
    return Math.min(Math.round((props.todayTotal / props.goal) * 100), 100)
})

const dashOffset = computed(() => {
    const circumference = 2 * Math.PI * 45 // r=45
    // 283 approx
    return 283 - (283 * percentage.value) / 100
})

const addWater = (amount) => {
    if (!amount) return

    form.amount = parseInt(amount)
    form.post(route('tools.water.store'), {
        preserveScroll: true,
        onSuccess: () => {
            customAmount.value = ''
            form.reset()
        },
    })
}

const deleteLog = (log) => {
    if (confirm('Supprimer cette entrée ?')) {
        router.delete(route('tools.water.destroy', { waterLog: log.id }), {
            preserveScroll: true,
        })
    }
}
</script>

<style scoped>
.text-gradient {
    @apply bg-gradient-to-r bg-clip-text text-transparent;
}
</style>
