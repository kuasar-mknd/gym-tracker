<script setup>
import { Link } from '@inertiajs/vue3'

const props = defineProps({
    items: {
        type: Array,
        default: () => [
            { name: 'Accueil', route: 'dashboard', icon: 'home' },
            { name: 'Séances', route: 'workouts.index', icon: 'dumbbell' },
            { name: 'Objectifs', route: 'goals.index', icon: 'target' },
            { name: 'Trophées', route: 'achievements.index', icon: 'trophy' },
            { name: 'Corps', route: 'body-measurements.index', icon: 'scale' },
            { name: 'Profil', route: 'profile.edit', icon: 'user' },
        ],
    },
})

const icons = {
    home: `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />`,
    dumbbell: `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7v10M8 4v16M12 7v10M16 4v16M20 7v10" />`,
    target: `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 22a10 10 0 100-20 10 10 0 000 20zm0-2a8 8 0 100-16 8 8 0 000 16zm0-2a6 6 0 100-12 6 6 0 000 12zm0-4a2 2 0 110-4 2 2 0 010 4z" />`,
    trophy: `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 3v4M3 5h4M6 17v4m-2-2h4m5-16l2.286 6.857L21 12l-5.714 2.143L13 21l-2.286-6.857L5 12l5.714-2.143L13 3z" />`,
    chart: `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />`,
    book: `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253" />`,
    scale: `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 6l3 1m0 0l-3 9a5.002 5.002 0 006.001 0l-3-9m3 1c1 0 1 1 1 1m0 0l3 9a5.002 5.002 0 006.001 0l-3-9m-3 1c-1 0-1-1-1-1m-4-1V5a2 2 0 012-2h2a2 2 0 012 2v3m-6 0h6" />`,
    user: `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />`,
}
</script>

<template>
    <nav class="glass-nav">
        <div class="flex items-center justify-around px-2">
            <Link
                v-for="item in items"
                :key="item.route"
                :href="route(item.route)"
                class="glass-nav-item relative flex-1 py-2"
                :class="{ active: route().current(item.route.split('.')[0] + '.*') || route().current(item.route) }"
            >
                <svg
                    class="h-6 w-6"
                    xmlns="http://www.w3.org/2000/svg"
                    fill="none"
                    viewBox="0 0 24 24"
                    stroke="currentColor"
                    v-html="icons[item.icon]"
                />
                <span class="mt-1 text-xs">{{ item.name }}</span>
            </Link>
        </div>
    </nav>
</template>
