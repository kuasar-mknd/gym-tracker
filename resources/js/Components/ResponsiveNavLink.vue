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
        ? 'block w-full rounded-2xl border border-electric-orange/30 bg-electric-orange/10 px-4 py-3 text-start text-base font-black uppercase tracking-wide text-electric-orange shadow-glow-orange backdrop-blur-md transition-all duration-300'
        : 'block w-full rounded-2xl border border-transparent px-4 py-3 text-start text-base font-bold text-text-muted transition-all duration-300 hover:scale-[1.02] hover:border-white/20 hover:bg-white/10 hover:text-text-main hover:shadow-lg focus:outline-none',
)
</script>

<template>
    <Link :href="href" :class="classes">
        <slot />
    </Link>
</template>
