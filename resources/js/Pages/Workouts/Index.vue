<script setup>
import Cloche from '@/Components/Navigation/Cloche.vue'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import GlassButton from '@/Components/UI/GlassButton.vue'
import { Head, Link, useForm, router } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import IndicateurDeRafraichissement from '@/Components/UI/IndicateurDeRafraichissement.vue'
import CarteDeSeance from '@/Components/Workout/CarteDeSeance.vue'
import GlassSkeleton from '@/Components/UI/GlassSkeleton.vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'
import { triggerHaptic } from '@/composables/useHaptics'
import { usePullToRefresh } from '@/composables/usePullToRefresh'
import ConfirmDialog from '@/Components/UI/ConfirmDialog.vue'
import { useConfirmation } from '@/composables/useConfirmation'
import { retirerDeLaListe, revenirSiLaVisiteEchoue } from '@/Utils/miseAJourOptimiste'
import { useDemarrageDUneSeance } from '@/composables/useDemarrageDUneSeance'

const props = defineProps({
    workouts: Object, // Laravel paginator: { data: [...], total, current_page, last_page, prev_page_url, next_page_url }
})

/**
 * Démarrer passe par la file hors ligne, et reste ouvert sur une copie (#2049) :
 * rien n'y est tiré de la copie. Supprimer une séance, lui, s'y désactive dans
 * sa carte (`CarteDeSeance`).
 */
const { demarrer: createWorkout, enCours: demarrageEnCours } = useDemarrageDUneSeance()

/**
 * The optimistic delete used to splice `props.workouts.data` in place. It works,
 * but a page owning a copy of its server data is the convention here — see
 * Workouts/Show, which clones props.workout for exactly this reason — and
 * writing through a prop hides the mutation from Vue's ownership model, so
 * Inertia replacing the prop on the next visit silently discards it.
 */
const workoutList = ref([...(props.workouts?.data ?? [])])

/**
 * The history is paginated 20 to a page server-side and the page offered no way
 * to reach page 2, so everything older than the twentieth session existed and
 * could not be opened. Same two-target layout as the notifications list.
 */
const goToPage = (url) => {
    if (url) {
        router.visit(url)
    }
}

watch(
    () => props.workouts?.data,
    (rows) => {
        workoutList.value = [...(rows ?? [])]
    },
)

const deleteForm = useForm({})
const {
    cible: seanceASupprimer,
    ouvert: suppressionDemandee,
    demander: confirmDeletion,
    annuler: annulerSuppression,
    confirmer: confirmerSuppression,
} = useConfirmation((workout, termine) => {
    /*
     * La ligne part de l'écran avant la réponse du serveur, et revient à sa
     * place exacte sur tout échec : refus, réseau absent, réponse en erreur,
     * visite annulée ou interrompue (#2077). L'ordre compte : on referme le
     * dialogue AVANT de retirer la ligne, sinon la modale reste ouverte sur
     * une liste qui a déjà bougé sous elle.
     */
    termine()

    const remettre = retirerDeLaListe(() => workoutList.value, workout.id)

    if (remettre === null) {
        return
    }

    triggerHaptic('warning')

    deleteForm.delete(
        route('workouts.destroy', { workout: workout.id }),
        revenirSiLaVisiteEchoue(
            () => {
                remettre()
                triggerHaptic('error')
            },
            { preserveScroll: true },
        ),
    )
})
const { isRefreshing, pullDistance } = usePullToRefresh()

/** Un compte neuf n'a qu'un bouton, celui de son état vide : ni l'en-tête ni la ligne d'action ne démarrent. */
const compteNeuf = computed(() => workoutList.value.length === 0)

/** Une séance démarrée sans réseau n'a pas d'adresse que le serveur connaisse : le bandeau du layout l'ouvre. */
const reprise = (seance) => Boolean(seance) && typeof seance.enAttenteDe !== 'string'
</script>

