<script setup>
import { computed } from 'vue'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import Cloche from '@/Components/Navigation/Cloche.vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
    user: { type: Object, required: true },
})

const streakDays = computed(() => props.user.current_streak || 0)

/**
 * The badge reads "4 jours" beside a flame glyph. Announced as it stands, that
 * is a number and an icon name with nothing saying what either counts, and
 * nothing saying where the link goes.
 */
const streakLabel = computed(() => {
    const days = streakDays.value

    return `Série de ${days} ${days === 1 ? 'jour' : 'jours'}, voir les statistiques`
})
</script>

<template>
    <header
        id="dashboard-header"
        class="animate-fade-in verre-regulier rounded-carte flex items-center justify-between gap-3 p-4"
    >
        <div class="flex min-w-0 items-center gap-3">
            <div
                class="bg-surface-glass-strong font-display text-text-main flex size-11 shrink-0 items-center justify-center rounded-full text-lg font-semibold"
                aria-hidden="true"
            >
                {{ user.name?.charAt(0).toUpperCase() }}
            </div>
            <div class="min-w-0">
                <p class="etiquette" dusk="dashboard-welcome">Bon retour</p>
                <!--
                    Le prénom n'est pas un `<h1>` : la salutation tient lieu de titre à
                    l'œil, et le layout garde « Accueil » en `<h1>` masqué (`titre-masque`).
                    Deux `<h1>` laisseraient un lecteur d'écran sans savoir lequel décrit
                    ce qu'il vient d'ouvrir.
                -->
                <p class="font-display text-text-main truncate text-xl leading-tight font-semibold">
                    {{ user.name?.split(' ')[0] }}
                </p>
            </div>
        </div>

        <div class="flex shrink-0 items-center gap-2">
            <!--
              Le badge de série mène à la page des statistiques, où la série est le
              sujet des chiffres. L'orange y est réservé à l'icône de série.
            -->
            <Link
                :href="route('stats.index')"
                dusk="dashboard-streak"
                :aria-label="streakLabel"
                class="verre-epais rounded-capsule focus-visible:ring-accent-primary min-h-touch flex shrink-0 items-center gap-1.5 px-3 py-2 focus-visible:ring-2 focus-visible:outline-none"
            >
                <GlassIcon name="local_fire_department" fill class="text-accent-primary-deep" />
                <span class="text-text-main text-sm font-medium tabular-nums" aria-hidden="true">
                    {{ streakDays }} {{ streakDays === 1 ? 'jour' : 'jours' }}
                </span>
            </Link>
            <Cloche class="bureau:hidden" />
        </div>
    </header>
</template>
