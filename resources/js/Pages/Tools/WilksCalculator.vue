<template>
    <Head title="Calculateur Wilks" />

    <AuthenticatedLayout page-title="Calculateur Wilks" show-back back-route="tools.index">
        <div class="space-y-6">
            <!-- Header -->
            <header class="animate-fade-in">
                <h1
                    class="font-display text-text-main text-4xl leading-none font-black tracking-tighter uppercase italic"
                >
                    Calculateur<br />
                    <span class="text-gradient">Wilks</span>
                </h1>
                <p class="text-text-muted mt-2 text-sm font-semibold tracking-wider uppercase">
                    Compare ta force relative
                </p>
            </header>

            <!-- Calculator Section -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.05s">
                <div class="space-y-6">
                    <!-- Unit Selection -->
                    <div class="flex justify-center">
                        <div class="inline-flex rounded-xl bg-white/10 p-1 backdrop-blur-md border border-white/20">
                            <button
                                @click="form.unit = 'kg'"
                                class="rounded-lg px-4 py-1 text-sm font-bold transition-all"
                                :class="
                                    form.unit === 'kg'
                                        ? 'text-text-main bg-white/40 shadow-sm backdrop-blur-sm'
                                        : 'text-text-muted hover:text-text-main hover:bg-white/10'
                                "
                            >
                                KG
                            </button>
                            <button
                                @click="form.unit = 'lbs'"
                                class="rounded-lg px-4 py-1 text-sm font-bold transition-all"
                                :class="
                                    form.unit === 'lbs'
                                        ? 'text-text-main bg-white/40 shadow-sm backdrop-blur-sm'
                                        : 'text-text-muted hover:text-text-main hover:bg-white/10'
                                "
                            >
                                LBS
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        <!-- Gender Selection -->
                        <div>
                            <label class="font-display-label text-text-muted mb-2 block">Sexe</label>
                            <div class="grid grid-cols-2 gap-3">
                                <button
                                    @click="form.gender = 'male'"
                                    class="flex h-16 items-center justify-center rounded-2xl border transition-all backdrop-blur-md"
                                    :class="
                                        form.gender === 'male'
                                            ? 'border-electric-orange bg-electric-orange/10 text-electric-orange'
                                            : 'text-text-muted border-white/20 bg-white/10 hover:bg-white/20 hover:border-white/30'
                                    "
                                >
                                    <span class="font-display text-lg font-black uppercase">Homme</span>
                                </button>
                                <button
                                    @click="form.gender = 'female'"
                                    class="flex h-16 items-center justify-center rounded-2xl border transition-all backdrop-blur-md"
                                    :class="
                                        form.gender === 'female'
                                            ? 'border-hot-pink bg-hot-pink/10 text-hot-pink'
                                            : 'text-text-muted border-white/20 bg-white/10 hover:bg-white/20 hover:border-white/30'
                                    "
                                >
                                    <span class="font-display text-lg font-black uppercase">Femme</span>
                                </button>
                            </div>
                        </div>

                        <!-- Inputs -->
                        <div class="space-y-4">
                            <div>
                                <label class="font-display-label text-text-muted mb-2 block">Poids de corps</label>
                                <div class="relative">
                                    <input
                                        type="number"
                                        v-model="form.body_weight"
                                        placeholder="80"
                                        step="0.1"
                                        class="font-display text-text-main focus:border-electric-orange focus:ring-electric-orange/20 h-14 w-full rounded-2xl border border-white/20 bg-white/10 px-4 text-xl font-bold backdrop-blur-md transition-all outline-none focus:bg-white/20 focus:ring-2 placeholder-text-muted/50"
                                    />
                                    <span
                                        class="text-text-muted absolute top-1/2 right-4 -translate-y-1/2 font-bold uppercase"
                                        >{{ form.unit }}</span
                                    >
                                </div>
                            </div>
                            <div>
                                <label class="font-display-label text-text-muted mb-2 block">Total soulevé</label>
                                <div class="relative">
                                    <input
                                        type="number"
                                        v-model="form.lifted_weight"
                                        placeholder="400"
                                        step="0.5"
                                        class="font-display text-text-main focus:border-electric-orange focus:ring-electric-orange/20 h-14 w-full rounded-2xl border border-white/20 bg-white/10 px-4 text-xl font-bold backdrop-blur-md transition-all outline-none focus:bg-white/20 focus:ring-2 placeholder-text-muted/50"
                                    />
                                    <span
                                        class="text-text-muted absolute top-1/2 right-4 -translate-y-1/2 font-bold uppercase"
                                        >{{ form.unit }}</span
                                    >
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Result -->
                    <div
                        class="mt-6 flex flex-col items-center justify-center rounded-3xl border border-white/20 bg-white/10 backdrop-blur-md p-8 text-center"
                    >
                        <p class="text-text-muted text-sm font-bold tracking-wider uppercase">Ton Score Wilks</p>
                        <div
                            class="from-electric-orange to-hot-pink font-display mt-2 bg-linear-to-r bg-clip-text text-6xl font-black tracking-tighter text-transparent italic"
                        >
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
                    <h2 class="font-display text-text-main text-lg font-black uppercase italic">Historique</h2>

                    <div v-if="history.length === 0" class="py-12 text-center">
                        <span class="material-symbols-outlined mb-3 text-6xl text-slate-200">history</span>
                        <p class="text-text-muted font-medium">Aucun historique.</p>
                        <p class="text-text-muted/70 mt-1 text-sm">
                            Calcule ton score pour commencer à suivre tes progrès.
                        </p>
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="entry in history"
                            :key="entry.id"
                            class="group relative flex items-center justify-between rounded-2xl border border-white/20 bg-white/10 backdrop-blur-md p-4 transition-all hover:bg-white/20 hover:shadow-sm"
                        >
                            <div class="flex items-center gap-4">
                                <div
                                    class="text-text-main flex h-12 w-12 items-center justify-center rounded-xl bg-white/20 text-xl font-bold border border-white/10"
                                >
                                    {{ parseFloat(entry.score).toFixed(0) }}
                                </div>
                                <div>
                                    <p class="text-text-main font-bold">
                                        {{ parseFloat(entry.lifted_weight) }} {{ entry.unit }} /
                                        {{ parseFloat(entry.body_weight) }} {{ entry.unit }}
                                    </p>
                                    <p class="text-text-muted text-xs tracking-wider uppercase">
                                        {{ new Date(entry.created_at).toLocaleDateString() }}
                                    </p>
                                </div>
                            </div>

                            <button
                                @click="deleteEntry(entry)"
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
    if (confirm('Voulez-vous supprimer ce score ?')) {
        router.delete(route('tools.wilks.destroy', { wilksScore: entry.id }), {
            preserveScroll: true,
        })
    }
}
</script>
