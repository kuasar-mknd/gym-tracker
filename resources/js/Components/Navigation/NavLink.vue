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

/*
 * Une capsule, comme l'onglet de la barre du bas : l'onglet actif devient une
 * bulle de verre épais, les autres restent nus.
 */
const classes = computed(() =>
    props.active
        ? 'verre-epais rounded-capsule text-accent-primary-deep inline-flex items-center justify-center px-4 py-2 text-sm font-medium transition duration-300 focus-visible:ring-2 focus-visible:ring-accent-primary focus-visible:outline-none'
        : 'rounded-capsule text-text-muted hover:text-text-main hover:bg-surface-glass inline-flex items-center justify-center border border-transparent px-4 py-2 text-sm font-medium transition duration-300 focus-visible:ring-2 focus-visible:ring-accent-primary focus-visible:outline-none',
)
</script>

<template>
    <Link v-press :href="href" :class="classes" :aria-current="active ? 'page' : undefined">
        <slot />
    </Link>
</template>