<template>
    <Head title="Mes séances" />

    <AuthenticatedLayout page-title="Mes séances" titre-masque>
        <template #header>
            <div class="flex items-center justify-between">
                <h1 class="titre-ecran">Mes séances</h1>
                <div class="flex gap-2">
                    <!-- Des navigations : un seul lien chacune, par `href` (#2117). -->
                    <GlassButton :href="route('calendar.index')">
                        <GlassIcon name="calendar_month" size="sm" class="mr-2" />
                        Calendrier
                    </GlassButton>
                    <GlassButton :href="route('templates.index')">
                        <GlassIcon name="inventory_2" size="sm" class="mr-2" />
                        Modèles
                    </GlassButton>
                    <GlassButton
                        v-if="!compteNeuf"
                        variant="primary"
                        :loading="demarrageEnCours"
                        @click="createWorkout"
                        aria-label="Nouvelle séance"
                    >
                        <GlassIcon name="add" size="sm" class="mr-2" />
                        Nouvelle séance
                    </GlassButton>
                </div>
            </div>
        </template>

        <template #default="{ seanceEnCours }">
            <IndicateurDeRafraichissement :distance="pullDistance" :en-cours="isRefreshing" />

            <div class="space-y-6">
                <!-- Les actions de l'en-tête de bureau, qui ne se montre qu'à partir de 1 024 px. -->
                <div class="bureau:hidden space-y-3">
                    <div class="flex items-center gap-3">
                        <GlassButton
                            v-if="reprise(seanceEnCours)"
                            variant="primary"
                            class="min-w-0 flex-1"
                            :href="route('workouts.show', { workout: seanceEnCours.id })"
                            dusk="reprendre-la-seance"
                        >
                            <GlassIcon name="play_arrow" size="sm" class="mr-2" />
                            Reprendre
                        </GlassButton>
                        <GlassButton
                            v-else-if="!seanceEnCours && !compteNeuf"
                            variant="primary"
                            class="min-w-0 flex-1"
                            :loading="demarrageEnCours"
                            @click="createWorkout"
                        >
                            <GlassIcon name="add" size="sm" class="mr-2" />
                            Démarrer une séance
                        </GlassButton>
                        <Cloche class="ms-auto" />
                    </div>
                    <nav
                        aria-label="Séances"
                        class="border-glass-border bg-surface-card/50 flex rounded-xl border p-1 text-sm font-bold"
                    >
                        <span
                            aria-current="page"
                            class="bg-text-main text-text-on-dark-accent min-h-touch flex flex-1 items-center justify-center rounded-lg px-3"
                        >
                            Historique
                        </span>
                        <Link
                            :href="route('templates.index')"
                            class="text-text-muted min-h-touch focus-visible:ring-accent-primary flex flex-1 items-center justify-center rounded-lg px-3 focus-visible:ring-2 focus-visible:outline-none"
                        >
                            Modèles
                        </Link>
                        <Link
                            :href="route('calendar.index')"
                            class="text-text-muted min-h-touch focus-visible:ring-accent-primary flex flex-1 items-center justify-center rounded-lg px-3 focus-visible:ring-2 focus-visible:outline-none"
                        >
                            Calendrier
                        </Link>
                    </nav>
                </div>

                <div class="stagger-2 animate-slide-up">
                    <h3 class="titre-plat bureau:block mb-3 hidden">Historique</h3>

                    <div v-if="!workouts" class="space-y-3">
                        <GlassCard v-for="i in 3" :key="i" padding="p-4">
                            <div class="flex items-start justify-between">
                                <div class="flex-1 space-y-2">
                                    <div class="flex items-center gap-2">
                                        <GlassSkeleton width="40%" height="1.5rem" />
                                        <GlassSkeleton width="40px" height="1.2rem" />
                                    </div>
                                    <GlassSkeleton width="30%" height="0.8rem" />
                                    <div class="mt-2 flex gap-2">
                                        <GlassSkeleton width="60px" height="1.2rem" />
                                        <GlassSkeleton width="60px" height="1.2rem" />
                                    </div>
                                </div>
                            </div>
                        </GlassCard>
                    </div>

                    <div v-else-if="compteNeuf">
                        <GlassEmptyState
                            title="Pas encore de séance"
                            description="Ton historique commence à la première."
                            icon="fitness_center"
                            action-label="Démarrer une séance"
                            action-id="empty-state-start-workout"
                            @action="createWorkout"
                            color="orange"
                        />
                    </div>

                    <div v-else class="space-y-3">
                        <CarteDeSeance
                            v-for="workout in workoutList"
                            :key="workout.id"
                            :seance="workout"
                            :en-cours="seanceEnCours?.id === workout.id"
                            @supprimer="confirmDeletion"
                        />
                    </div>

                    <nav
                        v-if="workouts?.last_page > 1"
                        class="mt-6 flex items-center justify-center gap-4"
                        aria-label="Pagination de l'historique"
                    >
                        <GlassButton
                            :disabled="!workouts.prev_page_url"
                            @click="goToPage(workouts.prev_page_url)"
                            aria-label="Page précédente"
                            dusk="workouts-prev"
                        >
                            <GlassIcon name="chevron_left" />
                        </GlassButton>

                        <span class="text-text-muted text-sm font-bold" aria-live="polite">
                            Page {{ workouts.current_page }} sur {{ workouts.last_page }}
                        </span>

                        <GlassButton
                            :disabled="!workouts.next_page_url"
                            @click="goToPage(workouts.next_page_url)"
                            aria-label="Page suivante"
                            dusk="workouts-next"
                        >
                            <GlassIcon name="chevron_right" />
                        </GlassButton>
                    </nav>
                </div>
            </div>

            <ConfirmDialog
                :ouvert="suppressionDemandee"
                titre="Supprimer cette séance ?"
                :description="
                    seanceASupprimer
                        ? `« ${seanceASupprimer.name || 'Séance'} » sera effacée, avec toutes ses séries.`
                        : ''
                "
                @confirmer="confirmerSuppression"
                @annuler="annulerSuppression"
            />
        </template>
    </AuthenticatedLayout>
</template>
