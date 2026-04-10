<script setup>
/**
 * GlassSection.vue
 *
 * A reusable section container with title and description.
 * Replaces the repeated <section><header><h2>...</h2><p>...</p></header> pattern
 * found in Profile partials and other pages.
 *
 * Usage:
 *   <GlassSection title="Profil" description="Mettez à jour vos informations.">
 *       <form>...</form>
 *   </GlassSection>
 */
defineProps({
    title: {
        type: String,
        required: true,
    },
    description: {
        type: String,
        default: '',
    },
    as: {
        type: String,
        default: 'section',
    },
})
</script>

<template>
    <component :is="as">
        <header class="mb-6">
            <h2 class="text-text-main text-lg font-medium dark:text-white">
                {{ title }}
            </h2>
            <p v-if="description" class="text-text-muted mt-1 text-sm dark:text-slate-400">
                {{ description }}
            </p>
        </header>

        <slot />
    </component>
</template>
