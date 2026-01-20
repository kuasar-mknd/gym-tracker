<template>
    <Head title="Calculateur Wilks" />

    <AuthenticatedLayout page-title="Wilks" show-back back-route="tools.index">
        <div class="space-y-6">
            <!-- Header -->
            <header class="animate-fade-in">
                <h1
                    class="font-display text-4xl font-black uppercase italic leading-none tracking-tighter text-text-main"
                >
                    Calculateur<br />
                    <span class="text-gradient">Wilks</span>
                </h1>
                <p class="mt-2 text-sm font-semibold uppercase tracking-wider text-text-muted">
                    Compare ta force relative
                </p>
            </header>

            <!-- Calculator Section -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.05s">
                <div class="space-y-6">
                    <!-- Unit Selection -->
                    <div class="flex justify-center">
                        <div class="inline-flex rounded-xl border border-white/20 bg-white/10 p-1 backdrop-blur-md">
                            <button
                                @click="form.unit = 'kg'"
                                class="rounded-lg px-6 py-2 text-sm font-bold transition-all duration-300"
                                :class="
                                    form.unit === 'kg'
                                        ? 'scale-105 bg-white text-text-main shadow-lg'
                                        : 'text-text-muted hover:bg-white/10 hover:text-text-main'
                                "
                            >
                                KG
                            </button>
                            <button
                                @click="form.unit = 'lbs'"
                                class="rounded-lg px-6 py-2 text-sm font-bold transition-all duration-300"
                                :class="
                                    form.unit === 'lbs'
                                        ? 'scale-105 bg-white text-text-main shadow-lg'
                                        : 'text-text-muted hover:bg-white/10 hover:text-text-main'
                                "
                            >
                                LBS
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <!-- Gender Selection -->
                        <div>
                            <label class="font-display-label mb-2 block text-text-muted">Sexe</label>
                            <div class="grid grid-cols-2 gap-3">
                                <button
                                    @click="form.gender = 'male'"
                                    class="group relative flex h-20 items-center justify-center overflow-hidden rounded-2xl border transition-all duration-300 hover:scale-[1.02]"
                                    :class="
                                        form.gender === 'male'
                                            ? 'border-electric-orange bg-electric-orange/10 shadow-[0_0_20px_rgba(255,85,0,0.15)]'
                                            : 'border-white/20 bg-white/5 hover:bg-white/10'
                                    "
                                >
                                    <div class="relative z-10 flex flex-col items-center gap-1">
                                        <span
                                            class="material-symbols-outlined"
                                            :class="form.gender === 'male' ? 'text-electric-orange' : 'text-text-muted'"
                                            >male</span
                                        >
                                        <span
                                            class="font-display text-sm font-black uppercase"
                                            :class="form.gender === 'male' ? 'text-electric-orange' : 'text-text-muted'"
                                            >Homme</span
                                        >
                                    </div>
                                    <div
                                        class="absolute inset-0 bg-gradient-to-br from-electric-orange/20 to-transparent opacity-0 transition-opacity duration-300"
                                        :class="{ 'opacity-100': form.gender === 'male' }"
                                    ></div>
                                </button>
                                <button
                                    @click="form.gender = 'female'"
                                    class="group relative flex h-20 items-center justify-center overflow-hidden rounded-2xl border transition-all duration-300 hover:scale-[1.02]"
                                    :class="
                                        form.gender === 'female'
                                            ? 'border-hot-pink bg-hot-pink/10 shadow-[0_0_20px_rgba(255,0,128,0.15)]'
                                            : 'border-white/20 bg-white/5 hover:bg-white/10'
                                    "
                                >
                                    <div class="relative z-10 flex flex-col items-center gap-1">
                                        <span
                                            class="material-symbols-outlined"
                                            :class="form.gender === 'female' ? 'text-hot-pink' : 'text-text-muted'"
                                            >female</span
                                        >
                                        <span
                                            class="font-display text-sm font-black uppercase"
                                            :class="form.gender === 'female' ? 'text-hot-pink' : 'text-text-muted'"
                                            >Femme</span
                                        >
                                    </div>
                                    <div
                                        class="absolute inset-0 bg-gradient-to-br from-hot-pink/20 to-transparent opacity-0 transition-opacity duration-300"
                                        :class="{ 'opacity-100': form.gender === 'female' }"
                                    ></div>
                                </button>
                            </div>
                        </div>

                        <!-- Inputs -->
                        <div class="space-y-4">
                            <div>
                                <div class="relative">
                                    <GlassInput
                                        type="number"
                                        label="Poids de corps"
                                        v-model="form.body_weight"
                                        placeholder="80"
                                        step="0.1"
                                    />
                                    <span
                                        class="absolute right-4 top-[42px] text-xs font-bold uppercase text-text-muted"
                                        >{{ form.unit }}</span
                                    >
                                </div>
                            </div>
                            <div>
                                <div class="relative">
                                    <GlassInput
                                        type="number"
                                        label="Total soulevé"
                                        v-model="form.lifted_weight"
                                        placeholder="400"
                                        step="0.5"
                                    />
                                    <span
                                        class="absolute right-4 top-[42px] text-xs font-bold uppercase text-text-muted"
                                        >{{ form.unit }}</span
                                    >
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Result -->
                    <div class="mt-6">
                        <GlassCard
                            variant="iridescent"
                            padding="p-8"
                            class="group relative overflow-hidden text-center"
                        >
                            <!-- Background Glow -->
                            <div
                                class="absolute inset-0 bg-gradient-to-br from-electric-orange/10 via-transparent to-hot-pink/10 opacity-50 blur-xl transition-opacity duration-500 group-hover:opacity-80"
                            ></div>

                            <div class="relative z-10">
                                <p class="text-xs font-black uppercase tracking-[0.2em] text-text-muted">
                                    Ton Score Wilks
                                </p>
                                <div
                                    class="mt-4 transform bg-gradient-to-r from-electric-orange to-hot-pink bg-clip-text font-display text-7xl font-black italic tracking-tighter text-transparent drop-shadow-sm transition-transform duration-500 hover:scale-105"
                                >
                                    {{ calculatedScore }}
                                </div>

                                <div class="mt-8 flex justify-center">
                                    <GlassButton
                                        @click="saveScore"
                                        variant="primary"
                                        :disabled="!isValid || form.processing"
                                        class="min-w-[200px] shadow-glow-orange"
                                    >
                                        Enregistrer le score
                                    </GlassButton>
                                </div>
                            </div>
                        </GlassCard>
                    </div>
                </div>
            </GlassCard>

            <!-- History Section -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.1s">
                <div class="space-y-5">
                    <h2 class="pl-2 font-display text-lg font-black uppercase italic text-text-main">Historique</h2>

                    <div
                        v-if="history.length === 0"
                        class="rounded-2xl border border-white/10 bg-white/5 py-12 text-center"
                    >
                        <span class="material-symbols-outlined mb-3 text-6xl text-white/20">history</span>
                        <p class="font-medium text-text-muted">Aucun historique.</p>
                        <p class="mt-1 text-sm text-text-muted/70">
                            Calcule ton score pour commencer à suivre tes progrès.
                        </p>
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="entry in history"
                            :key="entry.id"
                            class="group relative flex items-center justify-between rounded-2xl border border-white/20 bg-white/10 p-4 backdrop-blur-md transition-all duration-300 hover:-translate-y-1 hover:border-white/30 hover:bg-white/20 hover:shadow-lg"
                        >
                            <div class="flex items-center gap-4">
                                <div
                                    class="flex h-12 w-12 items-center justify-center rounded-xl border border-white/20 bg-gradient-to-br from-white/20 to-white/5 text-xl font-bold text-text-main shadow-inner"
                                >
                                    {{ parseFloat(entry.score).toFixed(0) }}
                                </div>
                                <div>
                                    <p class="font-bold text-text-main">
                                        {{ parseFloat(entry.lifted_weight) }}
                                        <span class="text-xs text-text-muted">{{ entry.unit }}</span>
                                        <span class="mx-1 text-text-muted">/</span>
                                        {{ parseFloat(entry.body_weight) }}
                                        <span class="text-xs text-text-muted">{{ entry.unit }}</span>
                                    </p>
                                    <p class="flex items-center gap-1 text-xs uppercase tracking-wider text-text-muted">
                                        <span class="material-symbols-outlined text-[10px]">calendar_today</span>
                                        {{ new Date(entry.created_at).toLocaleDateString() }}
                                    </p>
                                </div>
                            </div>

                            <button
                                @click="deleteEntry(entry)"
                                class="flex h-9 w-9 items-center justify-center rounded-xl text-slate-400 transition-all duration-300 hover:scale-110 hover:bg-red-500/20 hover:text-red-500 active:scale-95"
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
import { ref, computed } from 'vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'

