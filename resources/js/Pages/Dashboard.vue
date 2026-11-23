<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Deferred } from '@inertiajs/vue3'
import DashboardHeader from '@/Components/Dashboard/DashboardHeader.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'
import IndisponibleHorsLigne from '@/Components/UI/IndisponibleHorsLigne.vue'
import QuickActions from '@/Components/Dashboard/QuickActions.vue'
import WeeklyVolumeSection from '@/Components/Dashboard/WeeklyVolumeSection.vue'
import RecentActivity from '@/Components/Dashboard/RecentActivity.vue'
import GoalsSummary from '@/Components/Dashboard/GoalsSummary.vue'
import RecentPRs from '@/Components/Dashboard/RecentPRs.vue'
import { ref } from 'vue'
import InvitationDInstallation from '@/Components/UI/InvitationDInstallation.vue'
import InvitationAuxNotifications from '@/Components/UI/InvitationAuxNotifications.vue'
import { useCopieHorsLigne } from '@/composables/useCopieHorsLigne'
import { useDemarrageDUneSeance } from '@/composables/useDemarrageDUneSeance'

/**
 * Dashboard - Command Center
 * Pure composition page using specialized sub-components.
 */
const props = defineProps({
    recentWorkouts: { type: Array, default: () => [] },
    recentPRs: { type: Array, default: () => [] },
    activeGoals: { type: Array, default: () => [] },

    // Consolidated deferred props
    analyticalStats: {
        type: Object,
        default: () => ({
            weeklyVolume: { stats: { current_week_volume: 0, percentage: null, sessions_count: 0 }, trend: [] },
        }),
    },
})

/**
 * Un compte neuf : aucune séance du tout, donc aucune carte à remplir. Une séance
 * démarrée hors ligne, encore dans la file, en est une : le layout la donne (il
 * la tient, la page est son parent et ne peut pas l'injecter), sans quoi l'état
 * vide garderait un bouton « Démarrer » que les actions rapides ne proposent plus.
 */
const estUnCompteNeuf = (seanceEnCours) => props.recentWorkouts.length === 0 && (seanceEnCours ?? null) === null

/**
 * Une copie hors ligne de l'accueil ne garde pas les objectifs, qu'aucune copie
 * ne garde (#2048, `PROPS_JAMAIS_GARDEES`) : leur carte le dit.
 */
const { indisponibleSurLaCopie } = useCopieHorsLigne()

/** Démarrer, avec ou sans réseau : par la file hors ligne (#2049). */
const { demarrer: startWorkout, enCours: demarrageEnCours } = useDemarrageDUneSeance()

/**
 * Une seule invitation à la fois : celle d'installation passe d'abord, celle
 * des notifications attend une autre fin de séance (#1848).
 */
const installationProposee = ref(false)
</script>

<template>
    <Head title="Accueil" />

    <AuthenticatedLayout v-slot="{ seanceEnCours }" page-title="Accueil" titre-masque largeur="moyenne">
        <div class="space-y-6">
            <DashboardHeader :user="$page.props.auth.user" />

            <QuickActions
                :processing="demarrageEnCours"
                :sans-demarrage="estUnCompteNeuf(seanceEnCours)"
                @start-workout="startWorkout"
            />

            <InvitationDInstallation @visibilite="installationProposee = $event" />

            <!-- La donnée flash d'une fin de séance : une visite, jamais rejouée par l'historique. -->
            <InvitationAuxNotifications
                v-if="$page.flash?.proposerLesNotifications === true"
                :une-autre-invitation-passe-avant="installationProposee"
            />

            <GlassEmptyState
                v-if="estUnCompteNeuf(seanceEnCours)"
                icon="fitness_center"
                title="Pas encore de séance"
                description="Tes chiffres s'affichent ici après la première."
            >
                <template #action>
                    <GlassButton
                        variant="primary"
                        dusk="start-workout-button"
                        :loading="demarrageEnCours"
                        @click="startWorkout()"
                    >
                        Démarrer une séance
                    </GlassButton>
                </template>
            </GlassEmptyState>

            <template v-else>
                <Deferred data="analyticalStats">
                    <template #rescue>
                        <IndisponibleHorsLigne />
                    </template>

                    <template #fallback>
                        <GlassSkeleton class="h-56 w-full" />
                    </template>

                    <WeeklyVolumeSection
                        :weekly-volume-stats="analyticalStats?.weeklyVolume?.stats"
                        :weekly-volume-trend="analyticalStats?.weeklyVolume?.trend"
                    />
                </Deferred>

                <RecentActivity :recent-workouts="recentWorkouts" />

                <GoalsSummary :active-goals="activeGoals" :indisponible="indisponibleSurLaCopie('activeGoals')" />

                <RecentPRs :recent-p-rs="recentPRs" />
            </template>
        </div>
    </AuthenticatedLayout>
</template>
