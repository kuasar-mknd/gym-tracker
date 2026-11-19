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
        ? 'inline-flex items-center justify-center rounded-xl px-4 py-2 text-sm font-medium transition-colors bg-surface-sunken text-text-main focus-visible:ring-2 focus-visible:ring-accent-primary focus-visible:outline-none'
        : 'inline-flex items-center justify-center rounded-xl px-4 py-2 text-sm font-medium transition-colors text-text-muted hover:bg-surface-sunken hover:text-text-main focus-visible:ring-2 focus-visible:ring-accent-primary focus-visible:outline-none',
)
</script>

<template>
    <Link v-press :href="href" :class="classes" :aria-current="active ? 'page' : undefined">
        <slot />
    </Link>
</template>
