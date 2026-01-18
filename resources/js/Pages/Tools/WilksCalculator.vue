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
                        <div class="inline-flex rounded-lg bg-slate-100 p-1">
                            <button
                                @click="form.unit = 'kg'"
                                class="rounded-md px-4 py-1 text-sm font-bold transition-all"
                                :class="form.unit === 'kg' ? 'bg-white shadow-sm text-text-main' : 'text-text-muted hover:text-text-main'"
                            >
                                KG
                            </button>
                            <button
                                @click="form.unit = 'lbs'"
                                class="rounded-md px-4 py-1 text-sm font-bold transition-all"
                                :class="form.unit === 'lbs' ? 'bg-white shadow-sm text-text-main' : 'text-text-muted hover:text-text-main'"
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
                                    class="flex h-16 items-center justify-center rounded-2xl border-2 transition-all"
                                    :class="form.gender === 'male' ? 'border-electric-orange bg-electric-orange/10 text-electric-orange' : 'border-slate-200 bg-white text-text-muted hover:border-slate-300'"
                                >
                                    <span class="font-display text-lg font-black uppercase">Homme</span>
                                </button>
                                <button
                                    @click="form.gender = 'female'"
                                    class="flex h-16 items-center justify-center rounded-2xl border-2 transition-all"
                                    :class="form.gender === 'female' ? 'border-hot-pink bg-hot-pink/10 text-hot-pink' : 'border-slate-200 bg-white text-text-muted hover:border-slate-300'"
                                >
                                    <span class="font-display text-lg font-black uppercase">Femme</span>
                                </button>
                            </div>
                        </div>

                        <!-- Inputs -->
                        <div class="space-y-4">
                            <div>
                                <label class="font-display-label mb-2 block text-text-muted">Poids de corps</label>
                                <div class="relative">
                                    <input
                                        type="number"
                                        v-model="form.body_weight"
                                        placeholder="80"
                                        step="0.1"
                                        class="h-14 w-full rounded-2xl border-2 border-slate-200 bg-white px-4 font-display text-xl font-bold text-text-main outline-none transition-all focus:border-electric-orange focus:ring-2 focus:ring-electric-orange/20"
                                    />
                                    <span class="absolute right-4 top-1/2 -translate-y-1/2 font-bold text-text-muted uppercase">{{ form.unit }}</span>
                                </div>
                            </div>
                            <div>
                                <label class="font-display-label mb-2 block text-text-muted">Total soulevé</label>
                                <div class="relative">
                                    <input
                                        type="number"
                                        v-model="form.lifted_weight"
                                        placeholder="400"
                                        step="0.5"
                                        class="h-14 w-full rounded-2xl border-2 border-slate-200 bg-white px-4 font-display text-xl font-bold text-text-main outline-none transition-all focus:border-electric-orange focus:ring-2 focus:ring-electric-orange/20"
                                    />
                                    <span class="absolute right-4 top-1/2 -translate-y-1/2 font-bold text-text-muted uppercase">{{ form.unit }}</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Result -->
                    <div class="mt-6 flex flex-col items-center justify-center rounded-3xl border border-slate-100 bg-slate-50 p-8 text-center">
                        <p class="text-sm font-bold uppercase tracking-wider text-text-muted">Ton Score Wilks</p>
                        <div class="mt-2 font-display text-6xl font-black italic tracking-tighter text-transparent bg-clip-text bg-gradient-to-r from-electric-orange to-hot-pink">
                            {{ calculatedScore }}
                        </div>

                        <div class="mt-6">
                            <GlassButton
                                @click="saveScore"
                                variant="primary"
                                :disabled="!isValid || form.processing"
                                class="min-w-[200px]"
                            >
                                Enregistrer
                            </GlassButton>
                        </div>
                    </div>
                </div>
            </GlassCard>

            <!-- History Section -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.1s">
                <div class="space-y-5">
                    <h2 class="font-display text-lg font-black uppercase italic text-text-main">
                        Historique
                    </h2>

                    <div v-if="history.length === 0" class="py-12 text-center">
                        <span class="material-symbols-outlined mb-3 text-6xl text-slate-200">history</span>
                        <p class="font-medium text-text-muted">Aucun historique.</p>
                        <p class="mt-1 text-sm text-text-muted/70">Calcule ton score pour commencer à suivre tes progrès.</p>
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="entry in history"
                            :key="entry.id"
                            class="group relative flex items-center justify-between rounded-2xl border border-slate-100 bg-white p-4 transition-all hover:border-slate-200 hover:shadow-sm"
                        >
                            <div class="flex items-center gap-4">
                                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-slate-50 text-xl font-bold text-text-main">
                                    {{ parseFloat(entry.score).toFixed(0) }}
                                </div>
                                <div>
                                    <p class="font-bold text-text-main">
                                        {{ parseFloat(entry.lifted_weight) }} {{ entry.unit }} / {{ parseFloat(entry.body_weight) }} {{ entry.unit }}
                                    </p>
                                    <p class="text-xs text-text-muted uppercase tracking-wider">
                                        {{ new Date(entry.created_at).toLocaleDateString() }}
                                    </p>
                                </div>
                            </div>

                            <button
                                @click="deleteEntry(entry)"
                                class="flex h-8 w-8 items-center justify-center rounded-full text-slate-400 hover:bg-red-50 hover:text-red-500 transition-colors"
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
        e = 7.01863e-06
        f = -1.291e-08
    } else {
        a = 594.31747775582
        b = -27.23842536447
        c = 0.82112226871
        d = -0.00930733913
        e = 4.731582e-05
        f = -9.054e-08
    }

    const denominator = a + b * weight + c * Math.pow(weight, 2) + d * Math.pow(weight, 3) + e * Math.pow(weight, 4) + f * Math.pow(weight, 5)
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
