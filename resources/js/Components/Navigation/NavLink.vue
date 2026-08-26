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

const classes = computed(() =>
    props.active
        ? 'inline-flex items-center justify-center rounded-2xl px-4 py-2 text-sm font-bold tracking-wide uppercase transition-all duration-300 bg-surface-card/20 text-accent-primary-deep backdrop-blur-md border border-glass-border/40 shadow-sm focus-visible:ring-2 focus-visible:ring-accent-primary focus-visible:outline-none'
        : 'inline-flex items-center justify-center rounded-2xl px-4 py-2 text-sm font-bold tracking-wide uppercase transition-all duration-300 text-text-muted border border-transparent hover:bg-surface-card/10 hover:backdrop-blur-md hover:border-glass-border/20 hover:text-text-main hover:shadow-sm focus-visible:ring-2 focus-visible:ring-accent-primary focus-visible:outline-none',
)
</script>

<template>
    <Link v-press :href="href" :class="classes">
        <slot />
    </Link>
</template>
