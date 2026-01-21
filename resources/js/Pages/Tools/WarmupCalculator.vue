<template>
    <Head title="Calculateur d'Échauffement" />

    <AuthenticatedLayout page-title="Calculateur d'Échauffement" show-back back-route="tools.index">
        <div class="space-y-6">
            <!-- Header -->
            <header class="animate-fade-in">
                <h1
                    class="font-display text-text-main text-4xl leading-none font-black tracking-tighter uppercase italic"
                >
                    Calculateur<br />
                    <span class="text-gradient">d'Échauffement</span>
                </h1>
                <p class="text-text-muted mt-2 text-sm font-semibold tracking-wider uppercase">
                    Prépare ton corps intelligemment
                </p>
            </header>

            <!-- Calculator Section -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.05s">
                <div class="space-y-6">
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="font-display-label text-text-muted mb-2 block">Poids de travail</label>
                            <div class="relative">
                                <input
                                    type="number"
                                    v-model="targetWeight"
                                    placeholder="100"
                                    step="0.5"
                                    class="font-display text-text-main focus:border-electric-orange focus:ring-electric-orange/20 h-16 w-full rounded-2xl border-2 border-slate-200 bg-white px-4 text-center text-3xl font-black transition-all outline-none focus:ring-2"
                                />
                                <span class="text-text-muted absolute top-1/2 right-4 -translate-y-1/2 font-bold"
                                    >kg</span
                                >
                            </div>
                        </div>
                        <div>
                            <label class="font-display-label text-text-muted mb-2 block">Poids Barre</label>
                            <div class="relative">
                                <input
                                    type="number"
                                    v-model="form.bar_weight"
                                    placeholder="20"
                                    class="font-display text-text-main focus:border-electric-orange focus:ring-electric-orange/20 h-16 w-full rounded-2xl border-2 border-slate-200 bg-white px-4 text-center text-3xl font-black transition-all outline-none focus:ring-2"
                                />
                                <span class="text-text-muted absolute top-1/2 right-4 -translate-y-1/2 font-bold"
                                    >kg</span
                                >
                            </div>
                        </div>
                    </div>

                    <!-- Warmup Sets -->
                    <div class="mt-6 rounded-3xl border border-slate-100 bg-slate-50 p-6">
                        <h3 class="font-display text-text-main mb-4 text-lg font-black uppercase italic">
                            Séries d'échauffement
                        </h3>

                        <div class="space-y-3">
                            <div
                                v-for="(set, index) in calculatedSets"
                                :key="index"
                                class="flex items-center justify-between rounded-xl bg-white p-4 shadow-sm"
                            >
                                <div class="flex items-center gap-4">
                                    <div
                                        class="bg-electric-orange/10 text-electric-orange flex h-10 w-10 items-center justify-center rounded-full font-bold"
                                    >
                                        {{ index + 1 }}
                                    </div>
                                    <div>
                                        <p class="text-text-main font-bold">
                                            <span v-if="set.label">{{ set.label }}</span>
                                            <span v-else>{{ set.percent }}% du max</span>
                                        </p>
                                        <p class="text-text-muted text-xs">{{ set.reps }} répétitions</p>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <p class="font-display text-text-main text-2xl font-black">
                                        {{ set.weight
                                        }}<span class="text-text-muted ml-1 text-base font-normal">kg</span>
                                    </p>
                                    <p class="text-text-muted text-xs font-bold">{{ set.plateLoad }} / côté</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </GlassCard>

            <!-- Configuration Section -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.1s">
                <div class="space-y-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <h2 class="font-display text-text-main text-lg font-black uppercase italic">
                                Configuration
                            </h2>
                            <p class="text-text-muted mt-1 text-xs font-bold tracking-wider uppercase">
                                Personnaliser les paliers
                            </p>
                        </div>
                        <GlassButton @click="savePreferences" variant="primary" size="sm" :loading="form.processing">
                            Sauvegarder
                        </GlassButton>
                    </div>

                    <div class="space-y-4">
                        <div class="text-text-muted grid grid-cols-12 gap-2 text-xs font-bold tracking-wider uppercase">
                            <div class="col-span-3">Pourcentage</div>
                            <div class="col-span-3">Répétitions</div>
                            <div class="col-span-5">Label (optionnel)</div>
                            <div class="col-span-1"></div>
                        </div>

                        <div v-for="(step, index) in form.steps" :key="index" class="grid grid-cols-12 gap-2">
                            <div class="col-span-3">
                                <div class="relative">
                                    <input
                                        type="number"
                                        v-model="step.percent"
                                        class="focus:border-electric-orange w-full rounded-xl border border-slate-200 px-3 py-2 text-sm font-bold outline-none"
                                    />
                                    <span class="text-text-muted absolute top-1/2 right-2 -translate-y-1/2 text-xs"
                                        >%</span
                                    >
                                </div>
                            </div>
                            <div class="col-span-3">
                                <input
                                    type="number"
                                    v-model="step.reps"
                                    class="focus:border-electric-orange w-full rounded-xl border border-slate-200 px-3 py-2 text-sm font-bold outline-none"
                                />
                            </div>
                            <div class="col-span-5">
                                <input
                                    type="text"
                                    v-model="step.label"
                                    placeholder="ex: Barre vide"
                                    class="focus:border-electric-orange w-full rounded-xl border border-slate-200 px-3 py-2 text-sm outline-none"
                                />
                            </div>
                            <div class="col-span-1 flex items-center justify-center">
                                <button
                                    @click="removeStep(index)"
                                    class="text-slate-400 transition-colors hover:text-red-500"
                                    :disabled="form.steps.length <= 1"
                                >
                                    <span class="material-symbols-outlined text-lg">delete</span>
                                </button>
                            </div>
                        </div>

                        <GlassButton @click="addStep" variant="ghost" size="sm" icon="add" class="w-full">
                            Ajouter un palier
                        </GlassButton>
                    </div>

                    <div class="border-t border-slate-100 pt-4">
                        <label class="font-display-label text-text-muted mb-2 block">Arrondi (kg)</label>
                        <div class="flex gap-2">
                            <button
                                v-for="inc in [0.5, 1, 2.5, 5]"
                                :key="inc"
                                @click="form.rounding_increment = inc"
                                class="flex-1 rounded-xl border py-2 text-sm font-bold transition-all"
                                :class="
                                    form.rounding_increment === inc
                                        ? 'border-electric-orange bg-electric-orange/10 text-electric-orange'
                                        : 'text-text-muted border-slate-200 hover:border-slate-300'
                                "
                            >
                                {{ inc }}
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
import { Head, useForm } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'

