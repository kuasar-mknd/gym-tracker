<script setup>
import { Link, usePage, router } from '@inertiajs/vue3'
import { computed } from 'vue'

const page = usePage()

const navItems = [
    { name: 'Accueil', icon: 'grid_view', route: 'dashboard' },
    { name: 'Stats', icon: 'bar_chart_4_bars', route: 'stats.index' },
    { name: 'Add', icon: 'add', isFab: true, action: 'createWorkout' },
    { name: 'Séances', icon: 'calendar_month', route: 'workouts.index' },
    { name: 'Profil', icon: 'settings', route: 'profile.edit' },
]

const createWorkout = () => {
    router.post(route('workouts.store'))
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
            <div v-if="item.isFab" class="relative -top-8">
                <button @click="createWorkout" class="glass-nav-fab" :aria-label="item.name">
                    <span class="material-symbols-outlined text-4xl font-bold">{{ item.icon }}</span>
                </button>
            </div>

            <!-- Regular nav item -->
            <Link
                v-else
                :href="route(item.route)"
                :class="['glass-nav-item group', { active: isActiveRoute(item.route) }]"
                :aria-label="item.name"
                :aria-current="isActiveRoute(item.route) ? 'page' : undefined"
            >
                <span
                    class="material-symbols-outlined text-[28px] transition-all group-hover:drop-shadow-[0_0_8px_rgba(255,85,0,0.5)]"
                    :style="{ fontVariationSettings: isActiveRoute(item.route) ? `'FILL' 1` : `'FILL' 0` }"
                >
                    {{ item.icon }}
                </span>
            </Link>
        </template>
    </nav>
</template>
