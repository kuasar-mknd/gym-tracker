<script setup>
/**
 * Suivi de jeûne : le jeûne en cours avec son compteur, le démarrage d'un jeûne par type, et l'historique.
 *
 * @prop {Object} activeFast - Le jeûne en cours, ou null : { id, type, start_time, target_duration_minutes }.
 * @prop {Object} history - Les jeûnes passés, paginés.
 */

import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link, useForm, router } from '@inertiajs/vue3'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIconButton from '@/Components/UI/GlassIconButton.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassInput from '@/Components/UI/GlassInput.vue'
import GlassSelect from '@/Components/UI/GlassSelect.vue'
import { ref, computed, onUnmounted, watch } from 'vue'
import { graphiqueAsynchrone } from '@/Utils/graphiqueAsynchrone'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { useConfirmation } from '@/composables/useConfirmation'
import { dateCourte, formatToLocalISO, formatToUTC, heureCourte } from '@/Utils/date'

const FastingHistoryChart = graphiqueAsynchrone(() => import('@/Components/Stats/FastingHistoryChart.vue'))
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'

const props = defineProps({
    activeFast: Object,
    history: Object,
})

// `type` est une donnée que le serveur valide (StoreFastRequest), pas un morceau du libellé : « 24h » et « 36h »,
// tirés du libellé, étaient refusés sans qu'aucune erreur ne s'affiche.
const fastingTypes = [
    { value: '16:8', label: '16:8 (Leangains)', hours: 16 },
    { value: '18:6', label: '18:6', hours: 18 },
    { value: '20:4', label: '20:4 (Warrior)', hours: 20 },
    { value: '24:0', label: '24h (OMAD)', hours: 24 },
    { value: '36:0', label: '36h (Monk)', hours: 36 },
]

// Un <select> ne rend qu'une chaîne : lier les objets donnait value="[object Object]" et tuait « Commencer ».
const selectedTypeValue = ref(fastingTypes[0].value)

const selectedType = computed(
    () => fastingTypes.find((type) => type.value === selectedTypeValue.value) ?? fastingTypes[0],
)

const startForm = useForm({
    start_time: '',
    target_duration_minutes: 16 * 60,
    type: '16:8',
})
let debutPrerempli = ''

const endForm = useForm({
    end_time: '',
    status: 'completed',
})
const finDemandee = ref(false)

// Timer Logic
const elapsedSeconds = ref(0)
const progressPercentage = ref(0)
const timerInterval = ref(null)

// Format seconds into HH:mm:ss
const formatDuration = (seconds) => {
    const h = Math.floor(seconds / 3600)
    const m = Math.floor((seconds % 3600) / 60)
    const s = Math.floor(seconds % 60)
    return `${h.toString().padStart(2, '0')}:${m.toString().padStart(2, '0')}:${s.toString().padStart(2, '0')}`
}

const formattedElapsedTime = computed(() => {
    if (!props.activeFast) return '00:00:00'
    return formatDuration(elapsedSeconds.value)
})

const remainingTime = computed(() => {
    if (!props.activeFast) return ''
    const targetSeconds = props.activeFast.target_duration_minutes * 60
    const remaining = targetSeconds - elapsedSeconds.value
    if (remaining <= 0) return 'Objectif atteint !'
    return formatDuration(remaining) + ' restants'
})

/**
 * Les secondes pleines entre deux instants, tronquées vers zéro et jamais négatives (#2198) : avant un début à venir,
 * le compteur reste à 00:00:00. Un instant servi porte son fuseau, et se lit par `new Date()` (#2189).
 */
const secondesEntre = (debut, fin) => Math.max(0, Math.trunc((new Date(fin) - new Date(debut)) / 1000))

const updateTimer = () => {
    if (!props.activeFast) return
    const diff = secondesEntre(props.activeFast.start_time, new Date())
    elapsedSeconds.value = diff

    const targetSeconds = props.activeFast.target_duration_minutes * 60
    progressPercentage.value = Math.min((diff / targetSeconds) * 100, 100)
}

/**
 * Arme et désarme l'horloge avec le jeûne : `router.post` garde l'état, la page n'est pas remontée, et `onMounted`
 * seul laissait le compteur figé à 00:00:00 après « Commencer ».
 */
watch(
    () => props.activeFast,
    (fast) => {
        clearInterval(timerInterval.value)
        timerInterval.value = null

        if (!fast) {
            finDemandee.value = false
            elapsedSeconds.value = 0
            progressPercentage.value = 0
            debutPrerempli = formatToLocalISO(new Date())
            startForm.start_time = debutPrerempli

            return
        }

        updateTimer()
        timerInterval.value = setInterval(updateTimer, 1000)
    },
    { immediate: true },
)

onUnmounted(() => {
    if (timerInterval.value) clearInterval(timerInterval.value)
})

/** L'heure de l'appareil au départ, celle du geste : l'âge est nul, et le serveur place le geste sur son horloge. */
const auDepart = (geste) => ({ 'X-Heure-De-L-Appareil': geste })

