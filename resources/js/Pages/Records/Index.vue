<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link } from '@inertiajs/vue3'

import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'
import { dateSansJour, parseCalendarDate } from '@/Utils/date'
import { poids, volume } from '@/Utils/nombre'

defineProps({
    records: { type: Array, required: true },
    tronque: { type: Boolean, default: false },
})

/**
 * Les trois records d'un exercice, dans l'ordre où la fiche les dit ; celui
 * qu'une série n'a pas encore établi n'a pas de ligne.
 */
const lignes = (exercice) =>
    [
        exercice.poids_max && {
            cle: 'poids_max',
            libelle: 'Charge max',
            valeur:
                exercice.poids_max.repetitions > 0
                    ? `${poids(exercice.poids_max.valeur)} × ${exercice.poids_max.repetitions}`
                    : poids(exercice.poids_max.valeur),
            date: exercice.poids_max.date,
        },
        exercice.un_rm && {
            cle: 'un_rm',
            libelle: '1RM estimé',
            valeur: poids(exercice.un_rm.valeur),
            date: exercice.un_rm.date,
        },
        exercice.volume_serie && {
            cle: 'volume_serie',
            libelle: 'Meilleur volume',
            valeur: volume(exercice.volume_serie.valeur),
            date: exercice.volume_serie.date,
        },
    ].filter(Boolean)

const jour = (date) => dateSansJour(parseCalendarDate(date))
</script>

<template>
    <Head title="Records" />

    <AuthenticatedLayout show-back back-route="stats.index" page-title="Records">
        <template #header>
            <h1 class="titre-ecran">Records</h1>
        </template>

        <GlassEmptyState
            v-if="records.length === 0"
            icon="emoji_events"
            title="Pas encore de record"
            description="Ils apparaissent après ta première série terminée."
        />

        <div v-else class="space-y-6">
            <ul class="bureau:grid bureau:grid-cols-2 bureau:gap-3 bureau:space-y-0 space-y-3">
                <li v-for="exercice in records" :key="exercice.exercice_id" data-record>
                    <GlassCard padding="p-4" class="h-full">
                        <Link
                            :href="route('exercises.show', { exercise: exercice.exercice_id })"
                            class="titre-plat flex min-h-11 items-center truncate"
                        >
                            <span class="truncate">{{ exercice.nom }}</span>
                        </Link>

                        <dl class="divide-border divide-y">
                            <div
                                v-for="ligne in lignes(exercice)"
                                :key="ligne.cle"
                                class="flex min-h-11 items-center justify-between gap-3 py-2"
                            >
                                <dt class="min-w-0">
                                    <span class="text-text-main block text-sm">{{ ligne.libelle }}</span>
                                    <span v-if="ligne.date" class="text-text-muted block text-xs">
                                        {{ jour(ligne.date) }}
                                    </span>
                                </dt>
                                <dd class="text-text-main shrink-0 text-lg font-bold whitespace-nowrap tabular-nums">
                                    {{ ligne.valeur }}
                                </dd>
                            </div>
                        </dl>
                    </GlassCard>
                </li>
            </ul>

            <p v-if="tronque" class="text-text-muted text-xs">
                Seuls les exercices au record le plus récent sont listés.
            </p>
        </div>
    </AuthenticatedLayout>
</template>
