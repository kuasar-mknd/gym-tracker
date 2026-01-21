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
        ? 'block w-full rounded-2xl ps-3 pe-4 py-3 text-start text-base font-bold uppercase tracking-wide transition-all duration-300 bg-white/20 text-electric-orange backdrop-blur-md border border-white/40 shadow-sm'
        : 'block w-full rounded-2xl ps-3 pe-4 py-3 text-start text-base font-bold uppercase tracking-wide transition-all duration-300 text-text-muted border border-transparent hover:bg-white/10 hover:backdrop-blur-md hover:border-white/20 hover:text-text-main hover:shadow-sm',
)
</script>

<template>
    <Link :href="href" :class="classes">
        <slot />
    </Link>
</template>
