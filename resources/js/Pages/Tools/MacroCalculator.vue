<template>
    <Head title="Calculateur de Macros" />

    <AuthenticatedLayout page-title="Macros" show-back back-route="tools.index">
        <div class="space-y-6">
            <!-- Header -->
            <header class="animate-fade-in">
                <h1
                    class="font-display text-4xl font-black uppercase italic leading-none tracking-tighter text-text-main"
                >
                    Calculateur<br />
                    <span class="text-gradient">Macros</span>
                </h1>
                <p class="mt-2 text-sm font-semibold uppercase tracking-wider text-text-muted">
                    Estime tes besoins caloriques
                </p>
            </header>

            <!-- Calculator Section -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.05s">
                <div class="space-y-6">
                    <!-- Gender Selection -->
                    <div>
                        <label class="font-display-label mb-2 block text-text-muted">Sexe</label>
                        <div class="grid grid-cols-2 gap-3">
                            <button
                                @click="form.gender = 'male'"
                                class="flex h-16 items-center justify-center rounded-2xl border-2 transition-all"
                                :class="
                                    form.gender === 'male'
                                        ? 'border-electric-orange bg-electric-orange/10 text-electric-orange'
                                        : 'border-slate-200 bg-white text-text-muted hover:border-slate-300'
                                "
                            >
                                <span class="font-display text-lg font-black uppercase">Homme</span>
                            </button>
                            <button
                                @click="form.gender = 'female'"
                                class="flex h-16 items-center justify-center rounded-2xl border-2 transition-all"
                                :class="
                                    form.gender === 'female'
                                        ? 'border-hot-pink bg-hot-pink/10 text-hot-pink'
                                        : 'border-slate-200 bg-white text-text-muted hover:border-slate-300'
                                "
                            >
                                <span class="font-display text-lg font-black uppercase">Femme</span>
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <!-- Age -->
                        <div>
                            <label class="font-display-label mb-2 block text-text-muted">Age</label>
                            <input
                                type="number"
                                v-model="form.age"
                                placeholder="25"
                                class="h-14 w-full rounded-2xl border-2 border-slate-200 bg-white px-4 font-display text-xl font-bold text-text-main outline-none transition-all focus:border-electric-orange focus:ring-2 focus:ring-electric-orange/20"
                            />
                        </div>
                        <!-- Height -->
                        <div>
                            <label class="font-display-label mb-2 block text-text-muted">Taille (cm)</label>
                            <input
                                type="number"
                                v-model="form.height"
                                placeholder="175"
                                class="h-14 w-full rounded-2xl border-2 border-slate-200 bg-white px-4 font-display text-xl font-bold text-text-main outline-none transition-all focus:border-electric-orange focus:ring-2 focus:ring-electric-orange/20"
                            />
                        </div>
                        <!-- Weight -->
                        <div>
                            <label class="font-display-label mb-2 block text-text-muted">Poids (kg)</label>
                            <input
                                type="number"
                                v-model="form.weight"
                                placeholder="70"
                                class="h-14 w-full rounded-2xl border-2 border-slate-200 bg-white px-4 font-display text-xl font-bold text-text-main outline-none transition-all focus:border-electric-orange focus:ring-2 focus:ring-electric-orange/20"
                            />
                        </div>
                    </div>

                    <!-- Activity Level -->
                    <div>
                        <label class="font-display-label mb-2 block text-text-muted">Niveau d'activité</label>
                        <select
                            v-model="form.activity_level"
                            class="h-14 w-full rounded-2xl border-2 border-slate-200 bg-white px-4 font-display text-lg font-bold text-text-main outline-none transition-all focus:border-electric-orange focus:ring-2 focus:ring-electric-orange/20"
                        >
                            <option value="sedentary">Sédentaire (peu ou pas d'exercice)</option>
                            <option value="light">Légèrement actif (1-3 jours/semaine)</option>
                            <option value="moderate">Modérément actif (3-5 jours/semaine)</option>
                            <option value="very">Très actif (6-7 jours/semaine)</option>
                            <option value="extra">Extrêmement actif (travail physique)</option>
                        </select>
                    </div>

                    <!-- Goal -->
                    <div>
                        <label class="font-display-label mb-2 block text-text-muted">Objectif</label>
                        <div class="grid grid-cols-3 gap-3">
                            <button
                                v-for="goalOption in ['cut', 'maintain', 'bulk']"
                                :key="goalOption"
                                @click="form.goal = goalOption"
                                class="flex h-12 items-center justify-center rounded-xl border-2 transition-all"
                                :class="
                                    form.goal === goalOption
                                        ? 'border-electric-orange bg-electric-orange/10 text-electric-orange'
                                        : 'border-slate-200 bg-white text-text-muted hover:border-slate-300'
                                "
                            >
                                <span class="font-display font-bold uppercase">{{
                                    goalOption === 'cut' ? 'Sèche' : goalOption === 'maintain' ? 'Maintien' : 'Prise'
                                }}</span>
                            </button>
                        </div>
                    </div>

                    <!-- Result -->
                    <div
                        v-if="isValid"
                        class="mt-6 flex flex-col items-center justify-center space-y-4 rounded-3xl border border-slate-100 bg-slate-50 p-6 text-center"
                    >
                        <div>
                            <p class="text-sm font-bold uppercase tracking-wider text-text-muted">
                                Cibles Journalières
                            </p>
                            <div
                                class="mt-1 bg-gradient-to-r from-electric-orange to-hot-pink bg-clip-text font-display text-5xl font-black italic tracking-tighter text-transparent"
                            >
                                {{ calculatedResults.targetCalories }} kcal
                            </div>
                            <p class="text-xs font-semibold text-text-muted">TDEE: {{ calculatedResults.tdee }} kcal</p>
                        </div>

                        <div class="grid w-full grid-cols-3 gap-4 border-t border-slate-200 pt-4">
                            <div>
                                <p class="text-xs font-bold uppercase text-text-muted">Protéines</p>
                                <p class="font-display text-2xl font-black text-text-main">
                                    {{ calculatedResults.protein }}g
                                </p>
                            </div>
                            <div>
                                <p class="text-xs font-bold uppercase text-text-muted">Glucides</p>
                                <p class="font-display text-2xl font-black text-text-main">
                                    {{ calculatedResults.carbs }}g
                                </p>
                            </div>
                            <div>
                                <p class="text-xs font-bold uppercase text-text-muted">Lipides</p>
                                <p class="font-display text-2xl font-black text-text-main">
                                    {{ calculatedResults.fat }}g
                                </p>
                            </div>
                        </div>

                        <div class="mt-2 w-full pt-2">
                            <GlassButton
                                @click="saveCalculation"
                                variant="primary"
                                :disabled="form.processing"
                                class="w-full"
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
                    <h2 class="font-display text-lg font-black uppercase italic text-text-main">Historique</h2>

                    <div v-if="history.length === 0" class="py-12 text-center">
                        <span class="material-symbols-outlined mb-3 text-6xl text-slate-200">history</span>
                        <p class="font-medium text-text-muted">Aucun historique.</p>
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="entry in history"
                            :key="entry.id"
                            class="group relative flex flex-col justify-between rounded-2xl border border-slate-100 bg-white p-4 transition-all hover:border-slate-200 hover:shadow-sm sm:flex-row sm:items-center"
                        >
                            <div class="flex items-center gap-4">
                                <div
                                    class="flex h-12 w-16 items-center justify-center rounded-xl bg-slate-50 text-lg font-bold text-text-main"
                                >
                                    {{ entry.target_calories }}
                                </div>
                                <div>
                                    <p class="font-bold text-text-main">
                                        {{ entry.protein }}P / {{ entry.carbs }}C / {{ entry.fat }}L
                                    </p>
                                    <p class="text-xs uppercase tracking-wider text-text-muted">
                                        {{
                                            entry.goal === 'cut'
                                                ? 'Sèche'
                                                : entry.goal === 'maintain'
                                                  ? 'Maintien'
                                                  : 'Prise'
                                        }}
                                        • {{ new Date(entry.created_at).toLocaleDateString() }}
                                    </p>
                                </div>
                            </div>

                            <button
                                @click="deleteEntry(entry)"
                                class="absolute right-4 top-4 flex h-8 w-8 items-center justify-center rounded-full text-slate-400 transition-colors hover:bg-red-50 hover:text-red-500 sm:static"
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
    gender: 'male',
    age: '',
    height: '',
    weight: '',
    activity_level: 'moderate',
    goal: 'maintain',
})

