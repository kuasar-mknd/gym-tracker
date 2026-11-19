<script setup>
import { computed } from 'vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { Link } from '@inertiajs/vue3'
import { nombre } from '@/Utils/nombre'

const props = defineProps({
    user: { type: Object, required: true },
    /**
     * The controller has always sent this and the dashboard never rendered it,
     * so the query ran on every page load to fill a prop nothing read.
     */
    latestWeight: { type: [Number, String], default: null },
    /**
     * Le dernier poids manque parce que la page est une copie hors ligne,
     * qui ne garde jamais rien des mesures (#2048) : le dire, plutôt que de
     * laisser croire qu'aucun poids n'a été noté.
     */
    poidsIndisponible: { type: Boolean, default: false },
})

/**
 * Arrives as a decimal string ("75.50"). Trailing zeros are noise on a body
 * weight, and the separator has to be the French one to match every other
 * figure on the page.
 */
const formattedWeight = computed(() => {
    const formate = nombre(props.latestWeight)

    return formate === '—' ? null : formate
})

const streakDays = computed(() => props.user.current_streak || 0)

/**
 * The badge reads "4 Jours" beside a flame glyph. Announced as it stands, that
 * is a number and an icon name with nothing saying what either counts, and
 * nothing saying where the link goes.
 */
const streakLabel = computed(() => {
    const days = streakDays.value

    return `Série de ${days} ${days === 1 ? 'jour' : 'jours'}, voir les statistiques`
})
</script>

<template>
    <header id="dashboard-header" class="animate-fade-in carte-plate flex items-center justify-between rounded-2xl p-4">
        <div class="flex min-w-0 items-center gap-4">
            <div
                class="bg-surface-sunken text-text-main flex size-14 shrink-0 items-center justify-center rounded-full text-2xl font-semibold"
                aria-hidden="true"
            >
                {{ user.name?.charAt(0).toUpperCase() }}
            </div>
            <div class="min-w-0">
                <p class="sur-titre text-text-muted mb-0.5" dusk="dashboard-welcome">Bon retour</p>
                <!--
                    Le prénom n'est pas le titre de la page : c'est une salutation.
                    Le titre est « Accueil », et la barre collante le porte — deux
                    `<h1>` dans une même page laissent un lecteur d'écran sans
                    savoir lequel décrit ce qu'il vient d'ouvrir.
                -->
                <p class="font-display text-text-main truncate text-3xl font-semibold tracking-tight">
                    {{ user.name?.split(' ')[0] }}
                </p>
                <Link
                    v-if="formattedWeight"
                    :href="route('body-measurements.index')"
                    dusk="dashboard-latest-weight"
                    class="text-text-muted hover:text-text-main focus-visible:ring-accent-primary relative mt-1 inline-flex items-center gap-1 rounded-lg text-xs font-medium before:absolute before:-inset-x-2 before:-inset-y-3.5 before:content-[''] focus-visible:ring-2 focus-visible:outline-none"
                >
                    <GlassIcon name="monitor_weight" size="xs" />
                    {{ formattedWeight }} kg
                </Link>
                <p
                    v-else-if="poidsIndisponible"
                    dusk="dashboard-latest-weight-indisponible"
                    class="text-text-muted mt-1 inline-flex items-center gap-1 text-xs font-medium"
                >
                    <GlassIcon name="cloud_off" size="xs" />
                    Dernier poids indisponible hors ligne
                </p>
            </div>
        </div>

        <!--
          Streak Badge. It carried cursor-pointer, a lift on hover and
          active:scale-95 while having no click handler at all — every
          affordance of a button, and nothing behind it. It now goes to the
          stats page, where the streak is what the figures are about.
        -->
        <Link
            :href="route('stats.index')"
            dusk="dashboard-streak"
            :aria-label="streakLabel"
            class="focus-visible:ring-accent-primary min-h-touch carte-plate flex shrink-0 items-center gap-1.5 rounded-xl px-3 py-2 focus-visible:ring-2 focus-visible:outline-none"
        >
            <GlassIcon name="local_fire_department" fill class="text-accent-primary-deep" />
            <span class="text-text-main text-xl font-semibold tabular-nums" aria-hidden="true">
                {{ streakDays }}
                <span class="text-text-muted text-2xs ml-0.5 font-medium">
                    {{ streakDays === 1 ? 'jour' : 'jours' }}
                </span>
            </span>
        </Link>
    </header>
</template>
