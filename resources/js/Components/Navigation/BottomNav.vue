<script setup>
import { Link, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { triggerHaptic } from '@/composables/useHaptics'

const navItems = [
    { name: 'Accueil', icon: 'grid_view', route: 'dashboard' },
    { name: 'Stats', icon: 'bar_chart_4_bars', route: 'stats.index' },
    { isFab: true },
    { name: 'Séances', icon: 'calendar_month', route: 'workouts.index' },
    { name: 'Plus', icon: 'menu', route: 'profile.index' },
]

const isWorkoutShow = computed(() => route().current('workouts.show'))

const fabConfig = computed(() => {
    if (isWorkoutShow.value) {
        return { name: 'Exercice', icon: 'add' }
    }
    return { name: 'Séance', icon: 'play_arrow' }
})

/**
 * Un verrou pendant la création, comme les trois autres déclencheurs.
 *
 * Ce bouton postait `workouts.store` sans rien bloquer : deux appuis rapprochés
 * créaient deux séances, et le second écran s'ouvrait sur la mauvaise. Les trois
 * autres chemins vers la même action se gardaient déjà — `QuickActions` par un
 * `:disabled`, `Workouts/Index` par le `:loading` de GlassButton, et
 * `Templates/Index` par un identifiant en cours dont le commentaire décrit
 * exactement ce symptôme.
 *
 * Celui-ci était le seul sans garde, et c'est le plus facile à double-cliquer :
 * il est au centre de la barre, sous le pouce.
 */
const creationEnCours = ref(false)

const handleFabClick = () => {
    triggerHaptic('toggle')

    if (isWorkoutShow.value) {
        window.dispatchEvent(new CustomEvent('open-add-exercise'))

        return
    }

    if (creationEnCours.value) {
        return
    }

    creationEnCours.value = true

    router.post(
        route('workouts.store'),
        {},
        {
            // `onFinish` et non `onSuccess` : un échec doit rendre le bouton
            // aussi, sinon un refus du serveur le condamne jusqu'au rechargement.
            onFinish: () => {
                creationEnCours.value = false
            },
        },
    )
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
                    <span class="material-symbols-outlined text-4xl font-black" aria-hidden="true">{{
                        fabConfig.icon
                    }}</span>
                </button>
            </div>

            <!-- Regular nav item -->
            <Link
                v-else
                v-press
                :href="route(item.route)"
                :class="[
                    'glass-nav-item group focus-visible:ring-accent-primary rounded-xl transition-all focus-visible:ring-2 focus-visible:outline-none',
                    { active: isActiveRoute(item.route) },
                ]"
                :aria-label="item.name"
                :aria-current="isActiveRoute(item.route) ? 'page' : undefined"
                :dusk="'nav-' + item.route.split('.')[0]"
            >
                <span
                    class="material-symbols-outlined text-[28px] transition-all group-hover:drop-shadow-[0_0_8px_rgb(from_var(--color-accent-primary)_r_g_b_/_0.5)]"
                    :style="{ fontVariationSettings: isActiveRoute(item.route) ? `'FILL' 1` : `'FILL' 0` }"
                    aria-hidden="true"
                >
                    {{ item.icon }}
                </span>
            </Link>
        </template>
    </nav>
</template>
