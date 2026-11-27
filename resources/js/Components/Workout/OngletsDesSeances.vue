<script setup>
import { Link } from '@inertiajs/vue3'
import Cloche from '@/Components/Navigation/Cloche.vue'

/**
 * La rangée du haut des trois pages de séances sur téléphone : Historique,
 * Modèles et Calendrier, plus la cloche. Les trois pages la montrent à la même
 * place, pour qu'on passe de l'une à l'autre sans qu'elle bouge. L'onglet actif
 * n'est pas un lien (on y est déjà), les deux autres en sont un chacun.
 *
 * Elle ne se montre pas à partir de 1 024 px, où l'en-tête de la page porte ses
 * propres boutons. Le créneau par défaut reçoit la commande de la page, posée
 * sous la rangée (« Démarrer une séance », « Nouveau modèle »).
 *
 * Elle se pose hors de tout bloc `animate-slide-up` : une animation d'entrée la
 * ferait bouger entre deux pages.
 */
defineProps({
    actif: {
        type: String,
        required: true,
        validator: (valeur) => ['historique', 'modeles', 'calendrier'].includes(valeur),
    },
})

const ONGLETS = [
    { cle: 'historique', nom: 'Historique', route: 'workouts.index' },
    { cle: 'modeles', nom: 'Modèles', route: 'templates.index' },
    { cle: 'calendrier', nom: 'Calendrier', route: 'calendar.index' },
]

const CLASSES_DE_L_ONGLET = 'min-h-touch flex flex-1 items-center justify-center rounded-lg px-1'
</script>

<template>
    <div class="bureau:hidden space-y-3" dusk="onglets-des-seances">
        <div class="flex items-center gap-2">
            <nav
                aria-label="Séances"
                class="border-glass-border bg-surface-card/50 flex min-w-0 flex-1 rounded-xl border p-1 text-xs font-bold"
            >
                <template v-for="onglet in ONGLETS" :key="onglet.cle">
                    <span
                        v-if="onglet.cle === actif"
                        aria-current="page"
                        :class="[CLASSES_DE_L_ONGLET, 'bg-text-main text-text-on-dark-accent']"
                    >
                        {{ onglet.nom }}
                    </span>
                    <Link
                        v-else
                        :href="route(onglet.route)"
                        :class="[
                            CLASSES_DE_L_ONGLET,
                            'text-text-muted focus-visible:ring-accent-primary focus-visible:ring-2 focus-visible:outline-none',
                        ]"
                    >
                        {{ onglet.nom }}
                    </Link>
                </template>
            </nav>
            <Cloche class="shrink-0" />
        </div>
        <slot />
    </div>
</template>
