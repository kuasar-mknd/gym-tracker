<script setup>
/**
 * GlassSection.vue
 *
 * A reusable section container with title and description.
 * Replaces the repeated <section><header><h2>...</h2><p>...</p></header> pattern
 * found in Profile partials and other pages.
 *
 * Usage:
 *   <GlassSection title="Profil" description="Mets à jour tes informations.">
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
    <component :is="as" class="verre-regulier rounded-carte bg-surface-card/90 p-6 backdrop-blur-none">
        <header class="mb-6">
            <h2 class="titre-plat">
                {{ title }}
            </h2>
            <p v-if="description" class="text-text-muted mt-1 text-sm">
                {{ description }}
            </p>
        </header>

        <slot />
    </component>
</template>
