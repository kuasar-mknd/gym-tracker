<template>
    <Head title="Calculateur de Macros" />

    <AuthenticatedLayout page-title="Calculateur de Macros" show-back back-route="tools.index">
        <div class="space-y-6">
            <!-- Header -->
            <header class="animate-fade-in">
                <h1
                    class="font-display text-text-main text-4xl leading-none font-black tracking-tighter uppercase italic"
                >
                    Calculateur<br />
                    <span class="text-gradient">Macros</span>
                </h1>
                <p class="text-text-muted mt-2 text-sm font-semibold tracking-wider uppercase">
                    Estime tes besoins caloriques
                </p>
            </header>

            <!-- Calculator Section -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.05s">
                <div class="space-y-6">
                    <!-- Gender Selection -->
                    <div>
                        <label class="font-display-label text-text-muted mb-2 block">Sexe</label>
                        <div class="grid grid-cols-2 gap-3">
                            <button
                                @click="form.gender = 'male'"
                                :aria-pressed="form.gender === 'male'"
                                class="focus-visible:ring-electric-orange flex h-16 items-center justify-center rounded-2xl border backdrop-blur-md transition-all focus-visible:ring-2 focus-visible:outline-none"
                                :class="
                                    form.gender === 'male'
                                        ? 'border-electric-orange bg-electric-orange/20 text-electric-orange shadow-[0_0_15px_rgba(255,85,0,0.3)]'
                                        : 'text-text-muted hover:text-text-main border-slate-200 bg-white/50 hover:border-slate-300 hover:bg-white/80 dark:border-slate-700 dark:bg-slate-800/50 dark:hover:border-slate-600 dark:hover:bg-slate-800'
                                "
                            >
                                <span class="font-display text-lg font-black uppercase">Homme</span>
                            </button>
                            <button
                                @click="form.gender = 'female'"
                                :aria-pressed="form.gender === 'female'"
                                class="focus-visible:ring-hot-pink flex h-16 items-center justify-center rounded-2xl border backdrop-blur-md transition-all focus-visible:ring-2 focus-visible:outline-none"
                                :class="
                                    form.gender === 'female'
                                        ? 'border-hot-pink bg-hot-pink/20 text-hot-pink shadow-[0_0_15px_rgba(236,72,153,0.3)]'
                                        : 'text-text-muted hover:text-text-main border-slate-200 bg-white/50 hover:border-slate-300 hover:bg-white/80 dark:border-slate-700 dark:bg-slate-800/50 dark:hover:border-slate-600 dark:hover:bg-slate-800'
                                "
                            >
                                <span class="font-display text-lg font-black uppercase">Femme</span>
                            </button>
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
                        <!-- Age -->
                        <GlassInput type="number" v-model="form.age" label="Age" placeholder="25" size="lg" />
                        <!-- Height -->
                        <GlassInput
                            type="number"
                            v-model="form.height"
                            label="Taille (cm)"
                            placeholder="175"
                            size="lg"
                        />
                        <!-- Weight -->
                        <GlassInput type="number" v-model="form.weight" label="Poids (kg)" placeholder="70" size="lg" />
                    </div>

                    <!-- Activity Level -->
                    <GlassSelect
                        v-model="form.activity_level"
                        label="Niveau d'activité"
                        :options="activityOptions"
                        size="lg"
                    />

                    <!-- Goal -->
                    <div>
                        <label class="font-display-label text-text-muted mb-2 block">Objectif</label>
                        <div class="grid grid-cols-3 gap-3">
                            <button
                                v-for="goalOption in ['cut', 'maintain', 'bulk']"
                                :key="goalOption"
                                @click="form.goal = goalOption"
                                :aria-pressed="form.goal === goalOption"
                                class="focus-visible:ring-electric-orange flex h-12 items-center justify-center rounded-xl border backdrop-blur-md transition-all focus-visible:ring-2 focus-visible:outline-none"
                                :class="
                                    form.goal === goalOption
                                        ? 'border-electric-orange bg-electric-orange/20 text-electric-orange shadow-[0_0_15px_rgba(255,85,0,0.3)]'
                                        : 'text-text-muted hover:text-text-main border-slate-200 bg-white/50 hover:border-slate-300 hover:bg-white/80 dark:border-slate-700 dark:bg-slate-800/50 dark:hover:border-slate-600 dark:hover:bg-slate-800'
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
                        class="mt-6 flex flex-col items-center justify-center space-y-4 rounded-3xl border border-slate-200 bg-white/50 p-6 text-center dark:border-slate-700 dark:bg-slate-800/30"
                    >
                        <div>
                            <p class="text-text-muted text-sm font-bold tracking-wider uppercase">
                                Cibles Journalières
                            </p>
                            <div
                                class="from-electric-orange to-hot-pink font-display mt-1 bg-linear-to-r bg-clip-text text-5xl font-black tracking-tighter text-transparent italic"
                            >
                                {{ calculatedResults.targetCalories }} kcal
                            </div>
                            <p class="text-text-muted text-xs font-semibold">TDEE: {{ calculatedResults.tdee }} kcal</p>
                        </div>

                        <div class="grid w-full grid-cols-3 gap-4 border-t border-slate-200 pt-4 dark:border-slate-700">
                            <div>
                                <p class="text-text-muted text-xs font-bold uppercase">Protéines</p>
                                <p class="font-display text-text-main text-2xl font-black">
                                    {{ calculatedResults.protein }}g
                                </p>
                            </div>
                            <div>
                                <p class="text-text-muted text-xs font-bold uppercase">Glucides</p>
                                <p class="font-display text-text-main text-2xl font-black">
                                    {{ calculatedResults.carbs }}g
                                </p>
                            </div>
                            <div>
                                <p class="text-text-muted text-xs font-bold uppercase">Lipides</p>
                                <p class="font-display text-text-main text-2xl font-black">
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

            <!-- Trends Chart -->
            <GlassCard v-if="history.length > 1" class="animate-slide-up" style="animation-delay: 0.08s">
                <div class="mb-4">
                    <h3 class="font-display text-text-main text-lg font-black uppercase italic">Tendances</h3>
                    <p class="text-text-muted text-xs font-semibold">Évolution de vos objectifs</p>
                </div>
                <MacroHistoryChart :data="history" />
            </GlassCard>

            <!-- History Section -->
            <GlassCard class="animate-slide-up" style="animation-delay: 0.1s">
                <div class="space-y-5">
                    <h2 class="font-display text-text-main text-lg font-black uppercase italic">Historique</h2>

                    <div v-if="history.length === 0" class="py-12 text-center">
                        <span class="material-symbols-outlined mb-3 text-6xl text-slate-200">history</span>
                        <p class="text-text-muted font-medium">Aucun historique.</p>
                    </div>

                    <div v-else class="space-y-3">
                        <div
                            v-for="entry in history"
                            :key="entry.id"
                            class="group relative flex flex-col justify-between rounded-2xl border border-slate-200 bg-white/50 p-4 transition-all hover:bg-white/80 hover:shadow-lg sm:flex-row sm:items-center dark:border-slate-700 dark:bg-slate-800/30 dark:hover:bg-slate-800/50"
                        >
                            <div class="flex items-center gap-4">
                                <div
                                    class="text-text-main flex h-12 w-16 items-center justify-center rounded-xl bg-slate-100 text-lg font-bold dark:bg-slate-700"
                                >
                                    {{ entry.target_calories }}
                                </div>
                                <div>
                                    <p class="text-text-main font-bold">
                                        {{ entry.protein }}P / {{ entry.carbs }}C / {{ entry.fat }}L
                                    </p>
                                    <p class="text-text-muted text-xs tracking-wider uppercase">
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
                                type="button"
                                aria-label="Supprimer l'entrée"
                                title="Supprimer l'entrée"
                                class="text-text-muted absolute top-4 right-4 flex h-8 w-8 items-center justify-center rounded-full transition-colors hover:bg-red-500/20 hover:text-red-500 sm:static"
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
import { ref, computed, defineAsyncComponent } from 'vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassSelect from '@/Components/UI/GlassSelect.vue'

const MacroHistoryChart = defineAsyncComponent(() => import('@/Components/Stats/MacroHistoryChart.vue'))

const props = defineProps({
    history: {
        type: Array,
        required: true,
    },
})

const activityOptions = [
    { value: 'sedentary', label: "Sédentaire (peu ou pas d'exercice)" },
    { value: 'light', label: 'Légèrement actif (1-3 jours/semaine)' },
    { value: 'moderate', label: 'Modérément actif (3-5 jours/semaine)' },
    { value: 'very', label: 'Très actif (6-7 jours/semaine)' },
    { value: 'extra', label: 'Extrêmement actif (travail physique)' },
]

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
    if (confirm('Voulez-vous supprimer ce calcul ?')) {
        router.delete(route('tools.macro-calculator.destroy', { macroCalculation: entry.id }), {
            preserveScroll: true,
        })
    }
}
</script>