const props = defineProps({
    history: {
        type: Array,
        required: true,
    },
})

const form = useForm({
    body_weight: '',
    lifted_weight: '',
    gender: 'male',
    unit: 'kg',
})

const isValid = computed(() => {
    return form.body_weight > 0 && form.lifted_weight > 0
})

const calculateWilks = (bw, lifted, gender, unit) => {
    if (!bw || !lifted) return 0

    // Convert to KG if needed
    let weight = parseFloat(bw)
    let total = parseFloat(lifted)

    if (unit === 'lbs') {
        weight = weight / 2.20462
        total = total / 2.20462
    }

    let a, b, c, d, e, f

    if (gender === 'male') {
        a = -216.0475144
        b = 16.2606339
        c = -0.002388645
        d = -0.00113732
        e = 7.01863e-6
        f = -1.291e-8
    } else {
        a = 594.31747775582
        b = -27.23842536447
        c = 0.82112226871
        d = -0.00930733913
        e = 4.731582e-5
        f = -9.054e-8
    }

    const denominator =
        a +
        b * weight +
        c * Math.pow(weight, 2) +
        d * Math.pow(weight, 3) +
        e * Math.pow(weight, 4) +
        f * Math.pow(weight, 5)
    const coeff = 500 / denominator

    return (total * coeff).toFixed(2)
}

const calculatedScore = computed(() => {
    return calculateWilks(form.body_weight, form.lifted_weight, form.gender, form.unit)
})

const saveScore = () => {
    if (!isValid.value) return

    form.post(route('tools.wilks.store'), {
        preserveScroll: true,
        onSuccess: () => {
            // Keep the form values so user can see what they just saved
        },
    })
}

const deleteEntry = (entry) => {
    if (confirm('Supprimer ce score ?')) {
        router.delete(route('tools.wilks.destroy', { wilksScore: entry.id }), {
            preserveScroll: true,
        })
    }
}
</script>
