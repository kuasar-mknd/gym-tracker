<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { Head, Link, Deferred } from '@inertiajs/vue3'
import { computed } from 'vue'
import { graphiqueAsynchrone } from '@/Utils/graphiqueAsynchrone'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'
import HistoriqueEnAttente from '@/Components/Stats/HistoriqueEnAttente.vue'
import IndisponibleHorsLigne from '@/Components/UI/IndisponibleHorsLigne.vue'
import { peutEtablirUnRecord, seriesDeTravail, volumeDesSeriesDeTravail } from '@/Utils/seriesValidees'

const OneRepMaxChart = graphiqueAsynchrone(() => import('@/Components/Stats/OneRepMaxChart.vue'))
const VolumeTrendChart = graphiqueAsynchrone(() => import('@/Components/Stats/VolumeTrendChart.vue'))
const MaxRepsChart = graphiqueAsynchrone(() => import('@/Components/Stats/MaxRepsChart.vue'))
const MaxWeightChart = graphiqueAsynchrone(() => import('@/Components/Stats/MaxWeightChart.vue'))
const SetsPerSessionChart = graphiqueAsynchrone(() => import('@/Components/Stats/SetsPerSessionChart.vue'))
const WeightRepsScatterChart = graphiqueAsynchrone(() => import('@/Components/Stats/WeightRepsScatterChart.vue'))

/**
 * Component Props
 *
 * @property {Object} exercise - `{ id, name, category (e.g. 'Pectoraux'), type ('strength', 'cardio', 'timed') }`.
 *
 * @property {Array} progress - Data for the 1RM progression chart.
 * @property {Object} progress[] - `{ date ('12/05'), full_date (ISO), one_rep_max }`.
 *
 * @property {Array} history - List of past workout sessions for this exercise, a deferred prop (#2351): absent until it arrives.
 * @property {number} history[].id - Unique session identifier.
 * @property {number} history[].workout_id - ID of the workout.
 * @property {string} history[].workout_name - Name of the workout.
 * @property {string} history[].formatted_date - Formatted date string (e.g., 'Lun 12 Mai').
 * @property {Array} history[].sets - Every set of this session, validated or not.
 * @property {Object} history[].sets[] - `{ weight, reps, one_rep_max, is_completed, is_warmup }`.
 */
const props = defineProps({
    exercise: Object,
    progress: Array,
    history: Array,
})

/*
 * Le poids du corps est une force au poids nul. Un exercice de cardio ou de
 * temps n'a ni charge ni répétitions : ses séries sont à 0, et seul le nombre
 * de séries par séance dit quelque chose.
 */
const estUneForce = computed(() => props.exercise.type === 'strength')

/*
 * Une série de travail validée : ce que la fiche lit. Un historique où il n'y
 * en a aucune (séries jamais cochées, échauffements seuls, ou séries de force
 * sans répétition) n'a rien à tracer et garde les états vides de la page,
 * comme un historique vide. Le cardio et le temps n'ont jamais de répétitions :
 * leurs séries comptent sans.
 */
const compteCommeTravail = (serie) => !estUneForce.value || Number(serie.reps) > 0

const aDesSeriesDeTravail = computed(() =>
    (props.history ?? []).some((session) => seriesDeTravail(session.sets).some(compteCommeTravail)),
)

/*
 * Une charge est une série qui peut établir un record : poids et répétitions
 * positifs, comme « Charge max », « Poids vs Reps » et la progression du 1RM.
 * Une force qui n'en a aucune (le poids du corps) n'a ni volume en kilos, ni
 * charge max, ni nuage, ni 1RM : seules ses répétitions disent quelque chose.
 * Dès qu'une série porte une charge, les cinq cartes reviennent.
 */
const aDesCharges = computed(() => (props.history ?? []).some((session) => session.sets.some(peutEtablirUnRecord)))

const afficheLesCharges = computed(() => estUneForce.value && aDesCharges.value)

/*
 * La progression du 1RM arrive avec la page, avant l'historique : tant qu'il
 * n'est pas là, une courbe vide ne la montre pas, de peur de la voir
 * disparaître pour le poids du corps. Un exercice sans série de travail garde
 * son état vide.
 */
const afficheLaProgression = computed(
    () =>
        estUneForce.value &&
        (props.progress.length > 0 || (props.history != null && (!aDesSeriesDeTravail.value || aDesCharges.value))),
)

/**
 * Les séances de la plus ancienne à la plus récente, avec les séries que
 * chaque graphique a le droit de lire.
 *
 * Les graphiques ne comptent que les séries de travail validées (#1956,
 * #2333) : ni série jamais cochée, ni échauffement, donc un volume plus petit
 * que celui de la séance ; la charge max suit en plus les règles du record. Une séance sans série qui compte n'a pas
 * de meilleure valeur : son point vaut null, que Chart.js ne trace pas, plutôt
 * qu'un zéro qui creuserait la courbe.
 */
const seances = computed(() => {
    if (!props.history || props.history.length === 0) return []
    // History is desc, so reverse for chart
    return [...props.history].reverse().map((session) => ({
        date: session.formatted_date.split('/').slice(0, 2).join('/'), // Just dd/mm
        seriesDeTravail: seriesDeTravail(session.sets),
        seriesDuRecord: session.sets.filter(peutEtablirUnRecord),
    }))
})

