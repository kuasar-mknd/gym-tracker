<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link } from '@inertiajs/vue3'
import { computed } from 'vue'

import BodyFatCard from '@/Components/Stats/BodyFatCard.vue'
import WeightEvolutionCard from '@/Components/Stats/WeightEvolutionCard.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { dateCourte, parseCalendarDate } from '@/Utils/date'
import { nombre, variation } from '@/Utils/nombre'

const MENSURATIONS_AFFICHEES = 6
const JOURS_DES_COURBES = 90
const ORDRE_DES_MENSURATIONS = [
    ['waist', 'taille'],
    ['chest', 'poitrine'],
    ['hips', 'hanches'],
    ['thigh', 'cuisse'],
    ['biceps'],
    ['calf', 'mollet'],
]

const rang = (mensuration) => {
    const nom = String(mensuration.part).toLowerCase()
    const position = ORDRE_DES_MENSURATIONS.findIndex((noms) => noms.some((debut) => nom.startsWith(debut)))

    return position === -1 ? ORDRE_DES_MENSURATIONS.length : position
}

const props = defineProps({
    latestWeight: [Number, String],
    weightChange: Number,
    bodyFat: Number,
    latestMeasurements: Array,
    bodyStats: Object,
})

const mensurations = computed(() =>
    [...props.latestMeasurements].sort((a, b) => rang(a) - rang(b)).slice(0, MENSURATIONS_AFFICHEES),
)
</script>

<template>
    <Head title="Corps" />

    <AuthenticatedLayout show-back back-route="stats.index" page-title="Corps">
        <template #header>
            <h1 class="titre-ecran">Corps</h1>
        </template>

        <div class="bureau:grid bureau:grid-cols-2 bureau:gap-6 bureau:space-y-0 space-y-6">
            <WeightEvolutionCard
                :latest-weight="latestWeight"
                :weight-change="weightChange"
                :weight-history="bodyStats?.weightHistory"
                :periode="`les ${JOURS_DES_COURBES} derniers jours`"
            />

            <BodyFatCard :body-fat="bodyFat" :body-fat-history="bodyStats?.bodyFatHistory" />

            <GlassCard class="bureau:col-span-2 bureau:self-start">
                <h2 class="etiquette mb-3">Mensurations</h2>

                <GlassEmptyState
                    v-if="mensurations.length === 0"
                    taille="ligne"
                    icon="accessibility_new"
                    title="Aucune mensuration enregistrée"
                />

                <ul v-else class="divide-border divide-y">
                    <li v-for="item in mensurations" :key="item.part">
                        <Link
                            :href="route('body-parts.show', { part: item.part })"
                            class="flex items-center justify-between gap-3 py-3"
                        >
                            <span class="min-w-0">
                                <span class="titre-plat block truncate">{{ item.label ?? item.part }}</span>
                                <span class="text-text-muted block text-xs">
                                    {{ dateCourte(parseCalendarDate(item.date)) }}
                                </span>
                            </span>
                            <span class="shrink-0 text-right">
                                <span class="text-text-main block text-lg font-bold">
                                    {{ nombre(item.current, 2) }}
                                    <span class="text-text-muted text-sm">{{ item.unit }}</span>
                                </span>
                                <span
                                    v-if="item.diff !== 0"
                                    :class="[
                                        'block text-xs font-bold',
                                        item.diff > 0 ? 'text-trend-up' : 'text-trend-down',
                                    ]"
                                >
                                    {{ variation(item.diff, null, 2) }}
                                </span>
                            </span>
                        </Link>
                    </li>
                </ul>

                <Link
                    :href="route('body-parts.index')"
                    class="text-accent-primary-deep mt-4 inline-flex items-center gap-2 text-xs font-bold hover:underline"
                >
                    {{ latestMeasurements.length > 0 ? 'Toutes les mensurations' : 'Ajouter une mensuration' }}
                    <GlassIcon name="arrow_forward" size="xs" />
                </Link>
            </GlassCard>
        </div>
    </AuthenticatedLayout>
</template>