/**
 * Le champ laissé tel quel dit « maintenant » : le début part à l'instant du geste, avec l'heure de l'appareil au
 * départ (#2198) ; changé, à l'instant qu'il désigne. La fin part à l'instant présent, avec elle aussi. Sans
 * décalage, le serveur lisait une heure de l'application (#2111, `.ai/rules/js.md`).
 */
const startFast = () => {
    const geste = startForm.start_time === debutPrerempli ? new Date().toISOString() : null
    startForm
        .transform((data) => ({
            ...data,
            start_time: geste ?? formatToUTC(data.start_time),
            target_duration_minutes: selectedType.value.hours * 60,
            type: selectedType.value.value,
        }))
        .post(route('tools.fasting.store'), { headers: geste ? auDepart(geste) : {} })
}

const refusDeLaFin = computed(() => Object.values(endForm.errors).find(Boolean))

const endFast = () => {
    endForm.end_time = new Date().toISOString()
    endForm.patch(route('tools.fasting.update', props.activeFast.id), {
        headers: auDepart(endForm.end_time),
        onFinish: () => (finDemandee.value = false),
    })
}

/** Le refus qu'aucun champ ne porte (`base`, un jeûne en cours) ; celui du début ou du type se dit sous lui (#2198). */
const refus = computed(() => Object.values({ ...startForm.errors, start_time: null, type: null }).find(Boolean))

const goToPage = (url) => {
    if (url) {
        router.visit(url)
    }
}

const {
    ouvert: suppressionDemandee,
    demander: demanderSuppression,
    annuler: annulerSuppression,
    confirmer: confirmerSuppression,
} = useConfirmation((id, termine) => {
    router.delete(route('tools.fasting.destroy', id), { onFinish: termine })
})

/** `10/03/2026 08:00`, à l'heure de l'appareil, comme les autres pages (`.ai/rules/js.md`). */
const formatDate = (instant) => `${dateCourte(new Date(instant))} ${heureCourte(new Date(instant))}`
const formatHistoryDuration = (start, end) => formatDuration(secondesEntre(start, end))
</script>