const props = defineProps({
    preference: {
        type: Object,
        required: true,
    },
})

const targetWeight = ref(100)

const form = useForm({
    bar_weight: props.preference.bar_weight,
    rounding_increment: props.preference.rounding_increment,
    steps: props.preference.steps || [],
})

const addStep = () => {
    form.steps.push({ percent: 50, reps: 5, label: '' })
}

const removeStep = (index) => {
    if (form.steps.length > 1) {
        form.steps.splice(index, 1)
    }
}

const savePreferences = () => {
    form.post(route('tools.warmup.update'), {
        preserveScroll: true,
        onSuccess: () => {
            // Toast handled globally usually
        },
    })
}

// Round to nearest increment
const roundWeight = (weight, increment) => {
    return Math.round(weight / increment) * increment
}

const calculatedSets = computed(() => {
    return form.steps.map((step) => {
        let weight

        if (step.percent === 0) {
            weight = form.bar_weight
        } else {
            const rawWeight = targetWeight.value * (step.percent / 100)
            // Ensure we don't go below bar weight
            weight = Math.max(form.bar_weight, roundWeight(rawWeight, form.rounding_increment))
        }

        // Calculate plates per side
        const weightForPlates = Math.max(0, weight - form.bar_weight)
        const perSide = weightForPlates / 2
        const plateLoad = perSide > 0 ? `${perSide}kg` : 'Vide'

        return {
            ...step,
            weight: weight,
            plateLoad: plateLoad,
        }
    })
})
</script>
