<script setup>
import { computed } from 'vue'
import { Link } from '@inertiajs/vue3'

const props = defineProps({
    href: {
        type: String,
        required: true,
    },
    active: {
        type: Boolean,
    },
})

// Liquid Glass aesthetics for NavLink
const classes = computed(() =>
    props.active
        ? 'inline-flex items-center justify-center rounded-xl border border-electric-orange/20 bg-electric-orange/10 px-4 py-2 text-sm font-bold uppercase tracking-wide text-electric-orange shadow-[0_0_15px_rgba(255,85,0,0.15)] backdrop-blur-md transition-all duration-300'
        : 'inline-flex items-center justify-center rounded-xl border border-transparent px-4 py-2 text-sm font-bold uppercase tracking-wide text-text-muted transition-all duration-300 hover:bg-white/60 hover:text-text-main hover:shadow-sm hover:backdrop-blur-sm',
)
</script>

<template>
    <Link :href="href" :class="classes">
        <slot />
    </Link>
</template>