const isValid = computed(() => {
    return form.age > 0 && form.height > 0 && form.weight > 0
})

const multipliers = {
    sedentary: 1.2,
    light: 1.375,
    moderate: 1.55,
    very: 1.725,
    extra: 1.9,
}

const calculatedResults = computed(() => {
    if (!isValid.value) return { tdee: 0, targetCalories: 0, protein: 0, fat: 0, carbs: 0 }

    const weight = parseFloat(form.weight)
    const height = parseFloat(form.height)
    const age = parseFloat(form.age)
    const activity = multipliers[form.activity_level]

    // BMR
    let bmr
    if (form.gender === 'male') {
        bmr = 10 * weight + 6.25 * height - 5 * age + 5
    } else {
        bmr = 10 * weight + 6.25 * height - 5 * age - 161
    }

    const tdee = Math.round(bmr * activity)

    let target = tdee
    if (form.goal === 'cut') target -= 500
    if (form.goal === 'bulk') target += 300

    if (form.gender === 'male' && target < 1500) target = 1500
    if (form.gender === 'female' && target < 1200) target = 1200

    // Macros
    const protein = Math.round(weight * 2)
    let fat = Math.round(weight * 0.9)

    let proteinCal = protein * 4
    let fatCal = fat * 9

    let remaining = target - (proteinCal + fatCal)

    if (remaining < 0) {
        fat = Math.max(30, Math.round((target - proteinCal) / 9))
        remaining = target - (proteinCal + fat * 9)
    }

    const carbs = Math.max(0, Math.round(remaining / 4))

    return {
        tdee,
        targetCalories: Math.round(target),
        protein,
        fat,
        carbs,
    }
})

const saveCalculation = () => {
    if (!isValid.value) return

    form.post(route('tools.macro-calculator.store'), {
        preserveScroll: true,
        onSuccess: () => {
            // Optional: reset form or show success message
        },
    })
}

const deleteEntry = (entry) => {
    if (confirm('Supprimer ce calcul ?')) {
        router.delete(route('tools.macro-calculator.destroy', { macroCalculation: entry.id }), {
            preserveScroll: true,
        })
    }
}
</script>
