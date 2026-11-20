<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link, router } from '@inertiajs/vue3'
import { ref, watch } from 'vue'

import MonthlyVolumeCard from '@/Components/Stats/MonthlyVolumeCard.vue'
import VolumeTrendCard from '@/Components/Stats/VolumeTrendCard.vue'
import DurationHistoryCard from '@/Components/Stats/DurationHistoryCard.vue'
import MuscleDistributionCard from '@/Components/Stats/MuscleDistributionCard.vue'
import ExerciseProgressCard from '@/Components/Stats/ExerciseProgressCard.vue'
import SummaryStatsGrid from '@/Components/Stats/SummaryStatsGrid.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import GlassSegmented from '@/Components/UI/GlassSegmented.vue'
import IndisponibleHorsLigne from '@/Components/UI/IndisponibleHorsLigne.vue'
import { useCopieHorsLigne } from '@/composables/useCopieHorsLigne'
import { revenirSiLaVisiteEchoue } from '@/Utils/miseAJourOptimiste'

const props = defineProps({
    // ⚡ Bolt: Consolidated deferred data
    deferredData: Object,

    exercises: Array,
    selectedPeriod: String,
})

const { surUneCopie } = useCopieHorsLigne()

const currentPeriod = ref(props.selectedPeriod || '30j')

watch(
    () => props.selectedPeriod,
    (periode) => {
        currentPeriod.value = periode || '30j'
    },
)

const periods = [
    { value: '7j', label: '7 JOURS' },
    { value: '30j', label: '30 JOURS' },
    { value: '90j', label: '3 MOIS' },
    { value: '1a', label: '1 AN' },
]

/** Le numéro de la dernière période demandée : seul son échec ramène la sélection. */
let derniereDemande = 0

/**
 * La sélection bouge tout de suite, et revient à la période que la page montre
 * sur tout échec de la dernière demande : refus, réseau absent, réponse en
 * erreur, visite annulée ou interrompue (#2077). Elle restait sur la période
 * demandée, au-dessus des données de l'ancienne.
 */
const handlePeriodChange = (period) => {
    const demande = ++derniereDemande

    currentPeriod.value = period
    router.visit(
        route('stats.index'),
        revenirSiLaVisiteEchoue(
            () => {
                if (demande === derniereDemande) {
                    currentPeriod.value = props.selectedPeriod || '30j'
                }
            },
            {
                data: { period },
                preserveScroll: true,
                preserveState: true,
            },
        ),
    )
}
</script>

<template>
    <Head title="Statistiques" />

    <AuthenticatedLayout liquid-variant="cyan-magenta" page-title="Mon Évolution" titre-dans-la-page>
        <div class="space-y-6">
            <!-- Header -->
            <header class="animate-fade-in flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <!--
                    Le titre du bureau. Sur téléphone, c'est la barre collante de
                    la mise en page qui le porte, et l'afficher ici aussi le
                    dirait deux fois — en plus de mettre deux `<h1>` dans une
                    même page.
                -->
                <div class="bureau:block hidden">
                    <h1 class="titre-page">
                        Mon<br />
                        <span class="text-gradient-cyan-magenta">Évolution</span>
                    </h1>
                </div>

                <GlassSegmented
                    v-if="!surUneCopie"
                    :model-value="currentPeriod"
                    :options="periods"
                    label="Période"
                    size="sm"
                    class="shrink-0"
                    @update:model-value="handlePeriodChange"
                />
            </header>

            <!--
                Sur une copie hors ligne, aucune carte n'a de quoi se remplir : ni
                mesure ni série n'est gardée, et les contrôles de la page
                demandent le réseau (une période, une courbe d'exercice : des
                visites que rien ne garde). Une seule phrase le dit, puis la
                page offre ce qui s'ouvre sans réseau, la bibliothèque.
            -->
            <template v-if="surUneCopie">
                <IndisponibleHorsLigne texte="Les mesures reviennent avec le réseau." />

                <Link
                    :href="route('exercises.index')"
                    class="text-accent-primary-deep inline-flex items-center gap-2 text-xs font-bold tracking-wider uppercase"
                >
                    Bibliothèque d'exercices
                    <GlassIcon name="arrow_forward" size="xs" />
                </Link>
            </template>

            <template v-else>
                <div class="space-y-3">
                    <Link v-press :href="route('records.index')" class="block">
                        <GlassCard
                            padding="p-4"
                            class="transition duration-300 hover:-translate-y-0.5 active:scale-[0.99]"
                        >
                            <div class="flex items-center gap-3">
                                <GlassIcon name="emoji_events" class="text-accent-warning-deep" />
                                <div class="min-w-0 flex-1">
                                    <h2 class="titre-carte">Records</h2>
                                    <p class="text-text-muted text-xs">Charge max, 1RM estimé, meilleur volume</p>
                                </div>
                                <GlassIcon name="arrow_forward" size="xs" class="text-text-muted" />
                            </div>
                        </GlassCard>
                    </Link>

                    <Link v-press :href="route('corps.index')" class="block">
                        <GlassCard
                            padding="p-4"
                            class="transition duration-300 hover:-translate-y-0.5 active:scale-[0.99]"
                        >
                            <div class="flex items-center gap-3">
                                <GlassIcon name="accessibility_new" class="text-accent-secondary-deep" />
                                <div class="min-w-0 flex-1">
                                    <h2 class="titre-carte">Corps</h2>
                                    <p class="text-text-muted text-xs">Poids, masse grasse, mensurations</p>
                                </div>
                                <GlassIcon name="arrow_forward" size="xs" class="text-text-muted" />
                            </div>
                        </GlassCard>
                    </Link>
                </div>

                <MonthlyVolumeCard :monthly-comparison="deferredData?.performance?.monthlyComparison" />

                <VolumeTrendCard
                    :volume-trend="deferredData?.performance?.volumeTrend"
                    :current-period="currentPeriod"
                    :deferred-data="deferredData"
                />

                <DurationHistoryCard
                    :duration-history="deferredData?.performance?.durationHistory"
                    :deferred-data="deferredData"
                />

                <div class="stagger-4 animate-slide-up grid grid-cols-1 gap-6 lg:grid-cols-2">
                    <MuscleDistributionCard
                        :muscle-distribution="deferredData?.performance?.muscleDistribution"
                        :deferred-data="deferredData"
                    />

                    <ExerciseProgressCard :exercises="exercises" />
                </div>

                <SummaryStatsGrid
                    :volume-trend="deferredData?.performance?.volumeTrend"
                    :muscle-distribution="deferredData?.performance?.muscleDistribution"
                    :exercises="exercises"
                    :monthly-comparison="deferredData?.performance?.monthlyComparison"
                    :deferred-data="deferredData"
                />
            </template>
        </div>
    </AuthenticatedLayout>
</template>
