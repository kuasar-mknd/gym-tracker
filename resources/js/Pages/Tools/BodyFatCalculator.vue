<template>
    <Head title="Calculateur de Masse Grasse" />

    <AuthenticatedLayout page-title="Calculateur de Masse Grasse" show-back back-route="tools.index">
        <div class="space-y-6">
            <!-- Header -->
            <header class="animate-fade-in">
                <h1
                    class="font-display text-text-main text-4xl leading-none font-black tracking-tighter uppercase italic"
                >
                    Calculateur<br />
                    <span class="text-gradient">de Masse Grasse</span>
                </h1>
                <p class="text-text-muted mt-2 text-sm font-semibold tracking-wider uppercase">
                    Méthode US Navy
                </p>
            </header>

            <!-- Calculator Section -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.05s">
                <div class="space-y-6">
                    <!-- Gender Switch -->
                    <div class="flex justify-center">
                        <div class="bg-slate-100 p-1 rounded-xl flex">
                            <button
                                @click="gender = 'male'"
                                class="px-6 py-2 rounded-lg text-sm font-bold transition-all"
                                :class="gender === 'male' ? 'bg-white text-electric-orange shadow-sm' : 'text-text-muted hover:text-text-main'"
                            >
                                Homme
                            </button>
                            <button
                                @click="gender = 'female'"
                                class="px-6 py-2 rounded-lg text-sm font-bold transition-all"
                                :class="gender === 'female' ? 'bg-white text-electric-orange shadow-sm' : 'text-text-muted hover:text-text-main'"
                            >
                                Femme
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="font-display-label text-text-muted mb-2 block">Taille (cm)</label>
                            <GlassInput type="number" v-model="height" placeholder="180" />
                        </div>
                        <div>
                            <label class="font-display-label text-text-muted mb-2 block">Poids (kg)</label>
                            <GlassInput type="number" v-model="weight" placeholder="80" />
                        </div>
                        <div>
                            <label class="font-display-label text-text-muted mb-2 block">Tour de Cou (cm)</label>
                            <GlassInput type="number" v-model="neck" placeholder="40" />
                        </div>
                        <div>
                            <label class="font-display-label text-text-muted mb-2 block">Tour de Taille (cm)</label>
                            <GlassInput type="number" v-model="waist" placeholder="85" />
                        </div>
                        <div v-if="gender === 'female'">
                            <label class="font-display-label text-text-muted mb-2 block">Tour de Hanche (cm)</label>
                            <GlassInput type="number" v-model="hip" placeholder="95" />
                        </div>
                    </div>

                    <!-- Result Display -->
                    <div v-if="bodyFat !== null" class="mt-8 text-center animate-fade-in">
                        <p class="text-text-muted text-sm uppercase font-bold tracking-wider">Masse Grasse Estimée</p>
                        <div class="mt-2 font-display text-6xl font-black text-text-main">
                            {{ bodyFat }}<span class="text-2xl text-electric-orange">%</span>
                        </div>
                        <p class="mt-4 text-sm text-text-muted max-w-md mx-auto">
                            Basé sur la formule de l'US Navy. Cette estimation peut varier par rapport aux méthodes cliniques.
                        </p>

                        <div class="mt-8">
                            <GlassButton
                                @click="saveResults"
                                variant="primary"
                                size="lg"
                                :loading="processing"
                                icon="save"
                            >
                                Enregistrer mes mesures
                            </GlassButton>
                            <p class="mt-2 text-xs text-text-muted">
                                Sauvegarde le poids, le % de gras et les mensurations.
                            </p>
                        </div>
                    </div>
                     <div v-else class="mt-8 text-center text-text-muted italic">
                        Remplissez tous les champs pour voir le résultat.
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
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'

const gender = ref('male')
const height = ref('')
const weight = ref('')
const neck = ref('')
const waist = ref('')
const hip = ref('')
const processing = ref(false)

const bodyFat = computed(() => {
    const h = parseFloat(height.value)
    const w = parseFloat(weight.value) // Not used in formula but needed for context/saving
    const n = parseFloat(neck.value)
    const waistVal = parseFloat(waist.value)
    const hipVal = parseFloat(hip.value)

    if (!h || !n || !waistVal) return null
    if (gender.value === 'female' && !hipVal) return null

    let bf = 0
    // US Navy Method
    // Male: 495 / (1.0324 - 0.19077 * log10(waist - neck) + 0.15456 * log10(height)) - 450
    // Female: 495 / (1.29579 - 0.35004 * log10(waist + hip - neck) + 0.22100 * log10(height)) - 450
    // Note: Log10 in JS is Math.log10()

    try {
        if (gender.value === 'male') {
            if (waistVal <= n) return null // Invalid input
            bf = 495 / (1.0324 - 0.19077 * Math.log10(waistVal - n) + 0.15456 * Math.log10(h)) - 450
        } else {
             if (waistVal + hipVal <= n) return null // Invalid input
            bf = 495 / (1.29579 - 0.35004 * Math.log10(waistVal + hipVal - n) + 0.22100 * Math.log10(h)) - 450
        }
    } catch (e) {
        return null
    }

    return bf > 0 ? bf.toFixed(1) : null
})

const saveResults = () => {
    if (processing.value || !bodyFat.value) return
    processing.value = true

    const today = new Date().toISOString().split('T')[0]

    const parts = [
        { part: 'neck', value: neck.value },
        { part: 'waist', value: waist.value },
    ]
    if (gender.value === 'female') {
        parts.push({ part: 'hips', value: hip.value })
    }

    router.post(route('body-measurements.store'), {
        weight: weight.value,
        body_fat: bodyFat.value,
        measured_at: today,
        parts: parts
    }, {
        onFinish: () => {
            processing.value = false
        },
        onSuccess: () => {
            // Success feedback is handled by global toast or redirect
            // Ideally we reset, but user might want to see what they saved.
        }
    })
}
</script>
