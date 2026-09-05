<script setup>
/**
 * Le minuteur d'intervalles (Tabata, HIIT) : un onglet pour le faire tourner,
 * un autre pour ses préréglages. La machine à phases vit dans
 * `useMinuteurDIntervalles` ; la page garde les onglets et le formulaire.
 */
import { ref, computed } from 'vue'
import { Head, useForm, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { useConfirmation } from '@/composables/useConfirmation'
import { useMinuteurDIntervalles } from '@/composables/useMinuteurDIntervalles'

defineProps({
    timers: {
        type: Array,
        default: () => [],
    },
})

// State for tabs
const activeTab = ref('timer') // 'timer' | 'config'

// Form for creating/editing
const form = useForm({
    id: null,
    name: 'Tabata',
    work_seconds: 20,
    rest_seconds: 10,
    rounds: 8,
    warmup_seconds: 10,
})

const {
    timerConfig,
    status,
    currentRound,
    formattedTime,
    phaseColor,
    phaseBg,
    phaseLabel,
    charger,
    resetRunner,
    toggleTimer,
} = useMinuteurDIntervalles()

const isEditing = computed(() => !!form.id)

// --- Actions ---

const submitForm = () => {
    if (isEditing.value) {
        form.patch(route('tools.interval-timer.update', form.id), {
            onSuccess: () => {
                resetForm()
                activeTab.value = 'config' // Stay on config list
            },
        })
    } else {
        form.post(route('tools.interval-timer.store'), {
            onSuccess: () => {
                resetForm()
                activeTab.value = 'config'
            },
        })
    }
}

const resetForm = () => {
    form.reset()
    form.id = null
    form.name = 'Tabata'
    form.work_seconds = 20
    form.rest_seconds = 10
    form.rounds = 8
    form.warmup_seconds = 10
}

const editTimer = (timer) => {
    form.id = timer.id
    form.name = timer.name
    form.work_seconds = timer.work_seconds
    form.rest_seconds = timer.rest_seconds
    form.rounds = timer.rounds
    form.warmup_seconds = timer.warmup_seconds
    activeTab.value = 'config'
    // Scroll to top of form
    window.scrollTo({ top: 0, behavior: 'smooth' })
}

const {
    cible: minuteurASupprimer,
    ouvert: suppressionDemandee,
    demander: demanderSuppression,
    annuler: annulerSuppression,
    confirmer: confirmerSuppression,
} = useConfirmation((timer, termine) => {
    router.delete(route('tools.interval-timer.destroy', timer.id), { onFinish: termine })
})

const loadTimer = (timer) => {
    charger({
        name: timer.name,
        work: timer.work_seconds,
        rest: timer.rest_seconds,
        rounds: timer.rounds,
        warmup: timer.warmup_seconds || 0,
    })
    activeTab.value = 'timer'
}

const previewFromForm = () => {
    charger({
        name: form.name || 'Custom',
        work: form.work_seconds,
        rest: form.rest_seconds,
        rounds: form.rounds,
        warmup: form.warmup_seconds || 0,
    })
    activeTab.value = 'timer'
}
</script>

<template>
    <Head title="Minuteur d'Intervalle" />

    <AuthenticatedLayout page-title="Minuteur d'Intervalle" show-back back-route="tools.index">
        <template #header-actions>
            <!-- Mobile actions -->
        </template>

        <div class="space-y-6">
            <!-- Tabs -->
            <div class="glass-panel-light flex space-x-1 rounded-xl p-1">
                <button
                    v-for="tab in ['timer', 'config']"
                    :key="tab"
                    @click="activeTab = tab"
                    class="w-full rounded-lg py-2.5 text-sm leading-5 font-medium transition-all duration-200"
                    :class="[
                        activeTab === tab
                            ? 'text-text-main bg-surface-card shadow'
                            : 'text-text-muted hover:bg-surface-card/[0.12] hover:text-text-main',
                    ]"
                >
                    {{ tab === 'timer' ? 'Minuteur' : 'Préréglages' }}
                </button>
            </div>

            <!-- Timer Tab -->
            <div v-if="activeTab === 'timer'" class="space-y-6">
                <GlassCard
                    class="flex flex-col items-center justify-center border-2 px-4 py-12 transition-colors duration-500"
                    :class="phaseBg"
                >
                    <div class="mb-4 text-sm font-black tracking-[0.2em] uppercase" :class="phaseColor">
                        {{ phaseLabel }}
                    </div>

                    <div
                        class="font-display text-[6rem] leading-none font-black tracking-tighter tabular-nums"
                        :class="phaseColor"
                    >
                        {{ formattedTime }}
                    </div>

                    <div class="text-text-muted mt-8 flex items-center gap-2">
                        <span class="material-symbols-outlined text-sm" aria-hidden="true">repeat</span>
                        <span class="font-bold">{{ currentRound }}</span>
                        <span class="text-xs">/ {{ timerConfig.rounds }}</span>
                    </div>

                    <div class="text-text-muted mt-2 text-xs">
                        {{ timerConfig.name }}
                    </div>

                    <!-- Controls -->
                    <div class="mt-12 flex gap-4">
                        <button
                            @click="toggleTimer"
                            class="text-text-main bg-surface-card flex h-16 w-16 items-center justify-center rounded-full shadow-lg transition-transform hover:scale-110 active:scale-95"
                            :aria-label="status === 'running' ? 'Mettre en pause' : 'Démarrer'"
                        >
                            <span class="material-symbols-outlined text-3xl" aria-hidden="true">
                                {{ status === 'running' ? 'pause' : 'play_arrow' }}
                            </span>
                        </button>

                        <button
                            @click="resetRunner"
                            class="border-border bg-surface-card/50 text-text-muted flex h-16 w-16 items-center justify-center rounded-full border shadow-lg transition-transform hover:scale-110 active:scale-95"
                            aria-label="Réinitialiser"
                        >
                            <span class="material-symbols-outlined text-3xl" aria-hidden="true">restart_alt</span>
                        </button>
                    </div>
                </GlassCard>

                <!-- Legend/Info -->
                <div class="grid grid-cols-3 gap-4 text-center">
                    <div class="glass-panel p-3">
                        <div class="text-text-muted text-xs tracking-wider uppercase">Travail</div>
                        <div class="text-accent-primary-deep text-xl font-bold">{{ timerConfig.work }}s</div>
                    </div>
                    <div class="glass-panel p-3">
                        <div class="text-text-muted text-xs tracking-wider uppercase">Repos</div>
                        <div class="text-accent-state-deep text-xl font-bold">{{ timerConfig.rest }}s</div>
                    </div>
                    <div class="glass-panel p-3">
                        <div class="text-text-muted text-xs tracking-wider uppercase">Échauff.</div>
                        <div class="text-accent-info-deep text-xl font-bold">{{ timerConfig.warmup }}s</div>
                    </div>
                </div>
            </div>

            <!-- Config/Presets Tab -->
            <div v-else class="space-y-6">
                <!-- Form -->
                <GlassCard class="p-6">
                    <h3 class="text-text-main mb-4 text-lg font-bold">
                        {{ isEditing ? 'Modifier le minuteur' : 'Nouveau minuteur' }}
                    </h3>
                    <form @submit.prevent="submitForm" class="space-y-4">
                        <GlassInput
                            v-model="form.name"
                            label="Nom"
                            placeholder="ex. Tabata"
                            required
                            :error="form.errors.name"
                        />
                        <div class="grid grid-cols-2 gap-4">
                            <GlassInput
                                v-model.number="form.work_seconds"
                                type="number"
                                label="Travail (s)"
                                required
                                :error="form.errors.work_seconds"
                            />
                            <GlassInput
                                v-model.number="form.rest_seconds"
                                type="number"
                                label="Repos (s)"
                                required
                                :error="form.errors.rest_seconds"
                            />
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <GlassInput
                                v-model.number="form.rounds"
                                type="number"
                                label="Tours"
                                required
                                :error="form.errors.rounds"
                            />
                            <GlassInput
                                v-model.number="form.warmup_seconds"
                                type="number"
                                label="Échauffement (s)"
                                :error="form.errors.warmup_seconds"
                            />
                        </div>

                        <div class="mt-4 flex justify-end gap-2">
                            <GlassButton v-if="!isEditing" type="button" variant="secondary" @click="previewFromForm">
                                Lancer
                            </GlassButton>
                            <GlassButton v-if="isEditing" type="button" variant="secondary" @click="resetForm">
                                Annuler
                            </GlassButton>
                            <GlassButton variant="primary" type="submit" :loading="form.processing">
                                {{ isEditing ? 'Mettre à jour' : 'Enregistrer' }}
                            </GlassButton>
                        </div>
                    </form>
                </GlassCard>

                <!-- List of Timers -->
                <div class="space-y-4">
                    <h3 class="text-text-main px-2 text-lg font-bold">Mes Minuteurs</h3>
                    <div v-if="timers.length === 0" class="text-text-muted py-8 text-center">
                        Aucun minuteur enregistré.
                    </div>
                    <GlassCard
                        v-for="timer in timers"
                        :key="timer.id"
                        class="group relative flex items-center justify-between overflow-hidden p-4"
                    >
                        <div class="relative z-10 cursor-pointer" @click="loadTimer(timer)">
                            <h4 class="text-text-main group-hover:text-accent-primary-deep font-bold transition-colors">
                                {{ timer.name }}
                            </h4>
                            <p class="text-text-muted text-xs">
                                {{ timer.rounds }}x {{ timer.work_seconds }}s Travail / {{ timer.rest_seconds }}s Repos
                            </p>
                        </div>
                        <div class="relative z-10 flex items-center gap-2">
                            <button
                                @click="loadTimer(timer)"
                                class="text-text-muted hover:text-accent-primary-deep relative p-2 transition-colors before:absolute before:-inset-0.5 before:content-['']"
                                title="Charger & Lancer"
                                aria-label="Charger et lancer"
                            >
                                <span class="material-symbols-outlined" aria-hidden="true">play_circle</span>
                            </button>
                            <GlassIconButton
                                icon="edit"
                                label="Modifier le minuteur"
                                ton="info"
                                @click="editTimer(timer)"
                            />
                            <GlassIconButton
                                icon="delete"
                                label="Supprimer le minuteur"
                                ton="danger"
                                @click="demanderSuppression(timer)"
                            />
                        </div>
                    </GlassCard>
                </div>
            </div>
        </div>
        <ConfirmDialog
            :ouvert="suppressionDemandee"
            titre="Supprimer ce minuteur ?"
            :description="minuteurASupprimer ? `« ${minuteurASupprimer.name} » sera définitivement effacé.` : ''"
            @confirmer="confirmerSuppression"
            @annuler="annulerSuppression"
        />
    </AuthenticatedLayout>
</template>