<template>
    <Head title="Suivi de Jeûne" />

    <AuthenticatedLayout page-title="Suivi de Jeûne" show-back back-route="tools.index" largeur="etroite">
        <template #header>
            <div class="flex items-center gap-4">
                <Link
                    :href="route('tools.index')"
                    class="text-text-muted hover:text-accent-primary-deep border-border bg-surface-card flex size-11 shrink-0 items-center justify-center rounded-full border shadow-sm transition-colors"
                    aria-label="Retour aux outils"
                >
                    <GlassIcon name="arrow_back" />
                </Link>
                <h1 class="titre-carte">Suivi de Jeûne</h1>
            </div>
        </template>

        <div class="space-y-6">
            <div v-if="activeFast" class="flex justify-center">
                <GlassCard class="relative flex w-full max-w-md flex-col items-center overflow-hidden p-8 text-center">
                    <div class="relative mb-6 h-64 w-64">
                        <svg class="h-full w-full -rotate-90 transform" viewBox="0 0 100 100">
                            <circle
                                cx="50"
                                cy="50"
                                r="45"
                                fill="none"
                                stroke="rgb(from var(--color-surface-card) r g b / 0.1)"
                                stroke-width="8"
                            />
                            <circle
                                cx="50"
                                cy="50"
                                r="45"
                                fill="none"
                                stroke="url(#gradient)"
                                stroke-width="8"
                                :stroke-dasharray="2 * Math.PI * 45"
                                :stroke-dashoffset="2 * Math.PI * 45 * (1 - progressPercentage / 100)"
                                stroke-linecap="round"
                                class="transition duration-1000 ease-linear"
                            />
                            <defs>
                                <linearGradient id="gradient" x1="0%" y1="0%" x2="100%" y2="0%">
                                    <stop offset="0%" style="stop-color: var(--color-accent-primary)" />
                                    <stop offset="100%" style="stop-color: var(--color-accent-danger)" />
                                </linearGradient>
                            </defs>
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-text-main font-mono text-4xl font-bold">{{ formattedElapsedTime }}</span>
                            <span class="text-text-muted mt-2 text-sm">{{ remainingTime }}</span>
                        </div>
                    </div>

                    <div class="mb-6 space-y-2">
                        <p class="text-text-main font-medium">Débuté : {{ formatDate(activeFast.start_time) }}</p>
                        <p class="text-text-muted text-sm">Objectif : {{ activeFast.type }}</p>
                    </div>

                    <GlassButton
                        @click="finDemandee = true"
                        variant="primary"
                        :loading="endForm.processing"
                        class="w-full"
                    >
                        Terminer le jeûne
                    </GlassButton>
                    <p
                        v-if="refusDeLaFin"
                        class="text-accent-danger-deep mt-3 text-sm font-bold"
                        role="alert"
                        dusk="fasting-end-error"
                    >
                        {{ refusDeLaFin }}
                    </p>
                </GlassCard>
            </div>

            <GlassCard v-else class="mx-auto max-w-md">
                <h3 class="titre-carte mb-6">Démarrer un jeûne</h3>

                <form @submit.prevent="startFast" class="space-y-4">
                    <GlassSelect
                        v-model="selectedTypeValue"
                        label="Type de jeûne"
                        dusk="fasting-type-select"
                        :options="fastingTypes.map((t) => ({ value: t.value, label: t.label }))"
                        :error="startForm.errors.type"
                    />

                    <div>
                        <GlassInput
                            type="datetime-local"
                            v-model="startForm.start_time"
                            label="Début"
                            :error="startForm.errors.start_time"
                        />
                    </div>

                    <p v-if="refus" class="text-accent-danger-deep text-sm font-bold" role="alert" dusk="fasting-error">
                        {{ refus }}
                    </p>

                    <GlassButton type="submit" variant="primary" :loading="startForm.processing" class="mt-4 w-full">
                        Commencer
                    </GlassButton>
                </form>
            </GlassCard>

            <!-- History Analytics Chart Section -->
            <GlassCard class="stagger-2 animate-slide-up">
                <div class="mb-4">
                    <h3 class="titre-carte">Durée des jeûnes</h3>
                    <p class="text-text-muted text-xs font-semibold">Historique récent (heures)</p>
                </div>

                <GlassEmptyState
                    v-if="history.data.filter((f) => f.end_time).length === 0"
                    taille="ligne"
                    icon="show_chart"
                    title="Pas assez de données pour afficher le graphique"
                    class="h-64"
                />
                <div v-else>
                    <FastingHistoryChart :data="history.data" />
                </div>
            </GlassCard>

            <!-- History Details Section -->
            <GlassCard>
                <h3 class="titre-carte mb-4">Historique détaillé</h3>
                <GlassEmptyState
                    v-if="history.data.length === 0"
                    taille="ligne"
                    icon="history"
                    title="Aucun historique de jeûne"
                />
                <div v-else class="space-y-3">
                    <div
                        v-for="fast in history.data"
                        :key="fast.id"
                        class="border-border bg-surface-card/50 flex items-center justify-between rounded-2xl border p-3"
                    >
                        <div>
                            <p class="text-text-main font-medium">{{ fast.type }}</p>
                            <p class="text-text-muted text-xs">
                                {{ formatDate(fast.start_time) }} -
                                {{ fast.end_time ? formatDate(fast.end_time) : 'En cours' }}
                            </p>
                        </div>
                        <div class="flex items-center gap-3 text-right">
                            <span class="text-text-main font-mono text-sm" v-if="fast.end_time">
                                {{ formatHistoryDuration(fast.start_time, fast.end_time) }}
                            </span>
                            <!-- Rouge au survol seulement : une alerte sur chaque ligne n'alerte plus nulle part. -->
                            <GlassIconButton
                                icon="delete"
                                :label="`Supprimer le jeûne du ${formatDate(fast.start_time)}`"
                                ton="danger"
                                @click="demanderSuppression(fast.id)"
                            />
                        </div>
                    </div>
                </div>

                <!-- Dix par page côté serveur : sans ceci, tout jeûne au-delà du dixième était inaccessible. -->
                <nav
                    v-if="history.last_page > 1"
                    class="mt-6 flex items-center justify-center gap-4"
                    aria-label="Pagination de l'historique des jeûnes"
                >
                    <GlassButton
                        :disabled="!history.prev_page_url"
                        @click="goToPage(history.prev_page_url)"
                        aria-label="Page précédente"
                        dusk="fasting-prev"
                    >
                        <GlassIcon name="chevron_left" />
                    </GlassButton>

                    <span class="text-text-muted text-sm font-bold" aria-live="polite">
                        Page {{ history.current_page }} sur {{ history.last_page }}
                    </span>

                    <GlassButton
                        :disabled="!history.next_page_url"
                        @click="goToPage(history.next_page_url)"
                        aria-label="Page suivante"
                        dusk="fasting-next"
                    >
                        <GlassIcon name="chevron_right" />
                    </GlassButton>
                </nav>
            </GlassCard>
        </div>
        <ConfirmDialog
            :ouvert="suppressionDemandee"
            titre="Supprimer ce jeûne ?"
            description="Il disparaîtra de ton historique."
            @confirmer="confirmerSuppression"
            @annuler="annulerSuppression"
        />
        <ConfirmDialog
            :ouvert="finDemandee"
            titre="Terminer le jeûne ?"
            description="Il sera enregistré avec l'heure de maintenant."
            libelle-confirmation="Terminer"
            variante-confirmation="primary"
            libelle-annulation="Continuer le jeûne"
            :en-cours="endForm.processing"
            @confirmer="endFast"
            @annuler="finDemandee = false"
        />
    </AuthenticatedLayout>
</template>
