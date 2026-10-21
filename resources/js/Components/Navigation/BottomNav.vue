<script setup>
import { Link, usePage } from '@inertiajs/vue3'
import GlassIcon from '@/Components/UI/GlassIcon.vue'
import { computed } from 'vue'
import { triggerHaptic } from '@/composables/useHaptics'
import { useDemarrageDUneSeance } from '@/composables/useDemarrageDUneSeance'

const navItems = [
    { name: 'Accueil', icon: 'grid_view', route: 'dashboard' },
    { name: 'Stats', icon: 'bar_chart_4_bars', route: 'stats.index' },
    { isFab: true },
    { name: 'Séances', icon: 'calendar_month', route: 'workouts.index' },
    { name: 'Plus', icon: 'menu', route: 'profile.index' },
]

/*
 * La séance se reconnaît à la page qu'Inertia montre, pas à la route : une
 * séance démarrée sans réseau s'ouvre à l'adresse de sa clé
 * (`/workouts/en-attente/{clé}`, #2049), que `route().current('workouts.show')`
 * ne nomme pas, et le bouton y aurait démarré une seconde séance.
 */
const page = usePage()
const isWorkoutShow = computed(() => page.component === 'Workouts/Show')

const fabConfig = computed(() => {
    if (isWorkoutShow.value) {
        return { name: 'Exercice', icon: 'add' }
    }
    return { name: 'Séance', icon: 'play_arrow' }
})

/**
 * Un verrou pendant le démarrage, comme les autres déclencheurs : le même,
 * celui de l'onglet (`useDemarrageDUneSeance`).
 *
 * Ce bouton postait `workouts.store` sans rien bloquer : deux appuis rapprochés
 * créaient deux séances, et le second écran s'ouvrait sur la mauvaise. Il est
 * au centre de la barre, sous le pouce : c'est le plus facile à double-cliquer.
 *
 * Il reste ouvert sur une copie hors ligne (#2049) : démarrer n'envoie rien
 * de la copie, et sans réseau la séance s'ouvre depuis la file. Sur la séance,
 * il ajoute un exercice, qui passe aussi par la file.
 */
const { demarrer, enCours: creationEnCours } = useDemarrageDUneSeance()

const handleFabClick = () => {
    triggerHaptic('toggle')

    if (isWorkoutShow.value) {
        window.dispatchEvent(new CustomEvent('open-add-exercise'))

        return
    }

    demarrer()
}

const isActiveRoute = (itemRoute) => {
    const currentRoute = route().current()
    if (!currentRoute) return false

    const routePrefix = itemRoute.split('.')[0]
    return currentRoute === itemRoute || currentRoute.startsWith(routePrefix + '.')
}
</script>

<template>
    <nav class="glass-nav" aria-label="Navigation principale">
        <template v-for="item in navItems" :key="item.name">
            <!-- Center FAB -->
            <div v-if="item.isFab" class="relative">
                <button
                    v-press
                    @click="handleFabClick"
                    class="glass-nav-fab"
                    :aria-label="fabConfig.name"
                    :disabled="creationEnCours"
                    :aria-busy="creationEnCours"
                >
                    <GlassIcon :name="fabConfig.icon" size="xl" class="font-black" />
                </button>
            </div>

            <!-- Regular nav item -->
            <Link
                v-else
                v-press
                :href="route(item.route)"
                :class="[
                    'glass-nav-item group focus-visible:ring-accent-primary rounded-xl transition focus-visible:ring-2 focus-visible:outline-none',
                    { active: isActiveRoute(item.route) },
                ]"
                :aria-current="isActiveRoute(item.route) ? 'page' : undefined"
                :dusk="'nav-' + item.route.split('.')[0]"
            >
                <GlassIcon
                    :name="item.icon"
                    size="lg"
                    :fill="isActiveRoute(item.route)"
                    class="group-hover:drop-shadow-glow-primary transition"
                />
                <span class="text-2xs font-bold">{{ item.name }}</span>
            </Link>
        </template>
    </nav>
</template>