const volumeData = computed(() =>
    seances.value.map((session) => ({
        date: session.date,
        volume: volumeDesSeriesDeTravail(session.seriesDeTravail),
    })),
)

const maxRepsData = computed(() =>
    seances.value.map((session) => ({
        date: session.date,
        reps: session.seriesDeTravail.length > 0 ? Math.max(...session.seriesDeTravail.map((s) => s.reps || 0)) : null,
    })),
)

const setsPerSessionData = computed(() =>
    seances.value.map((session) => ({
        date: session.date,
        sets: session.seriesDeTravail.length,
    })),
)

const maxWeightData = computed(() =>
    seances.value.map((session) => ({
        date: session.date,
        weight:
            session.seriesDuRecord.length > 0
                ? Math.max(...session.seriesDuRecord.map((s) => parseFloat(s.weight)))
                : null,
    })),
)

const scatterData = computed(() => {
    return seances.value
        .flatMap((s) => s.seriesDeTravail)
        .filter((s) => parseFloat(s.weight) > 0 && parseInt(s.reps) > 0)
        .map((s) => ({
            x: parseFloat(s.weight),
            y: parseInt(s.reps),
        }))
})
</script>

<template>
    <Head :title="exercise.name" />

    <AuthenticatedLayout :page-title="exercise.name" show-back back-route="exercises.index" retour-propre>
        <template #header>
            <div class="flex items-center gap-4">
                <Link
                    :href="route('exercises.index')"
                    class="text-text-muted hover:text-accent-primary-deep border-border bg-surface-card flex size-11 shrink-0 items-center justify-center rounded-full border transition-colors"
                    aria-label="Retour aux exercices"
                >
                    <GlassIcon name="arrow_back" />
                </Link>
                <h1 class="titre-ecran">
                    {{ exercise.name }}
                </h1>
            </div>
        </template>

        <div class="space-y-6">
            <!--
                La catégorie et le type se lisent à toute largeur : l'en-tête de
                page n'existe qu'avec la barre de bureau, à partir de 1 024 px
                (#2057), et l'en-tête mobile ne porte que le nom.
            -->
            <p class="etiquette">
                {{ exercise.category }} •
                {{ exercise.type === 'strength' ? 'Force' : exercise.type === 'cardio' ? 'Cardio' : 'Temps' }}
            </p>

            <!-- Progress Chart -->
            <GlassCard v-if="afficheLaProgression" class="animate-slide-up">
                <div class="mb-4">
                    <h3 class="titre-plat">Progression 1RM</h3>
                    <p class="etiquette">Estimation sur 1 an</p>
                </div>
                <div v-if="progress.length > 0" class="h-64">
                    <OneRepMaxChart :data="progress" />
                </div>
                <GlassEmptyState
                    v-else
                    taille="ligne"
                    icon="show_chart"
                    title="Pas assez de données pour afficher le graphique"
                    class="h-64"
                />
            </GlassCard>

            <Deferred data="history">
                <template #rescue>
                    <IndisponibleHorsLigne />
                </template>
                <template #fallback>
                    <HistoriqueEnAttente :cartes="estUneForce ? 4 : 1" />
                </template>
                <div
                    v-if="aDesSeriesDeTravail"
                    class="stagger-1 animate-slide-up grid grid-cols-1 gap-6 md:grid-cols-2"
                >
                    <GlassCard v-if="afficheLesCharges">
                        <div class="mb-4">
                            <h3 class="titre-plat">Volume</h3>
                            <p class="etiquette">Volume total par séance (kg)</p>
                        </div>
                        <div class="h-64">
                            <VolumeTrendChart :data="volumeData" hauteur="h-full" />
                        </div>
                    </GlassCard>

                    <GlassCard v-if="estUneForce">
                        <div class="mb-4">
                            <h3 class="titre-plat">Endurance</h3>
                            <p class="etiquette">Répétitions max par série</p>
                        </div>
                        <div class="h-64">
                            <MaxRepsChart :data="maxRepsData" />
                        </div>
                    </GlassCard>

                    <GlassCard v-if="afficheLesCharges">
                        <div class="mb-4">
                            <h3 class="titre-plat">Charge max</h3>
                            <p class="etiquette">Maximum soulevé par séance (kg)</p>
                        </div>
                        <div class="h-64">
                            <MaxWeightChart :data="maxWeightData" />
                        </div>
                    </GlassCard>

                    <GlassCard v-if="afficheLesCharges">
                        <div class="mb-4">
                            <h3 class="titre-plat">Poids vs reps</h3>
                            <p class="etiquette">Répartition des séries de travail</p>
                        </div>
                        <div class="h-64">
                            <WeightRepsScatterChart :data="scatterData" />
                        </div>
                    </GlassCard>

                    <GlassCard v-if="!estUneForce">
                        <div class="mb-4">
                            <h3 class="titre-plat">Séries</h3>
                            <p class="etiquette">Nombre de séries par séance</p>
                        </div>
                        <div class="h-64">
                            <SetsPerSessionChart :data="setsPerSessionData" />
                        </div>
                    </GlassCard>
                </div>
                <GlassCard v-else class="stagger-1 animate-slide-up">
                    <GlassEmptyState
                        taille="ligne"
                        icon="bar_chart"
                        title="Pas assez de données pour afficher les statistiques"
                        class="h-64"
                    />
                </GlassCard>
            </Deferred>
        </div>
    </AuthenticatedLayout>
</template>
