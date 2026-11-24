<script setup>
import Cloche from '@/Components/Navigation/Cloche.vue'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'

import MonthlyVolumeCard from '@/Components/Stats/MonthlyVolumeCard.vue'
import VolumeTrendCard from '@/Components/Stats/VolumeTrendCard.vue'
import DurationHistoryCard from '@/Components/Stats/DurationHistoryCard.vue'
import MuscleDistributionCard from '@/Components/Stats/MuscleDistributionCard.vue'
import RegulariteCard from '@/Components/Stats/RegulariteCard.vue'
import TuileDAcces from '@/Components/Stats/TuileDAcces.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'
import GlassSegmented from '@/Components/UI/GlassSegmented.vue'
import IndisponibleHorsLigne from '@/Components/UI/IndisponibleHorsLigne.vue'
import { useCopieHorsLigne } from '@/composables/useCopieHorsLigne'
import { useDemarrageDUneSeance } from '@/composables/useDemarrageDUneSeance'
import { revenirSiLaVisiteEchoue } from '@/Utils/miseAJourOptimiste'

const props = defineProps({
    // ⚡ Bolt: Consolidated deferred data
    deferredData: Object,

    selectedPeriod: String,
})

const { surUneCopie } = useCopieHorsLigne()

/** Démarrer, avec ou sans réseau : par la file hors ligne (#2049). */
const { demarrer, enCours: demarrageEnCours } = useDemarrageDUneSeance()

/** Un vrai booléen du serveur : aucune séance faite, quelle que soit son ancienneté. */
const aucuneSeanceFaite = computed(() => props.deferredData?.aucuneSeanceFaite === true)

const currentPeriod = ref(props.selectedPeriod || '30j')

watch(
    () => props.selectedPeriod,
    (periode) => {
        currentPeriod.value = periode || '30j'
    },
)

const periods = [
    { value: '7j', label: '7 jours' },
    { value: '30j', label: '30 jours' },
    { value: '90j', label: '3 mois' },
    { value: '1a', label: '1 an' },
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
    <Head title="Progrès" />

    <AuthenticatedLayout
        v-slot="{ seanceEnCours }"
        liquid-variant="cyan-magenta"
        page-title="Progrès"
        titre-dans-la-page
        titre-masque
    >
        <div class="relative flex flex-col gap-6">
            <!--
                Le titre du bureau. Sur téléphone, c'est la barre collante de la
                mise en page qui le porte (masqué ici), et la cloche se pose dans
                le coin de la première carte, comme sur l'Accueil et le Profil :
                une rangée qui ne porterait qu'elle resterait vide.
            -->
            <header class="animate-fade-in bureau:block hidden">
                <h1 class="titre-ecran">Progrès</h1>
            </header>

            <div class="bureau:hidden absolute top-3 right-3 z-10"><Cloche /></div>

            <!--
                Sur une copie hors ligne, aucune carte n'a de quoi se remplir : ni
                mesure ni série n'est gardée, et le choix d'une période demande
                le réseau (une visite que rien ne garde). Une seule phrase le
                dit, puis la page offre la seule tuile qui s'ouvre sans réseau,
                la bibliothèque.
            -->
            <template v-if="surUneCopie">
                <IndisponibleHorsLigne
                    texte="Tes chiffres reviennent avec le réseau."
                    class="bureau:min-h-0 bureau:pe-4 min-h-[4.5rem] pe-16"
                />

                <div class="grid grid-cols-2 gap-3" dusk="tuiles-d-acces">
                    <TuileDAcces
                        :href="route('exercises.index')"
                        icon="fitness_center"
                        libelle="Exercices"
                        pleine-largeur
                    />
                </div>
            </template>

            <template v-else>
                <GlassEmptyState
                    v-if="aucuneSeanceFaite"
                    icon="fitness_center"
                    title="Pas encore de séance"
                    description="Tes chiffres apparaissent après la première."
                >
                    <template v-if="(seanceEnCours ?? null) === null" #action>
                        <GlassButton
                            variant="primary"
                            dusk="start-workout-button"
                            :loading="demarrageEnCours"
                            @click="demarrer()"
                        >
                            Démarrer une séance
                        </GlassButton>
                    </template>
                </GlassEmptyState>

                <MonthlyVolumeCard
                    v-else
                    :monthly-comparison="deferredData?.performance?.monthlyComparison"
                    :monthly-volume="deferredData?.volumeMensuel"
                />

                <nav class="grid grid-cols-2 gap-3" aria-label="Accès" dusk="tuiles-d-acces">
                    <TuileDAcces :href="route('records.index')" icon="emoji_events" libelle="Records" pleine-largeur />
                    <TuileDAcces :href="route('exercises.index')" icon="fitness_center" libelle="Exercices" />
                    <TuileDAcces :href="route('corps.index')" icon="accessibility_new" libelle="Corps" />
                    <TuileDAcces :href="route('goals.index')" icon="flag" libelle="Objectifs" />
                    <TuileDAcces :href="route('achievements.index')" icon="military_tech" libelle="Badges" />
                </nav>

                <template v-if="!aucuneSeanceFaite">
                    <RegulariteCard :regularite="deferredData?.regularite" />

                    <section class="space-y-3" aria-labelledby="titre-sur-la-periode">
                        <h2 id="titre-sur-la-periode" class="titre-plat">Sur la période</h2>

                        <GlassSegmented
                            :model-value="currentPeriod"
                            :options="periods"
                            label="Période"
                            size="sm"
                            class="w-full"
                            @update:model-value="handlePeriodChange"
                        />

                        <div class="bureau:grid bureau:grid-cols-2 bureau:gap-3 bureau:space-y-0 space-y-3">
                            <VolumeTrendCard
                                :volume-trend="deferredData?.performance?.volumeTrend"
                                :current-period="currentPeriod"
                                :deferred-data="deferredData"
                            />

                            <MuscleDistributionCard
                                :muscle-distribution="deferredData?.performance?.muscleDistribution"
                                :deferred-data="deferredData"
                            />
                        </div>
                    </section>

                    <DurationHistoryCard
                        :duration-history="deferredData?.performance?.durationHistory"
                        :deferred-data="deferredData"
                    />
                </template>
            </template>
        </div>
    </AuthenticatedLayout>
</template>
