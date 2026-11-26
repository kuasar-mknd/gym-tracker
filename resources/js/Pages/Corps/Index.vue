<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import { Head, Link } from '@inertiajs/vue3'

import BodyFatCard from '@/Components/Stats/BodyFatCard.vue'
import WeightEvolutionCard from '@/Components/Stats/WeightEvolutionCard.vue'
import GlassCard from '@/Components/UI/GlassCard.vue'
import GlassEmptyState from '@/Components/UI/GlassEmptyState.vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { dateCourte, parseCalendarDate } from '@/Utils/date'
import { nombre, variation } from '@/Utils/nombre'

defineProps({
    joursDesCourbes: { type: Number, required: true },
    latestWeight: [Number, String],
    weightChange: Number,
    bodyFat: Number,
    latestMeasurements: Array,
    bodyStats: Object,
})

const cleDeLaLigne = (ligne) => (ligne.cotes ? `famille:${ligne.famille}` : `part:${ligne.part}`)

/** « Biceps gauche » → « Gauche » : le dernier mot du libellé que donne le serveur. */
const nomDuCote = (cote) => {
    const mot = cote.label.split(' ').pop()

    return mot.charAt(0).toUpperCase() + mot.slice(1)
}

/** Les deux côtés portent-ils la même date ? Alors elle va au titre, sinon sous chaque valeur. */
const memeDate = (ligne) => ligne.cotes.every((cote) => cote.date === ligne.cotes[0].date)

const dateDe = (date) => dateCourte(parseCalendarDate(date))

const nomAccessible = (cote) => {
    const ecart = cote.diff === 0 ? '' : `, ${cote.diff > 0 ? 'plus' : 'moins'} ${nombre(Math.abs(cote.diff), 2)}`

    return `${cote.label}, ${nombre(cote.current, 2)} ${cote.unit}${ecart}`
}
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
                :periode="`les ${joursDesCourbes} derniers jours`"
            />

            <BodyFatCard :body-fat="bodyFat" :body-fat-history="bodyStats?.bodyFatHistory" />

            <GlassCard class="bureau:col-span-2 bureau:self-start">
                <h2 class="etiquette mb-3">Mensurations</h2>

                <GlassEmptyState
                    v-if="latestMeasurements.length === 0"
                    taille="ligne"
                    icon="accessibility_new"
                    title="Aucune mensuration enregistrée"
                />

                <ul v-else class="bureau:grid bureau:grid-cols-2 bureau:gap-x-7">
                    <li
                        v-for="item in latestMeasurements"
                        :key="cleDeLaLigne(item)"
                        class="border-border bureau:[&:nth-child(2)]:border-t-0 border-t first:border-t-0"
                    >
                        <div v-if="item.cotes" class="py-2">
                            <div class="flex min-h-8 items-baseline justify-between gap-2">
                                <span class="titre-plat min-w-0 truncate">{{ item.label }}</span>
                                <span v-if="memeDate(item)" class="text-text-muted shrink-0 text-xs">
                                    {{ dateDe(item.cotes[0].date) }}
                                </span>
                            </div>
                            <div class="mt-1 flex gap-2">
                                <Link
                                    v-for="cote in item.cotes"
                                    :key="cote.part"
                                    :href="route('body-parts.show', { part: cote.part })"
                                    :aria-label="nomAccessible(cote)"
                                    class="border-border bg-surface-sunken focus-visible:ring-accent-primary min-h-touch flex min-w-0 flex-1 flex-col justify-center rounded-xl border px-3 py-1.5 focus-visible:ring-2 focus-visible:outline-none"
                                >
                                    <span class="text-text-muted text-xs font-bold">{{ nomDuCote(cote) }}</span>
                                    <span class="text-text-main text-lg font-bold whitespace-nowrap">
                                        {{ nombre(cote.current, 2) }}
                                        <span class="text-text-muted text-sm">{{ cote.unit }}</span>
                                    </span>
                                    <span
                                        v-if="cote.diff !== 0"
                                        :class="[
                                            'text-xs font-bold',
                                            cote.diff > 0 ? 'text-trend-up' : 'text-trend-down',
                                        ]"
                                    >
                                        {{ variation(cote.diff, null, 2) }}
                                    </span>
                                    <span v-if="!memeDate(item)" class="text-text-muted text-xs">
                                        {{ dateDe(cote.date) }}
                                    </span>
                                </Link>
                            </div>
                        </div>
                        <Link
                            v-else
                            :href="route('body-parts.show', { part: item.part })"
                            class="flex items-center justify-between gap-3 py-3"
                        >
                            <span class="min-w-0">
                                <span class="titre-plat block truncate">{{ item.label ?? item.part }}</span>
                                <span class="text-text-muted block text-xs">
                                    {{ dateDe(item.date) }}
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
